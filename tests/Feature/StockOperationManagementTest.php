<?php

namespace Tests\Feature;

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\StockOperation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockOperationManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{0: InventoryLot, 1: User} */
    private function inventoryLot(string $quantity = '10', int $quantityScale = 0, array $overrides = []): array
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create();
        $unit = Unit::factory()->create(['name' => $quantityScale === 0 ? '個' : 'kg', 'quantity_scale' => $quantityScale]);
        $product = Product::factory()->create(['unit_id' => $unit->id, 'registration_status' => 'confirmed']);
        $setting = ProductSupplier::factory()->create(['supplier_id' => $supplier->id, 'product_id' => $product->id]);
        $receipt = PurchaseReceipt::factory()->create(['supplier_id' => $supplier->id, 'created_by' => $admin->id, 'status' => 'posted']);
        $line = PurchaseReceiptLine::factory()->create([
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'product_supplier_id' => $setting->id,
            'quantity' => $quantity,
            'purchase_amount' => bcmul($quantity, '100', 4),
        ]);
        $lot = InventoryLot::factory()->create(array_replace([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'purchase_receipt_line_id' => $line->id,
            'lot_number' => 'LOT-'.$line->id,
            'initial_quantity' => $quantity,
            'current_quantity' => $quantity,
            'unit_purchase_price' => '100',
        ], $overrides));

        return [$lot, $admin];
    }

    private function input(InventoryLot $lot, array $overrides = []): array
    {
        return array_replace_recursive([
            'operation_type' => 'outbound',
            'occurred_on' => '2026-10-21',
            'notes' => '受注出荷',
            'lines' => [[
                'inventory_lot_id' => $lot->id,
                'quantity' => '4',
                'reason' => '注文 ORD-001',
            ]],
        ], $overrides);
    }

    public function test_stock_operation_routes_require_verified_admin(): void
    {
        [$lot] = $this->inventoryLot();
        $operation = StockOperation::factory()->create();

        $this->get('/admin/stock-operations')->assertRedirect('/admin/login');
        $this->get('/admin/stock-operations/create')->assertRedirect('/admin/login');
        $this->post('/admin/stock-operations', $this->input($lot))->assertRedirect('/admin/login');
        $this->get('/admin/stock-operations/'.$operation->id)->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create());
        $this->get('/admin/stock-operations')->assertForbidden();
        $this->get('/admin/stock-operations/create')->assertForbidden();
        $this->post('/admin/stock-operations', $this->input($lot))->assertForbidden();
        $this->get('/admin/stock-operations/'.$operation->id)->assertForbidden();

        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->get('/admin/stock-operations')->assertRedirect('/email/verify');
        $this->post('/admin/stock-operations', $this->input($lot))->assertRedirect('/email/verify');
    }

    public function test_outbound_reduces_lot_and_records_auditable_history(): void
    {
        [$lot, $admin] = $this->inventoryLot('10');

        $response = $this->actingAs($admin)->post('/admin/stock-operations', $this->input($lot));

        $operation = StockOperation::firstOrFail();
        $line = $operation->lines()->firstOrFail();
        $response->assertRedirect('/admin/stock-operations/'.$operation->id)->assertSessionHasNoErrors();
        $this->assertSame('outbound', $operation->operation_type);
        $this->assertSame('6.0000', $lot->fresh()->current_quantity);
        $this->assertSame('active', $lot->fresh()->status);
        $this->assertDatabaseHas('stock_operation_lines', [
            'id' => $line->id,
            'quantity_delta' => '-4.0000',
            'quantity_before' => '10.0000',
            'quantity_after' => '6.0000',
            'reason' => '注文 ORD-001',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_lot_id' => $lot->id,
            'movement_type' => 'outbound',
            'quantity_delta' => '-4.0000',
            'source_type' => 'stock_operation_line',
            'source_id' => $line->id,
            'created_by' => $admin->id,
        ]);
    }

    public function test_outbound_of_all_stock_marks_lot_as_depleted(): void
    {
        [$lot, $admin] = $this->inventoryLot('10');

        $this->actingAs($admin)->post('/admin/stock-operations', $this->input($lot, ['lines' => [[
            'inventory_lot_id' => $lot->id, 'quantity' => '10', 'reason' => null,
        ]]]))->assertSessionHasNoErrors();

        $this->assertSame('0.0000', $lot->fresh()->current_quantity);
        $this->assertSame('depleted', $lot->fresh()->status);
    }

    public function test_adjustment_accepts_increase_and_fraction_matching_unit(): void
    {
        [$lot, $admin] = $this->inventoryLot('10.000', 3);

        $response = $this->actingAs($admin)->post('/admin/stock-operations', $this->input($lot, [
            'operation_type' => 'adjustment',
            'notes' => '棚卸差異',
            'lines' => [[
                'inventory_lot_id' => $lot->id,
                'quantity' => '2.345',
                'reason' => '実棚が帳簿より多い',
            ]],
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame('12.3450', $lot->fresh()->current_quantity);
        $this->assertDatabaseHas('stock_operation_lines', ['quantity_delta' => '2.3450', 'quantity_before' => '10.0000', 'quantity_after' => '12.3450']);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'adjustment', 'quantity_delta' => '2.3450']);
    }

    public function test_negative_adjustment_reduces_stock_but_cannot_make_it_negative(): void
    {
        [$lot, $admin] = $this->inventoryLot('10');

        $this->actingAs($admin)->post('/admin/stock-operations', $this->input($lot, [
            'operation_type' => 'adjustment',
            'lines' => [['inventory_lot_id' => $lot->id, 'quantity' => '-3', 'reason' => '破損']],
        ]))->assertSessionHasNoErrors();
        $this->assertSame('7.0000', $lot->fresh()->current_quantity);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'adjustment', 'quantity_delta' => '-3.0000']);

        $this->post('/admin/stock-operations', $this->input($lot, [
            'operation_type' => 'adjustment',
            'lines' => [['inventory_lot_id' => $lot->id, 'quantity' => '-8', 'reason' => '棚卸差異']],
        ]))->assertSessionHasErrors('lines.0.quantity');
        $this->assertSame('7.0000', $lot->fresh()->current_quantity);
        $this->assertDatabaseCount('stock_operations', 1);
    }

    public function test_insufficient_stock_rolls_back_every_line(): void
    {
        [$first, $admin] = $this->inventoryLot('10');
        [$second] = $this->inventoryLot('2');
        $input = $this->input($first, ['lines' => [
            ['inventory_lot_id' => $first->id, 'quantity' => '3', 'reason' => null],
            ['inventory_lot_id' => $second->id, 'quantity' => '5', 'reason' => null],
        ]]);

        $this->actingAs($admin)->post('/admin/stock-operations', $input)
            ->assertSessionHasErrors(['lines.1.quantity' => "ロット {$second->lot_number} の現在庫（2.0000）を超える数量は減らせません。"]);

        $this->assertSame('10.0000', $first->fresh()->current_quantity);
        $this->assertSame('2.0000', $second->fresh()->current_quantity);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('stock_operation_lines', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_duplicate_lot_invalid_direction_and_quantity_scale_are_rejected(): void
    {
        [$lot, $admin] = $this->inventoryLot('10', 0);
        $duplicate = $this->input($lot, ['lines' => [
            ['inventory_lot_id' => $lot->id, 'quantity' => '1', 'reason' => null],
            ['inventory_lot_id' => $lot->id, 'quantity' => '1', 'reason' => null],
        ]]);
        $this->actingAs($admin)->post('/admin/stock-operations', $duplicate)->assertSessionHasErrors('lines.1.inventory_lot_id');

        $this->post('/admin/stock-operations', $this->input($lot, ['lines' => [[
            'inventory_lot_id' => $lot->id, 'quantity' => '-1', 'reason' => null,
        ]]]))->assertSessionHasErrors('lines.0.quantity');

        $this->post('/admin/stock-operations', $this->input($lot, ['lines' => [[
            'inventory_lot_id' => $lot->id, 'quantity' => '1.5', 'reason' => null,
        ]]]))->assertSessionHasErrors(['lines.0.quantity' => 'この販売商品の数量は整数で入力してください。']);
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_operation_list_filters_and_detail_show_saved_snapshot(): void
    {
        [$lot, $admin] = $this->inventoryLot('10');
        $this->actingAs($admin)->post('/admin/stock-operations', $this->input($lot, [
            'occurred_on' => '2026-10-20',
            'lines' => [['inventory_lot_id' => $lot->id, 'quantity' => '2', 'reason' => '検索対象']],
        ]))->assertSessionHasNoErrors();
        $match = StockOperation::firstOrFail();
        StockOperation::factory()->create(['operation_type' => 'adjustment', 'occurred_on' => '2026-09-01']);

        $this->get('/admin/stock-operations?q='.$lot->lot_number.'&operation_type=outbound&occurred_month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StockOperations/Index')
                ->where('operations.total', 1)
                ->where('operations.data.0.id', $match->id)
                ->where('operations.data.0.lines_count', 1)
                ->where('counts.all', 2));

        $this->get('/admin/stock-operations/'.$match->id)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StockOperations/Show')
                ->where('operation.lines.0.inventory_lot.lot_number', $lot->lot_number)
                ->where('operation.lines.0.quantity_before', '10.0000')
                ->where('operation.lines.0.quantity_after', '8.0000')
                ->where('operation.lines.0.reason', '検索対象'));
    }
}
