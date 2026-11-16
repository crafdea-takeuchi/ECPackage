<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PurchaseReceiptExpirationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function input(array $expiration = []): array
    {
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create();
        $setting = ProductSupplier::factory()->create(['supplier_id' => $supplier->id, 'product_id' => $product->id]);

        return [
            'supplier_id' => $supplier->id, 'received_on' => '2026-11-16', 'action' => 'draft', 'lock_version' => 0,
            'lines' => [[
                'product_id' => $product->id, 'product_supplier_id' => $setting->id, 'lot_number' => 'TEST',
                'quantity' => '2', 'unit_purchase_price' => '100', 'manufactured_on' => '2026-11-01',
                'expiration_type' => null, 'expires_on' => null, ...$expiration,
            ]],
        ];
    }

    #[TestWith([null, null])]
    #[TestWith(['best_before', '2027-05-31'])]
    #[TestWith(['use_by', '2026-11-20'])]
    public function test_saves_and_posts_each_expiration_type(?string $type, ?string $date): void
    {
        $input = $this->input(['expiration_type' => $type, 'expires_on' => $date]);
        $input['action'] = 'post';
        $this->actingAs(User::factory()->admin()->create())->post('/admin/purchase-receipts', $input)->assertSessionHasNoErrors();
        $line = PurchaseReceiptLine::firstOrFail();
        $this->assertDatabaseHas('purchase_receipt_lines', ['id' => $line->id, 'expiration_type' => $type, 'expires_on' => $date]);
        $this->assertDatabaseHas('inventory_lots', ['purchase_receipt_line_id' => $line->id, 'expiration_type' => $type, 'expires_on' => $date, 'current_quantity' => 2]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->get('/admin/purchase-receipts/'.$line->purchase_receipt_id.'/edit')
            ->assertInertia(fn (Assert $page) => $page->where('receipt.lines.0.expiration_type', $type)->where('receipt.lines.0.expires_on', $date));
        $this->get('/admin/inventory-lots')->assertInertia(fn (Assert $page) => $page
            ->where('lots.data.0.expiration_type', $type)->where('lots.data.0.expires_on', $date));
    }

    #[TestWith(['best_before', null, 'lines.0.expires_on'])]
    #[TestWith(['use_by', '', 'lines.0.expires_on'])]
    #[TestWith([null, '2027-05-31', 'lines.0.expiration_type'])]
    #[TestWith(['invalid', '2027-05-31', 'lines.0.expiration_type'])]
    #[TestWith(['best_before', '2027-02-30', 'lines.0.expires_on'])]
    #[TestWith(['best_before', '2026-10-31', 'lines.0.expires_on'])]
    #[TestWith(['best_before', '2027/05/31', 'lines.0.expires_on'])]
    public function test_rejects_invalid_expiration_without_creating_any_data(?string $type, ?string $date, string $key): void
    {
        $input = $this->input(['expiration_type' => $type, 'expires_on' => $date]);
        $input['action'] = 'post';
        $this->actingAs(User::factory()->admin()->create())->post('/admin/purchase-receipts', $input)->assertSessionHasErrors($key);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('inventory_lots', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_same_manufacture_and_expiration_date_is_allowed_and_lines_are_independent(): void
    {
        $input = $this->input(['expiration_type' => 'best_before', 'expires_on' => '2026-11-01']);
        $input['lines'][] = [...$input['lines'][0], 'lot_number' => 'SECOND', 'expiration_type' => 'use_by', 'expires_on' => '2026-12-01'];
        $input['lines'][] = [...$input['lines'][0], 'lot_number' => 'THIRD', 'expiration_type' => null, 'expires_on' => null];
        $input['action'] = 'post';
        $this->actingAs(User::factory()->admin()->create())->post('/admin/purchase-receipts', $input)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_lots', ['lot_number' => 'TEST', 'expiration_type' => 'best_before', 'expires_on' => '2026-11-01']);
        $this->assertDatabaseHas('inventory_lots', ['lot_number' => 'SECOND', 'expiration_type' => 'use_by', 'expires_on' => '2026-12-01']);
        $this->assertDatabaseHas('inventory_lots', ['lot_number' => 'THIRD', 'expiration_type' => null, 'expires_on' => null]);
    }

    #[TestWith(['best_before', '2027-05-31'])]
    #[TestWith(['use_by', '2026-12-01'])]
    #[TestWith([null, '2026-12-01'])]
    public function test_editing_preserves_existing_expiration_and_allows_explicit_clearing(?string $type, string $date): void
    {
        $input = $this->input();
        $admin = User::factory()->admin()->create();
        $receipt = PurchaseReceipt::factory()->create(['supplier_id' => $input['supplier_id'], 'created_by' => $admin->id]);
        $line = PurchaseReceiptLine::factory()->create([...$input['lines'][0], 'purchase_receipt_id' => $receipt->id, 'expiration_type' => $type, 'expires_on' => $date]);
        $input['lines'][0] = [...$input['lines'][0], 'id' => $line->id, 'expiration_type' => $type, 'expires_on' => $date];
        $this->actingAs($admin)->get('/admin/purchase-receipts/'.$receipt->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page->where('receipt.lines.0.expiration_type', $type)->where('receipt.lines.0.expires_on', $date));
        $this->put('/admin/purchase-receipts/'.$receipt->id, $input)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchase_receipt_lines', ['id' => $line->id, 'expiration_type' => $type, 'expires_on' => $date]);
        $input['lock_version'] = $receipt->fresh()->lock_version;
        $input['lines'][0]['expiration_type'] = null;
        $input['lines'][0]['expires_on'] = null;
        $this->put('/admin/purchase-receipts/'.$receipt->id, $input)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchase_receipt_lines', ['id' => $line->id, 'expiration_type' => null, 'expires_on' => null]);
    }

    #[TestWith(['expiration_type', ['best_before']])]
    #[TestWith(['expires_on', ['2027-05-31']])]
    public function test_rejects_non_scalar_expiration_input(string $field, array $value): void
    {
        $input = $this->input(['expiration_type' => 'best_before', 'expires_on' => '2027-05-31', $field => $value]);
        $this->actingAs(User::factory()->admin()->create())->post('/admin/purchase-receipts', $input)
            ->assertSessionHasErrors('lines.0.'.$field);
        $this->assertDatabaseCount('purchase_receipts', 0);
    }

    public function test_legacy_exception_cannot_be_used_to_submit_a_new_untyped_date(): void
    {
        $input = $this->input();
        $receipt = PurchaseReceipt::factory()->create(['supplier_id' => $input['supplier_id']]);
        $line = PurchaseReceiptLine::factory()->create([...$input['lines'][0], 'purchase_receipt_id' => $receipt->id, 'expires_on' => '2026-12-01']);
        $input['lines'][0] = [...$input['lines'][0], 'id' => $line->id, 'expires_on' => '2026-12-02'];
        $this->actingAs(User::factory()->admin()->create())->put('/admin/purchase-receipts/'.$receipt->id, $input)
            ->assertSessionHasErrors('lines.0.expiration_type');
        $this->assertDatabaseHas('purchase_receipt_lines', ['id' => $line->id, 'expires_on' => '2026-12-01']);
    }
}
