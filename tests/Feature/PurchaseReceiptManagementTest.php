<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchaseReceiptManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{0: Supplier, 1: Product, 2: ProductSupplier} */
    private function purchasingContext(int $quantityScale = 0): array
    {
        $supplier = Supplier::factory()->create(['name' => '北海道青果']);
        $unit = Unit::factory()->create(['name' => $quantityScale === 0 ? '個' : 'kg', 'quantity_scale' => $quantityScale]);
        $product = Product::factory()->create(['name' => '男爵ジャガイモ', 'sku' => 'POTATO-'.$supplier->id, 'unit_id' => $unit->id, 'registration_status' => 'confirmed']);
        $setting = ProductSupplier::factory()->create(['supplier_id' => $supplier->id, 'product_id' => $product->id, 'default_purchase_price' => '180.5000']);

        return [$supplier, $product, $setting];
    }

    private function input(Supplier $supplier, Product $product, ProductSupplier $setting, array $overrides = []): array
    {
        return array_replace_recursive([
            'supplier_id' => $supplier->id,
            'received_on' => '2026-10-15',
            'notes' => '午前入庫',
            'action' => 'draft',
            'lock_version' => 0,
            'lines' => [[
                'product_id' => $product->id,
                'product_supplier_id' => $setting->id,
                'lot_number' => 'LOT-202610-A',
                'manufactured_on' => '2026-10-01',
                'expires_on' => '2026-12-31',
                'quantity' => '10',
                'unit_purchase_price' => '180.5000',
            ]],
        ], $overrides);
    }

    public function test_receipt_and_inventory_routes_require_verified_admin(): void
    {
        [$supplier, $product, $setting] = $this->purchasingContext();
        $receipt = PurchaseReceipt::factory()->create(['supplier_id' => $supplier->id]);

        $this->get('/admin/purchase-receipts')->assertRedirect('/admin/login');
        $this->post('/admin/purchase-receipts', $this->input($supplier, $product, $setting))->assertRedirect('/admin/login');
        $this->get('/admin/inventory-lots')->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create());
        $this->get('/admin/purchase-receipts')->assertForbidden();
        $this->get('/admin/purchase-receipts/create')->assertForbidden();
        $this->get('/admin/purchase-receipts/'.$receipt->id.'/edit')->assertForbidden();
        $this->post('/admin/purchase-receipts', $this->input($supplier, $product, $setting))->assertForbidden();
        $this->get('/admin/inventory-lots')->assertForbidden();

        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->get('/admin/purchase-receipts')->assertRedirect('/email/verify');
        $this->get('/admin/inventory-lots')->assertRedirect('/email/verify');
    }

    public function test_admin_saves_draft_without_changing_stock(): void
    {
        [$supplier, $product, $setting] = $this->purchasingContext();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/purchase-receipts', $this->input($supplier, $product, $setting));

        $receipt = PurchaseReceipt::firstOrFail();
        $response->assertRedirect('/admin/purchase-receipts/'.$receipt->id.'/edit')->assertSessionHasNoErrors();
        $this->assertSame('draft', $receipt->status);
        $this->assertDatabaseHas('purchase_receipt_lines', [
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'lot_number' => 'LOT-202610-A',
            'quantity' => '10.0000',
            'purchase_amount' => '1805.0000',
        ]);
        $this->assertDatabaseCount('inventory_lots', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_posting_receipt_creates_one_inventory_lot_and_inbound_movement(): void
    {
        [$supplier, $product, $setting] = $this->purchasingContext(3);
        $admin = User::factory()->admin()->create();

        $input = $this->input($supplier, $product, $setting, ['action' => 'post', 'lines' => [[
            'product_id' => $product->id,
            'product_supplier_id' => $setting->id,
            'lot_number' => 'LOT-KG-001',
            'manufactured_on' => null,
            'expires_on' => null,
            'quantity' => '12.345',
            'unit_purchase_price' => '180.5000',
        ]]]);
        $this->actingAs($admin)->post('/admin/purchase-receipts', $input)->assertSessionHasNoErrors();

        $receipt = PurchaseReceipt::firstOrFail();
        $line = $receipt->lines()->firstOrFail();
        $this->assertSame('posted', $receipt->status);
        $this->assertSame($admin->id, $receipt->posted_by);
        $this->assertDatabaseHas('inventory_lots', [
            'purchase_receipt_line_id' => $line->id,
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'lot_number' => 'LOT-KG-001',
            'initial_quantity' => '12.3450',
            'current_quantity' => '12.3450',
            'unit_purchase_price' => '180.5000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'movement_type' => 'inbound',
            'quantity_delta' => '12.3450',
            'source_type' => 'purchase_receipt_line',
            'source_id' => $line->id,
        ]);
    }

    public function test_draft_update_replaces_lines_and_uses_optimistic_lock(): void
    {
        [$supplier, $product, $setting] = $this->purchasingContext();
        $admin = User::factory()->admin()->create();
        $receipt = PurchaseReceipt::factory()->create(['supplier_id' => $supplier->id, 'created_by' => $admin->id, 'lock_version' => 1]);
        $old = PurchaseReceiptLine::factory()->create([
            'purchase_receipt_id' => $receipt->id, 'product_id' => $product->id, 'product_supplier_id' => $setting->id,
            'line_number' => 1, 'lot_number' => 'OLD',
        ]);

        $stale = $this->input($supplier, $product, $setting, ['lock_version' => 0]);
        $this->actingAs($admin)->put('/admin/purchase-receipts/'.$receipt->id, $stale)->assertSessionHasErrors('lock_version');
        $this->assertDatabaseHas('purchase_receipt_lines', ['id' => $old->id, 'lot_number' => 'OLD']);

        $fresh = $this->input($supplier, $product, $setting, ['lock_version' => 1, 'lines' => [[
            'product_id' => $product->id, 'product_supplier_id' => $setting->id, 'lot_number' => 'NEW',
            'manufactured_on' => null, 'expires_on' => null, 'quantity' => '3', 'unit_purchase_price' => '200',
        ]]]);
        $this->put('/admin/purchase-receipts/'.$receipt->id, $fresh)->assertSessionHasNoErrors();

        $this->assertSame(2, $receipt->fresh()->lock_version);
        $this->assertDatabaseMissing('purchase_receipt_lines', ['id' => $old->id]);
        $this->assertDatabaseHas('purchase_receipt_lines', ['purchase_receipt_id' => $receipt->id, 'lot_number' => 'NEW', 'purchase_amount' => '600.0000']);
    }

    public function test_posted_receipt_cannot_be_changed_or_posted_twice(): void
    {
        [$supplier, $product, $setting] = $this->purchasingContext();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/purchase-receipts', $this->input($supplier, $product, $setting, ['action' => 'post']))->assertSessionHasNoErrors();
        $receipt = PurchaseReceipt::firstOrFail();
        $line = $receipt->lines()->firstOrFail();

        $change = $this->input($supplier, $product, $setting, ['action' => 'post', 'lock_version' => $receipt->lock_version, 'lines' => [[
            'id' => $line->id, 'product_id' => $product->id, 'product_supplier_id' => $setting->id,
            'lot_number' => 'CHANGED', 'manufactured_on' => null, 'expires_on' => null, 'quantity' => '20', 'unit_purchase_price' => '180.5',
        ]]]);
        $this->put('/admin/purchase-receipts/'.$receipt->id, $change)->assertSessionHasErrors('status');

        $this->assertDatabaseCount('inventory_lots', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('purchase_receipt_lines', ['id' => $line->id, 'lot_number' => 'LOT-202610-A', 'quantity' => '10.0000']);
    }

    public function test_invalid_supplier_setting_duplicate_lot_dates_and_quantity_scale_are_rejected(): void
    {
        [$supplier, $product, $setting] = $this->purchasingContext();
        [$otherSupplier, $otherProduct, $otherSetting] = $this->purchasingContext();
        $admin = User::factory()->admin()->create();

        $mismatch = $this->input($supplier, $product, $setting, ['lines' => [[
            'product_id' => $otherProduct->id, 'product_supplier_id' => $otherSetting->id, 'lot_number' => 'X',
            'manufactured_on' => null, 'expires_on' => null, 'quantity' => '1', 'unit_purchase_price' => '10',
        ]]]);
        $this->actingAs($admin)->post('/admin/purchase-receipts', $mismatch)->assertSessionHasErrors('lines.0.product_id');

        $bad = $this->input($supplier, $product, $setting, ['lines' => [
            ['product_id' => $product->id, 'product_supplier_id' => $setting->id, 'lot_number' => 'SAME', 'manufactured_on' => '2026-10-10', 'expires_on' => '2026-10-01', 'quantity' => '1.5', 'unit_purchase_price' => '10'],
            ['product_id' => $product->id, 'product_supplier_id' => $setting->id, 'lot_number' => 'same', 'manufactured_on' => null, 'expires_on' => null, 'quantity' => '1', 'unit_purchase_price' => '10'],
        ]]);
        $this->post('/admin/purchase-receipts', $bad)->assertSessionHasErrors(['lines.0.expires_on', 'lines.0.quantity', 'lines.1.lot_number']);

        $setting->update(['status' => 'inactive']);
        $this->post('/admin/purchase-receipts', $this->input($supplier, $product, $setting))->assertSessionHasErrors('lines.0.product_id');
        $this->assertDatabaseCount('purchase_receipts', 0);
    }

    public function test_receipt_list_filters_by_supplier_status_month_and_lot(): void
    {
        [$supplier, $product, $setting] = $this->purchasingContext();
        $admin = User::factory()->admin()->create();
        $match = PurchaseReceipt::factory()->create(['supplier_id' => $supplier->id, 'created_by' => $admin->id, 'received_on' => '2026-10-15', 'status' => 'posted']);
        PurchaseReceiptLine::factory()->create(['purchase_receipt_id' => $match->id, 'product_id' => $product->id, 'product_supplier_id' => $setting->id, 'lot_number' => 'FIND-ME']);
        PurchaseReceipt::factory()->create(['received_on' => '2026-09-01', 'status' => 'draft']);

        $this->actingAs($admin)->get('/admin/purchase-receipts?supplier_id='.$supplier->id.'&status=posted&received_month=2026-10&q=FIND')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/PurchaseReceipts/Index')
                ->where('receipts.total', 1)
                ->where('receipts.data.0.id', $match->id)
                ->where('receipts.data.0.lines_count', 1)
                ->where('counts.all', 2)
                ->where('counts.posted', 1));
    }

    public function test_inventory_list_filters_lots_and_totals_current_quantity_by_unit(): void
    {
        [$supplier, $product, $setting] = $this->purchasingContext(3);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/purchase-receipts', $this->input($supplier, $product, $setting, [
            'action' => 'post', 'received_on' => '2026-10-15', 'lines' => [[
                'product_id' => $product->id, 'product_supplier_id' => $setting->id, 'lot_number' => 'VISIBLE-LOT',
                'manufactured_on' => null, 'expires_on' => null, 'quantity' => '12.345', 'unit_purchase_price' => '180.5',
            ]],
        ]))->assertSessionHasNoErrors();

        $this->get('/admin/inventory-lots?supplier_id='.$supplier->id.'&received_month=2026-10&q=VISIBLE')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/InventoryLots/Index')
                ->where('lots.total', 1)
                ->where('lots.data.0.lot_number', 'VISIBLE-LOT')
                ->where('totalsByUnit.0.name', 'kg')
                ->where('totalsByUnit.0.total_quantity', 12.345));
    }
}
