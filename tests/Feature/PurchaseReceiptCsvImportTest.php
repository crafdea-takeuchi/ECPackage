<?php

namespace Tests\Feature;

use App\CsvImports\PurchaseReceiptCsvImportDefinition;
use App\Enums\CsvImportStatus;
use App\Models\CsvImport;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Csv\CsvImportManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchaseReceiptCsvImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('csv_imports');
        config(['csv-imports.disk' => 'csv_imports']);
    }

    public function test_admin_can_open_upload_page_and_download_purchase_receipt_template(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/purchase-receipts/csv-imports/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/PurchaseReceipts/CsvImports/Create')
                ->where('context.type', 'purchase_receipts')
                ->where('context.template_available', true)
                ->has('context.fields', 10)
                ->where('context.fields.0.name', '入庫番号')
                ->where('context.fields.0.requirement', '必須')
                ->has('context.help', 4));

        $response = $this->get('/admin/purchase-receipts/csv-imports/template');

        $response->assertOk()->assertDownload('purchase_receipts-template.csv');
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('入庫番号,入庫日,仕入先コード,商品コード,ロット番号', $content);
    }

    public function test_valid_csv_groups_lines_and_creates_draft_without_changing_stock(): void
    {
        [$admin, $supplier, $firstProduct, $firstSetting, $secondProduct] = $this->purchasingContext();
        ProductSupplier::factory()->create([
            'supplier_id' => $supplier->id,
            'product_id' => $secondProduct->id,
            'status' => 'active',
        ]);
        $import = $this->makeImport($admin, [
            $this->validRow($supplier, $firstProduct),
            $this->validRow($supplier, $secondProduct, [
                'ロット番号' => 'LOT-202611-B',
                '入庫数量' => '2',
                '仕入単価' => '450',
                '期限日' => '2026-11-30',
            ]),
        ]);

        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Ready, $import->status);
        $this->assertSame(2, $import->valid_rows);

        $manager->execute($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Completed, $import->status);
        $this->assertSame(2, $import->imported_rows);
        $receipt = PurchaseReceipt::where('receipt_number', 'RCV-20261109-001')->firstOrFail();
        $this->assertSame('draft', $receipt->status);
        $this->assertSame($supplier->id, $receipt->supplier_id);
        $this->assertSame($admin->id, $receipt->created_by);
        $this->assertSame('午前便', $receipt->notes);
        $this->assertSame(2, $receipt->lines()->count());
        $this->assertDatabaseHas('purchase_receipt_lines', [
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $firstProduct->id,
            'product_supplier_id' => $firstSetting->id,
            'line_number' => 1,
            'lot_number' => 'LOT-202611-A',
            'expires_on' => '2027-05-01',
            'quantity' => '10.0000',
            'unit_purchase_price' => '180.5000',
            'purchase_amount' => '1805.0000',
        ]);
        $this->assertDatabaseHas('purchase_receipt_lines', [
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $secondProduct->id,
            'line_number' => 2,
            'purchase_amount' => '900.0000',
        ]);
        $this->assertDatabaseCount('inventory_lots', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_csv_rejects_inconsistent_receipt_fields_duplicate_lot_and_invalid_dates(): void
    {
        [$admin, $supplier, $product] = $this->purchasingContext();
        $otherSupplier = Supplier::factory()->create(['code' => 'SUP-002', 'status' => 'active']);
        ProductSupplier::factory()->create([
            'supplier_id' => $otherSupplier->id,
            'product_id' => $product->id,
            'status' => 'active',
        ]);
        $import = $this->makeImport($admin, [
            $this->validRow($supplier, $product),
            $this->validRow($otherSupplier, $product, [
                '入庫日' => '2026-11-10',
                '期限日' => '2026-10-01',
                '備考' => '午後便',
            ]),
        ]);

        app(CsvImportManager::class)->validate($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Invalid, $import->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'row_number' => 3,
            'column' => '入庫日',
            'message' => '同じ入庫番号の入庫日は統一してください。',
        ]);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'row_number' => 3,
            'column' => '仕入先コード',
            'message' => '同じ入庫番号の仕入先コードは統一してください。',
        ]);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'row_number' => 3,
            'column' => 'ロット番号',
            'message' => '同じ入庫番号内で販売商品とロット番号が重複しています。',
        ]);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'row_number' => 3,
            'column' => '期限日',
            'message' => '期限日は製造日以降の日付を入力してください。',
        ]);
        $this->assertDatabaseCount('purchase_receipts', 0);
    }

    public function test_csv_rejects_existing_receipt_number_and_product_without_active_purchase_setting(): void
    {
        [$admin, $supplier, $product, $setting] = $this->purchasingContext();
        PurchaseReceipt::factory()->create([
            'receipt_number' => 'RCV-20261109-001',
            'supplier_id' => $supplier->id,
            'created_by' => $admin->id,
        ]);
        $setting->update(['status' => 'inactive']);
        $import = $this->makeImport($admin, [$this->validRow($supplier, $product)]);

        app(CsvImportManager::class)->validate($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Invalid, $import->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'column' => '入庫番号',
            'message' => 'この入庫番号はすでに登録されています。',
        ]);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'column' => '商品コード',
            'message' => 'この仕入先に有効な仕入商品設定がありません。',
        ]);
        $this->assertDatabaseCount('purchase_receipts', 1);
    }

    /** @return array{User, Supplier, Product, ProductSupplier, Product} */
    private function purchasingContext(): array
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create(['code' => 'SUP-001', 'status' => 'active']);
        $unit = Unit::factory()->create(['code' => 'piece-csv', 'quantity_scale' => 0, 'status' => 'active']);
        $firstProduct = Product::factory()->create([
            'sku' => 'PAN-001',
            'unit_id' => $unit->id,
            'registration_status' => 'confirmed',
        ]);
        $secondProduct = Product::factory()->create([
            'sku' => 'PAN-002',
            'unit_id' => $unit->id,
            'registration_status' => 'confirmed',
        ]);
        $firstSetting = ProductSupplier::factory()->create([
            'supplier_id' => $supplier->id,
            'product_id' => $firstProduct->id,
            'status' => 'active',
        ]);

        return [$admin, $supplier, $firstProduct, $firstSetting, $secondProduct];
    }

    /** @return array<string, string> */
    private function validRow(Supplier $supplier, Product $product, array $overrides = []): array
    {
        return array_replace([
            '入庫番号' => 'RCV-20261109-001',
            '入庫日' => '2026-11-09',
            '仕入先コード' => $supplier->code,
            '商品コード' => $product->sku,
            'ロット番号' => 'LOT-202611-A',
            '入庫数量' => '10',
            '仕入単価' => '180.5',
            '製造日' => '2026-11-01',
            '期限日' => '2027-05-01',
            '備考' => '午前便',
        ], $overrides);
    }

    /** @param list<array<string, string>> $rows */
    private function makeImport(User $admin, array $rows): CsvImport
    {
        $headers = app(PurchaseReceiptCsvImportDefinition::class)->requiredHeaders();
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $header): string => $row[$header] ?? '', $headers), ',', '"', '');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        $path = 'tests/purchase-receipts-'.uniqid('', true).'.csv';
        Storage::disk('csv_imports')->put($path, $contents);

        return CsvImport::create([
            'type' => 'purchase_receipts',
            'original_filename' => 'purchase-receipts.csv',
            'disk' => 'csv_imports',
            'path' => $path,
            'file_hash' => hash('sha256', $contents),
            'status' => CsvImportStatus::Uploaded,
            'created_by' => $admin->id,
        ]);
    }
}
