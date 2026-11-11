<?php

namespace Tests\Feature;

use App\CsvImports\StockOperationCsvImportDefinition;
use App\Enums\CsvImportStatus;
use App\Jobs\ExecuteCsvImport;
use App\Models\CsvImport;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\StockOperation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Csv\CsvImportManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StockOperationCsvImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('csv_imports');
        config(['csv-imports.disk' => 'csv_imports']);
    }

    public function test_admin_can_open_upload_page_and_download_stock_operation_template(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/stock-operations/csv-imports/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StockOperations/CsvImports/Create')
                ->where('context.type', 'stock_operations')
                ->where('context.template_available', true)
                ->has('context.fields', 8)
                ->where('context.fields.0.name', '操作種別')
                ->where('context.fields.0.requirement', '必須')
                ->has('context.help', 4));

        $response = $this->get('/admin/stock-operations/csv-imports/template');

        $response->assertOk()->assertDownload('stock_operations-template.csv');
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertSame("\xEF\xBB\xBF操作種別,処理日,商品コード,ロット番号,仕入先コード,数量,理由,備考\n", $content);
    }

    public function test_valid_csv_applies_outbound_increase_and_decrease_in_row_order(): void
    {
        [$admin, $lot] = $this->inventoryContext('10', [
            'expires_on' => '2027-05-01',
        ]);
        $import = $this->makeImport($admin, [
            $this->validRow($lot, [
                '操作種別' => '通常出庫',
                '数量' => '3',
                '理由' => '店頭販売',
            ]),
            $this->validRow($lot, [
                '操作種別' => '在庫増加',
                '数量' => '2',
                '理由' => '棚卸差異',
            ]),
            $this->validRow($lot, [
                '操作種別' => '在庫減少',
                '数量' => '4',
                '理由' => '破損品の廃棄',
            ]),
        ]);

        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Ready, $import->status);
        $this->assertSame(3, $import->valid_rows);

        $manager->execute($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Completed, $import->status);
        $this->assertSame(3, $import->imported_rows);
        $this->assertSame('5.0000', $lot->fresh()->current_quantity);
        $this->assertSame('active', $lot->fresh()->status);
        $this->assertDatabaseCount('stock_operations', 3);
        $this->assertDatabaseCount('stock_operation_lines', 3);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseHas('stock_operation_lines', [
            'inventory_lot_id' => $lot->id,
            'quantity_before' => '10.0000',
            'quantity_delta' => '-3.0000',
            'quantity_after' => '7.0000',
            'reason' => '店頭販売',
        ]);
        $this->assertDatabaseHas('stock_operation_lines', [
            'inventory_lot_id' => $lot->id,
            'quantity_before' => '7.0000',
            'quantity_delta' => '2.0000',
            'quantity_after' => '9.0000',
            'reason' => '棚卸差異',
        ]);
        $this->assertDatabaseHas('stock_operation_lines', [
            'inventory_lot_id' => $lot->id,
            'quantity_before' => '9.0000',
            'quantity_delta' => '-4.0000',
            'quantity_after' => '5.0000',
            'reason' => '破損品の廃棄',
        ]);
        $this->assertSame(1, StockOperation::where('operation_type', 'outbound')->count());
        $this->assertSame(2, StockOperation::where('operation_type', 'adjustment')->count());
    }

    public function test_csv_rejects_cumulative_stock_shortage_before_execution(): void
    {
        [$admin, $lot] = $this->inventoryContext('5');
        $import = $this->makeImport($admin, [
            $this->validRow($lot, ['操作種別' => '通常出庫', '数量' => '4']),
            $this->validRow($lot, ['操作種別' => '在庫減少', '数量' => '2']),
        ]);

        app(CsvImportManager::class)->validate($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Invalid, $import->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'row_number' => 3,
            'column' => '数量',
            'message' => "CSV内の処理順ではロット {$lot->lot_number} の在庫（1.0000）を超えて減らせません。",
        ]);
        $this->assertSame('5.0000', $lot->fresh()->current_quantity);
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_supplier_code_disambiguates_same_product_and_lot_number(): void
    {
        [$admin, $firstLot] = $this->inventoryContext('10');
        $secondSupplier = Supplier::factory()->create(['code' => 'SUP-002', 'status' => 'active']);
        $secondSetting = ProductSupplier::factory()->create([
            'supplier_id' => $secondSupplier->id,
            'product_id' => $firstLot->product_id,
            'status' => 'active',
        ]);
        $secondReceipt = PurchaseReceipt::factory()->create([
            'supplier_id' => $secondSupplier->id,
            'created_by' => $admin->id,
            'status' => 'posted',
        ]);
        $secondLine = PurchaseReceiptLine::factory()->create([
            'purchase_receipt_id' => $secondReceipt->id,
            'product_id' => $firstLot->product_id,
            'product_supplier_id' => $secondSetting->id,
            'lot_number' => $firstLot->lot_number,
        ]);
        InventoryLot::factory()->create([
            'product_id' => $firstLot->product_id,
            'supplier_id' => $secondSupplier->id,
            'purchase_receipt_line_id' => $secondLine->id,
            'lot_number' => $firstLot->lot_number,
            'current_quantity' => '10',
        ]);
        $ambiguous = $this->makeImport($admin, [
            $this->validRow($firstLot, ['仕入先コード' => '']),
        ]);

        $manager = app(CsvImportManager::class);
        $manager->validate($ambiguous);
        $this->assertSame(CsvImportStatus::Invalid, $ambiguous->fresh()->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $ambiguous->id,
            'column' => 'ロット番号',
            'message' => '一致する在庫ロットが複数あります。仕入先コードを指定して一意にしてください。',
        ]);

        $specified = $this->makeImport($admin, [$this->validRow($firstLot)]);
        $manager->validate($specified);
        $this->assertSame(CsvImportStatus::Ready, $specified->fresh()->status);

        $manager->execute($specified);

        $this->assertSame('9.0000', $firstLot->fresh()->current_quantity);
    }

    #[TestWith(['在庫増加', ['ロット番号' => 'UNKNOWN-LOT'], '仕入先コード'])]
    #[TestWith(['通常出庫', ['商品コード' => 'OTHER-PRODUCT'], '仕入先コード'])]
    #[TestWith(['在庫減少', ['仕入先コード' => 'OTHER-SUPPLIER'], '仕入先コード'])]
    #[TestWith(['在庫増加', ['ロット番号' => 'UNKNOWN-LOT', '仕入先コード' => ''], 'ロット番号'])]
    public function test_unmatched_product_lot_or_supplier_is_rejected_without_creating_lots(string $operation, array $overrides, string $column): void
    {
        [$admin, $lot] = $this->inventoryContext('10');
        Product::factory()->create(['sku' => 'OTHER-PRODUCT']);
        Supplier::factory()->create(['code' => 'OTHER-SUPPLIER']);
        $import = $this->makeImport($admin, [$this->validRow($lot, array_replace(['操作種別' => $operation], $overrides))]);

        app(CsvImportManager::class)->validate($import);

        $this->assertSame(CsvImportStatus::Invalid, $import->fresh()->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'column' => $column,
            'message' => '指定した商品・ロット・仕入先に一致する在庫ロットが見つかりません。',
        ]);
        $this->assertDatabaseCount('inventory_lots', 1);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertSame('10.0000', $lot->fresh()->current_quantity);
    }

    public function test_same_supplier_and_lot_is_still_ambiguous(): void
    {
        [$admin, $lot] = $this->inventoryContext('10');
        $receiptLine = PurchaseReceiptLine::factory()->create([
            'purchase_receipt_id' => $lot->receiptLine->purchase_receipt_id,
            'line_number' => 2,
            'product_id' => $lot->product_id,
            'product_supplier_id' => $lot->receiptLine->product_supplier_id,
            'lot_number' => $lot->lot_number,
        ]);
        InventoryLot::factory()->create([
            'product_id' => $lot->product_id,
            'supplier_id' => $lot->supplier_id,
            'purchase_receipt_line_id' => $receiptLine->id,
            'lot_number' => $lot->lot_number,
        ]);
        $import = $this->makeImport($admin, [$this->validRow($lot, ['操作種別' => '在庫増加'])]);

        app(CsvImportManager::class)->validate($import);

        $this->assertSame(CsvImportStatus::Invalid, $import->fresh()->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'column' => 'ロット番号',
            'message' => '指定した商品・ロット・仕入先に一致する在庫ロットが複数あります。ロットの登録内容を確認してください。',
        ]);
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_execution_refreshes_lot_lookup_after_validation(): void
    {
        [$admin, $lot] = $this->inventoryContext('10');
        $this->app->instance(StockOperationCsvImportDefinition::class, app(StockOperationCsvImportDefinition::class));
        $import = $this->makeImport($admin, [$this->validRow($lot, ['操作種別' => '在庫増加'])]);
        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);
        $lot->update(['lot_number' => 'CHANGED-LOT']);

        try {
            $manager->execute($import);
            $this->fail('実行時に存在しないロットは取込できません。');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('登録条件が検証時から変更されています', $exception->getMessage());
        }

        $this->assertSame('10.0000', $lot->fresh()->current_quantity);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('inventory_lots', 1);
    }

    public function test_later_execution_failure_rolls_back_all_prior_stock_operations(): void
    {
        [$admin, $lot] = $this->inventoryContext('10');
        $import = $this->makeImport($admin, [
            $this->validRow($lot, ['操作種別' => '在庫増加', '数量' => '1']),
            $this->validRow($lot, ['操作種別' => '通常出庫', '数量' => '5']),
        ]);
        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);
        $lot->update(['current_quantity' => '1']);
        $createdOperations = [];
        StockOperation::created(function (StockOperation $operation) use (&$createdOperations): void {
            $createdOperations[] = $operation->operation_type;
        });

        try {
            ExecuteCsvImport::dispatchSync($import->id);
            $this->fail('在庫不足のCSVは全件ロールバックする必要があります。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.quantity', $exception->errors());
        }

        $this->assertContains('adjustment', $createdOperations);
        $this->assertSame('1.0000', $lot->fresh()->current_quantity);
        $this->assertDatabaseCount('inventory_lots', 1);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('stock_operation_lines', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0, $import->fresh()->imported_rows);
        $this->assertSame(CsvImportStatus::Failed, $import->fresh()->status);
    }

    /** @return array{User, InventoryLot} */
    private function inventoryContext(string $quantity, array $lotOverrides = []): array
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create(['code' => 'SUP-001', 'status' => 'active']);
        $unit = Unit::factory()->create(['code' => 'piece-stock-csv', 'quantity_scale' => 0, 'status' => 'active']);
        $product = Product::factory()->create([
            'sku' => 'PAN-001',
            'unit_id' => $unit->id,
            'registration_status' => 'confirmed',
        ]);
        $setting = ProductSupplier::factory()->create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'status' => 'active',
        ]);
        $receipt = PurchaseReceipt::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $admin->id,
            'status' => 'posted',
        ]);
        $line = PurchaseReceiptLine::factory()->create([
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'product_supplier_id' => $setting->id,
            'lot_number' => 'LOT-202611-A',
            'quantity' => $quantity,
            'purchase_amount' => bcmul($quantity, '100', 4),
        ]);
        $lot = InventoryLot::factory()->create(array_replace([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'purchase_receipt_line_id' => $line->id,
            'lot_number' => 'LOT-202611-A',
            'received_on' => '2026-11-01',
            'initial_quantity' => $quantity,
            'current_quantity' => $quantity,
        ], $lotOverrides));

        return [$admin, $lot];
    }

    /** @return array<string, string> */
    private function validRow(InventoryLot $lot, array $overrides = []): array
    {
        return array_replace([
            '操作種別' => '通常出庫',
            '処理日' => '2026-11-09',
            '商品コード' => $lot->product->sku,
            'ロット番号' => $lot->lot_number,
            '仕入先コード' => $lot->supplier->code,
            '数量' => '1',
            '理由' => 'CSV取込',
            '備考' => '在庫操作CSV',
        ], $overrides);
    }

    /** @param list<array<string, string>> $rows */
    private function makeImport(User $admin, array $rows): CsvImport
    {
        $headers = app(StockOperationCsvImportDefinition::class)->requiredHeaders();
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $header): string => $row[$header] ?? '', $headers), ',', '"', '');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        $path = 'tests/stock-operations-'.uniqid('', true).'.csv';
        Storage::disk('csv_imports')->put($path, $contents);

        return CsvImport::create([
            'type' => 'stock_operations',
            'original_filename' => 'stock-operations.csv',
            'disk' => 'csv_imports',
            'path' => $path,
            'file_hash' => hash('sha256', $contents),
            'status' => CsvImportStatus::Uploaded,
            'created_by' => $admin->id,
        ]);
    }
}
