<?php

namespace Tests\Feature;

use App\Actions\Inventory\DisposeExpiredInventory;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOperation;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class ExpiredInventoryDisposalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'Asia/Tokyo'));
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    private function lot(array $attributes = [], ?Unit $unit = null): InventoryLot
    {
        $product = Product::factory()->create(['unit_id' => $unit?->id ?? Unit::factory()->create(['name' => '個', 'quantity_scale' => 0])->id]);

        return InventoryLot::factory()->create([
            'product_id' => $product->id, 'expiration_type' => 'use_by',
            'expires_on' => '2026-11-13', 'status' => 'active', 'current_quantity' => '10',
            ...$attributes,
        ]);
    }

    private function input(array $lots, array $overrides = []): array
    {
        return [
            'lots' => array_map(fn (InventoryLot $lot): array => ['inventory_lot_id' => $lot->id, 'product_id' => $lot->product_id], $lots),
            'occurred_on' => '2026-11-16', 'reason' => DisposeExpiredInventory::REASON, 'notes' => DisposeExpiredInventory::DEFAULT_NOTES,
            ...$overrides,
        ];
    }

    #[TestWith(['2026-11-13', 'active', '10', true])]
    #[TestWith(['2026-11-16', 'active', '10', true])]
    #[TestWith(['2026-11-17', 'active', '10', true])]
    #[TestWith(['2027-05-31', 'active', '10', true])]
    #[TestWith([null, 'active', '10', false])]
    #[TestWith(['2026-11-13', 'active', '0', false])]
    #[TestWith(['2026-11-13', 'inactive', '10', false])]
    public function test_only_active_dated_lots_with_stock_are_selectable(?string $date, string $status, string $quantity, bool $expected): void
    {
        $this->admin();
        $lot = $this->lot(['expires_on' => $date, 'status' => $status, 'current_quantity' => $quantity]);
        $this->get('/admin/inventory-lots?product_id='.$lot->product_id)
            ->assertInertia(fn (Assert $page) => $page->where('lots.data.0.can_dispose', $expected)->where('disposal.allowed', true));
    }

    #[TestWith(['best_before'])]
    #[TestWith(['use_by'])]
    public function test_disposal_uses_adjustment_history_without_deleting_lot_or_receipt(string $type): void
    {
        $actor = $this->admin();
        $lot = $this->lot(['expiration_type' => $type]);
        $receiptLineId = $lot->purchase_receipt_line_id;
        $response = $this->post('/admin/inventory-lots/dispose', $this->input([$lot], ['occurred_on' => '2026-11-15']));
        $response->assertRedirect('/admin/inventory-lots')->assertSessionHasNoErrors()
            ->assertSessionHas('status', '1ロット、合計10個を廃棄しました。');
        $operation = StockOperation::sole();
        $this->assertSame('adjustment', $operation->operation_type);
        $this->assertSame($actor->id, $operation->created_by);
        $this->assertSame('2026-11-15', $operation->occurred_on->format('Y-m-d'));
        $this->assertSame(DisposeExpiredInventory::DEFAULT_NOTES, $operation->notes);
        $this->assertDatabaseHas('stock_operation_lines', [
            'stock_operation_id' => $operation->id, 'inventory_lot_id' => $lot->id, 'product_id' => $lot->product_id,
            'quantity_before' => 10, 'quantity_delta' => -10, 'quantity_after' => 0, 'reason' => '期限切れ廃棄',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_lot_id' => $lot->id, 'movement_type' => 'adjustment', 'quantity_delta' => -10,
            'created_by' => $actor->id, 'occurred_on' => '2026-11-15', 'notes' => '期限切れ廃棄',
        ]);
        $this->assertDatabaseHas('inventory_lots', ['id' => $lot->id, 'current_quantity' => 0, 'status' => 'depleted', 'expiration_type' => $type, 'expires_on' => '2026-11-13']);
        $this->assertDatabaseHas('purchase_receipt_lines', ['id' => $receiptLineId]);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('inventory_lots', 1);
    }

    public function test_multiple_lots_use_latest_stock_and_keep_filters(): void
    {
        $this->admin();
        $unit = Unit::factory()->create(['name' => '個', 'quantity_scale' => 0]);
        $first = $this->lot([], $unit);
        $second = $this->lot(['current_quantity' => '5', 'expiration_type' => 'best_before'], $unit);
        $input = $this->input([$first, $second]);
        $input['lots'][0]['quantity'] = '9999';
        $first->update(['current_quantity' => 8]);
        $filters = ['expiration_status' => 'expired', 'expiration_type' => 'use_by', 'q' => 'LOT', 'page' => 2];
        $query = http_build_query($filters);
        $this->post('/admin/inventory-lots/dispose?'.$query, $input)->assertRedirect('/admin/inventory-lots?'.$query)
            ->assertSessionHasNoErrors()->assertSessionHas('status', '2ロット、合計13個を廃棄しました。');
        $this->assertDatabaseCount('stock_operations', 1);
        $this->assertDatabaseCount('stock_operation_lines', 2);
        $this->assertDatabaseHas('stock_operation_lines', ['inventory_lot_id' => $first->id, 'quantity_delta' => -8, 'reason' => '期限切れ廃棄']);
        $this->assertSame('0.0000', $first->fresh()->current_quantity);
        $this->assertSame('0.0000', $second->fresh()->current_quantity);
    }

    public function test_different_units_are_reported_separately(): void
    {
        $this->admin();
        $pieces = $this->lot();
        $kg = Unit::factory()->create(['name' => 'kg', 'quantity_scale' => 3]);
        $weight = $this->lot(['current_quantity' => '1.125'], $kg);
        $this->post('/admin/inventory-lots/dispose', $this->input([$pieces, $weight]))
            ->assertSessionHasNoErrors()->assertSessionHas('status', '2ロット、合計10個・1.125kgを廃棄しました。');
    }

    #[TestWith(['expires_on', null])]
    #[TestWith(['current_quantity', 0])]
    #[TestWith(['status', 'inactive'])]
    public function test_server_rechecks_each_lot_and_rejects_entire_batch(string $field, mixed $value): void
    {
        $this->admin();
        $valid = $this->lot();
        $changed = $this->lot();
        $input = $this->input([$valid, $changed]);
        $changed->update([$field => $value]);
        $this->post('/admin/inventory-lots/dispose', $input)->assertSessionHasErrors('lots');
        $this->assertSame('10.0000', $valid->fresh()->current_quantity);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('stock_operation_lines', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_missing_and_mismatched_lots_are_rejected(): void
    {
        $this->admin();
        $lot = $this->lot();
        $input = $this->input([$lot]);
        $input['lots'][0]['product_id'] = $lot->product_id + 100;
        $this->post('/admin/inventory-lots/dispose', $input)->assertSessionHasErrors('lots');
        $input['lots'][0]['inventory_lot_id'] = $lot->id + 100;
        $this->post('/admin/inventory-lots/dispose', $input)->assertSessionHasErrors('lots');
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertSame('10.0000', $lot->fresh()->current_quantity);
    }

    public function test_duplicate_and_oversized_selection_and_invalid_date_are_rejected(): void
    {
        $this->admin();
        $lot = $this->lot();
        $this->post('/admin/inventory-lots/dispose', $this->input([$lot, $lot]))
            ->assertSessionHasErrors('lots.0.inventory_lot_id');
        $this->post('/admin/inventory-lots/dispose', $this->input(array_fill(0, 21, $lot)))->assertSessionHasErrors('lots');
        $this->post('/admin/inventory-lots/dispose', $this->input([$lot], ['occurred_on' => '2026-02-30']))
            ->assertSessionHasErrors('occurred_on');
        $this->post('/admin/inventory-lots/dispose', $this->input([]))->assertSessionHasErrors('lots');
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_repeated_submission_and_other_stock_changes_cannot_subtract_twice(): void
    {
        $this->admin();
        $lot = $this->lot();
        $input = $this->input([$lot]);
        $this->post('/admin/inventory-lots/dispose', $input)->assertSessionHasNoErrors();
        $this->post('/admin/inventory-lots/dispose', $input)->assertSessionHasErrors('lots');
        $this->assertDatabaseCount('stock_operations', 1);
        $this->assertDatabaseCount('stock_operation_lines', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('0.0000', $lot->fresh()->current_quantity);
    }

    public function test_failure_after_first_stock_update_rolls_back_everything(): void
    {
        $this->admin();
        $first = $this->lot();
        $second = $this->lot();
        $attempts = 0;
        StockMovement::creating(function () use (&$attempts): void {
            if (++$attempts === 2) {
                throw new RuntimeException('廃棄中の保存エラー');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/inventory-lots/dispose', $this->input([$first, $second]));
            $this->fail('保存エラーは全件ロールバックする必要があります。');
        } catch (RuntimeException $exception) {
            $this->assertSame('廃棄中の保存エラー', $exception->getMessage());
        }
        $this->assertSame(2, $attempts);
        $this->assertSame('10.0000', $first->fresh()->current_quantity);
        $this->assertSame('10.0000', $second->fresh()->current_quantity);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('stock_operation_lines', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_guest_non_admin_and_unverified_admin_cannot_dispose(): void
    {
        $lot = $this->lot();
        $input = $this->input([$lot]);
        $this->post('/admin/inventory-lots/dispose', $input)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->post('/admin/inventory-lots/dispose', $input)->assertForbidden();
        $this->get('/admin/inventory-lots')->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create())->post('/admin/inventory-lots/dispose', $input)->assertRedirect('/email/verify');
        $this->assertSame('10.0000', $lot->fresh()->current_quantity);
        $this->assertDatabaseCount('stock_operations', 0);
    }

    #[TestWith(['2026-11-16'])]
    #[TestWith(['2026-11-20'])]
    #[TestWith(['2027-05-31'])]
    public function test_unexpired_disposal_requires_acknowledgment_and_accepts_business_reason(string $date): void
    {
        $this->admin();
        $first = $this->lot();
        $unexpired = $this->lot(['expires_on' => $date]);
        $input = $this->input([$first, $unexpired], ['reason' => '品質上の判断による廃棄']);
        $this->post('/admin/inventory-lots/dispose', $input)->assertSessionHasErrors('confirm_unexpired');
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertSame('10.0000', $first->fresh()->current_quantity);
        $this->assertSame('10.0000', $unexpired->fresh()->current_quantity);
        $input['confirm_unexpired'] = true;
        $this->post('/admin/inventory-lots/dispose', $input)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stock_operation_lines', 2);
        $this->assertDatabaseHas('stock_operation_lines', ['inventory_lot_id' => $unexpired->id, 'quantity_delta' => -10, 'reason' => '品質上の判断による廃棄']);
        $this->assertDatabaseHas('stock_operations', ['notes' => DisposeExpiredInventory::DEFAULT_NOTES]);
    }

    #[TestWith([false])]
    #[TestWith([0])]
    #[TestWith(['0'])]
    #[TestWith(['false'])]
    public function test_falsy_or_invalid_acknowledgment_cannot_bypass_server_verification(mixed $confirmation): void
    {
        $this->admin();
        $lot = $this->lot(['expires_on' => '2026-12-01']);
        $this->post('/admin/inventory-lots/dispose', $this->input([$lot], ['confirm_unexpired' => $confirmation]))
            ->assertSessionHasErrors('confirm_unexpired');
        $this->assertDatabaseCount('stock_operations', 0);
    }

    #[TestWith(['期限切れ廃棄', null])]
    #[TestWith(['期限間近による廃棄', null])]
    #[TestWith(['品質上の判断による廃棄', null])]
    #[TestWith(['その他', '容器破損のため'])]
    public function test_selected_reason_is_saved_even_if_it_does_not_match_expiration_status(string $reason, ?string $notes): void
    {
        $this->admin();
        $lot = $this->lot(['expires_on' => '2027-05-31']);
        $this->post('/admin/inventory-lots/dispose', $this->input([$lot], [
            'reason' => $reason, 'notes' => $notes, 'confirm_unexpired' => true,
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_operation_lines', ['inventory_lot_id' => $lot->id, 'reason' => $reason]);
        $this->assertDatabaseHas('stock_movements', ['inventory_lot_id' => $lot->id, 'notes' => $reason]);
        $this->assertDatabaseHas('stock_operations', ['notes' => $notes ?? DisposeExpiredInventory::DEFAULT_NOTES]);
    }

    #[TestWith(['', null, 'reason'])]
    #[TestWith(['不正な理由', null, 'reason'])]
    #[TestWith(['その他', null, 'notes'])]
    #[TestWith(['その他', '   ', 'notes'])]
    public function test_reason_and_notes_are_validated_on_request_and_action(string $reason, ?string $notes, string $error): void
    {
        $actor = $this->admin();
        $lot = $this->lot();
        $input = $this->input([$lot], ['reason' => $reason, 'notes' => $notes]);
        $this->post('/admin/inventory-lots/dispose', $input)->assertSessionHasErrors($error);
        try {
            app(DisposeExpiredInventory::class)->handle($input, $actor);
            $this->fail('Actionを直接呼び出しても理由・備考の検証を回避できません。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($error, $exception->errors());
        }
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_changed_expiration_is_rechecked_at_execution(): void
    {
        $this->admin();
        $lot = $this->lot();
        $input = $this->input([$lot]);
        $lot->update(['expires_on' => '2027-05-31']);
        $this->post('/admin/inventory-lots/dispose', $input)->assertSessionHasErrors('confirm_unexpired');
        $this->assertSame('10.0000', $lot->fresh()->current_quantity);
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_expiration_is_decided_on_the_execution_date_in_japan(): void
    {
        $this->admin();
        $lot = $this->lot(['expires_on' => '2026-11-15']);
        $this->travelTo(CarbonImmutable::parse('2026-11-15 14:59:59', 'UTC'));
        $this->post('/admin/inventory-lots/dispose', $this->input([$lot]))->assertSessionHasErrors('confirm_unexpired');
        $this->travelTo(CarbonImmutable::parse('2026-11-15 15:00:00', 'UTC'));
        $this->post('/admin/inventory-lots/dispose', $this->input([$lot]))->assertSessionHasNoErrors();
        $this->assertSame('0.0000', $lot->fresh()->current_quantity);
    }
}
