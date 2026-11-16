<?php

namespace Tests\Feature;

use App\CsvImports\PurchaseReceiptCsvImportDefinition;
use App\Enums\CsvImportStatus;
use App\Exceptions\CsvImportFormatException;
use App\Models\CsvImport;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Csv\CsvImportManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
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
                ->has('context.fields', 11)
                ->where('context.fields.8.name', '期限種別')
                ->where('context.fields.8.example', '賞味期限')
                ->where('context.fields.8.description', fn ($description) => str_contains($description, 'best_before、use_by') && str_contains($description, '期限なし'))
                ->where('context.fields.9.name', '期限日')
                ->where('context.fields.9.example', '2027-05-31')
                ->where('context.fields.0.name', '入庫番号')
                ->where('context.fields.0.requirement', '必須')
                ->has('context.help', 4));

        $response = $this->get('/admin/purchase-receipts/csv-imports/template');

        $response->assertOk()->assertDownload('purchase_receipts-template.csv');
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertSame(['入庫番号', '入庫日', '仕入先コード', '商品コード', 'ロット番号', '入庫数量', '仕入単価', '製造日', '期限種別', '期限日', '備考'], str_getcsv(trim(substr($content, 3)), ',', '"', ''));
        $this->assertSame(1, substr_count($content, "\xEF\xBB\xBF"));
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
                '期限種別' => '消費期限',
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
            'expiration_type' => 'best_before',
            'quantity' => '10.0000',
            'unit_purchase_price' => '180.5000',
            'purchase_amount' => '1805.0000',
        ]);
        $this->assertDatabaseHas('purchase_receipt_lines', [
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $secondProduct->id,
            'line_number' => 2,
            'purchase_amount' => '900.0000',
            'expiration_type' => 'use_by',
            'expires_on' => '2026-11-30',
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

    #[TestWith(['賞味期限', 'best_before', '賞味期限', '2027-05-31'])]
    #[TestWith(['消費期限', 'use_by', '消費期限', '2026-11-20'])]
    #[TestWith(['best_before', 'best_before', '賞味期限', '2027-05-31'])]
    #[TestWith(['use_by', 'use_by', '消費期限', '2026-11-20'])]
    #[TestWith(['', null, '', ''])]
    public function test_expiration_is_previewed_saved_and_posted_using_existing_receipt_flow(string $input, ?string $saved, string $label, string $date): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'Asia/Tokyo'));
        [$admin, $supplier, $product] = $this->purchasingContext();
        $import = $this->makeImport($admin, [$this->validRow($supplier, $product, ['期限種別' => $input, '期限日' => $date])]);
        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);
        $this->actingAs($admin)->get('/admin/purchase-receipts/csv-imports/'.$import->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.rows.0.data.期限種別', $label)
                ->where('preview.rows.0.data.期限日', $date));
        $this->assertSame($input, $manager->preview($import)['rows'][0]['data']['期限種別']);
        $manager->execute($import);
        $receipt = PurchaseReceipt::sole();
        $line = $receipt->lines()->sole();
        $this->assertDatabaseHas('purchase_receipt_lines', ['id' => $line->id, 'expiration_type' => $saved, 'expires_on' => $date ?: null]);
        $this->assertDatabaseCount('inventory_lots', 0);

        $this->put('/admin/purchase-receipts/'.$receipt->id, [
            'supplier_id' => $supplier->id, 'received_on' => '2026-11-09',
            'lock_version' => $receipt->lock_version, 'action' => 'post',
            'lines' => [[...$line->only(['id', 'product_id', 'product_supplier_id', 'lot_number', 'expiration_type', 'quantity', 'unit_purchase_price']),
                'manufactured_on' => $line->manufactured_on?->format('Y-m-d'), 'expires_on' => $line->expires_on?->format('Y-m-d'),
            ]],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_lots', ['purchase_receipt_line_id' => $line->id, 'expiration_type' => $saved, 'expires_on' => $date ?: null]);
        $this->assertDatabaseCount('stock_movements', 1);
        $typeFilter = $saved ?? 'none';
        $expectedStatus = $saved === 'use_by' ? 'near' : ($saved === null ? 'none' : 'valid');
        $this->get('/admin/inventory-lots?product_id='.$product->id.'&expiration_type='.$typeFilter.'&expiration_status='.$expectedStatus)
            ->assertInertia(fn (Assert $page) => $page->where('lots.total', 1)
                ->where('lots.data.0.expiration_type', $saved)
                ->where('lots.data.0.expiration.status', $expectedStatus));
    }

    #[TestWith(['賞味期限', '', '期限日', '期限種別を指定した場合は、期限日も入力してください。'])]
    #[TestWith(['', '2027-05-31', '期限種別', '期限日を指定した場合は、期限種別も入力してください。'])]
    #[TestWith(['期限なし', '2027-05-31', '期限種別', '期限種別は「賞味期限」または「消費期限」を入力してください。'])]
    #[TestWith(['invalid', '2027-05-31', '期限種別', '期限種別は「賞味期限」または「消費期限」を入力してください。'])]
    #[TestWith(['消費期限', '2027-02-30', '期限日', '期限日はYYYY-MM-DD形式で入力してください。'])]
    #[TestWith(['best_before', '2027/05/31', '期限日', '期限日はYYYY-MM-DD形式で入力してください。'])]
    #[TestWith(['use_by', '2026-10-31', '期限日', '期限日は製造日以降の日付を入力してください。'])]
    public function test_expiration_error_in_one_row_blocks_all_rows_and_preserves_error_history(string $type, string $date, string $column, string $message): void
    {
        [$admin, $supplier, $product] = $this->purchasingContext();
        $import = $this->makeImport($admin, [
            $this->validRow($supplier, $product),
            $this->validRow($supplier, $product, ['ロット番号' => 'BAD-LOT', '期限種別' => $type, '期限日' => $date]),
        ]);
        app(CsvImportManager::class)->validate($import);
        $this->assertSame(CsvImportStatus::Invalid, $import->fresh()->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id, 'row_number' => 3, 'column' => $column, 'message' => $message,
        ]);
        $this->actingAs($admin)->from('/admin/purchase-receipts/csv-imports/'.$import->id)
            ->post('/admin/purchase-receipts/csv-imports/'.$import->id.'/execute')->assertSessionHasErrors('csv');
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('purchase_receipt_lines', 0);
        $this->assertDatabaseCount('inventory_lots', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('csv_imports', 1);
    }

    public function test_old_header_without_expiration_type_is_rejected(): void
    {
        [$admin, $supplier, $product] = $this->purchasingContext();
        $oldHeaders = ['入庫番号', '入庫日', '仕入先コード', '商品コード', 'ロット番号', '入庫数量', '仕入単価', '製造日', '期限日', '備考'];
        $import = $this->makeImport($admin, [$this->validRow($supplier, $product)], $oldHeaders);
        app(CsvImportManager::class)->validate($import);
        $this->assertSame(CsvImportStatus::Invalid, $import->fresh()->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id, 'row_number' => 1, 'column' => '期限種別', 'message' => '必須ヘッダー「期限種別」がありません。',
        ]);
        $this->assertDatabaseCount('purchase_receipts', 0);
    }

    public function test_runtime_failure_rolls_back_previous_receipts_and_keeps_failed_history(): void
    {
        [$admin, $supplier, $first, , $second] = $this->purchasingContext();
        $setting = ProductSupplier::factory()->create(['supplier_id' => $supplier->id, 'product_id' => $second->id, 'status' => 'active']);
        $import = $this->makeImport($admin, [
            $this->validRow($supplier, $first),
            $this->validRow($supplier, $second, ['入庫番号' => 'SECOND', 'ロット番号' => 'SECOND', '期限種別' => '消費期限']),
        ]);
        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);
        $setting->update(['status' => 'inactive']);
        $created = [];
        PurchaseReceipt::created(function (PurchaseReceipt $receipt) use (&$created): void {
            $created[] = $receipt->receipt_number;
        });
        try {
            $manager->execute($import);
            $this->fail('実登録時のエラーは全件ロールバックする必要があります。');
        } catch (CsvImportFormatException $exception) {
            $this->assertStringContainsString('商品設定が検証時から変更されています', $exception->getMessage());
        }
        $this->assertContains('RCV-20261109-001', $created);
        $this->assertSame(CsvImportStatus::Failed, $import->fresh()->status);
        $this->assertSame(0, $import->fresh()->imported_rows);
        $this->assertStringContainsString('商品設定', $import->fresh()->system_error);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('purchase_receipt_lines', 0);
        $this->assertDatabaseCount('inventory_lots', 0);
    }

    public function test_execution_revalidates_expiration_even_if_marked_ready(): void
    {
        [$admin, $supplier, $product] = $this->purchasingContext();
        $import = $this->makeImport($admin, [$this->validRow($supplier, $product, ['期限種別' => ''])]);
        $import->update(['status' => CsvImportStatus::Ready]);
        try {
            app(CsvImportManager::class)->execute($import);
            $this->fail('期限情報が不正な取込は実登録時も失敗する必要があります。');
        } catch (CsvImportFormatException $exception) {
            $this->assertStringContainsString('CSV2行目「期限種別」', $exception->getMessage());
        }
        $this->assertSame(CsvImportStatus::Failed, $import->fresh()->status);
        $this->assertStringContainsString('期限種別', $import->fresh()->system_error);
        $this->assertDatabaseCount('purchase_receipts', 0);
    }

    public function test_normal_sample_csv_preserves_per_line_expiration_types(): void
    {
        [$admin] = $this->purchasingContext();
        $import = $this->importFixture($admin, 'purchase-receipts-expiration-valid.csv');
        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);
        $manager->execute($import);
        $receipt = PurchaseReceipt::sole();
        $this->assertSame(
            ['best_before', 'use_by', 'best_before', 'use_by', null],
            $receipt->lines()->orderBy('line_number')->pluck('expiration_type')->all(),
        );
        $this->assertDatabaseHas('purchase_receipt_lines', ['lot_number' => 'EXP-03', 'manufactured_on' => '2026-11-01', 'expires_on' => '2026-11-01']);
    }

    #[TestWith(['purchase-receipts-expiration-invalid.csv', 6])]
    #[TestWith(['purchase-receipts-expiration-missing-header.csv', 2])]
    public function test_invalid_sample_csv_records_errors_without_creating_receipts(string $filename, int $errorRows): void
    {
        [$admin] = $this->purchasingContext();
        $import = $this->importFixture($admin, $filename);
        app(CsvImportManager::class)->validate($import);
        $this->assertSame(CsvImportStatus::Invalid, $import->fresh()->status);
        $this->assertSame($errorRows, $import->fresh()->error_rows);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('purchase_receipt_lines', 0);
    }

    public function test_stock_operation_template_keeps_its_existing_columns(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $content = $this->get('/admin/stock-operations/csv-imports/template')->assertOk()->streamedContent();
        $this->assertSame(['操作種別', '処理日', '商品コード', 'ロット番号', '仕入先コード', '数量', '理由', '備考'], str_getcsv(trim(substr($content, 3)), ',', '"', ''));
    }

    private function importFixture(User $admin, string $filename): CsvImport
    {
        $import = $this->makeImport($admin, []);
        $contents = file_get_contents(base_path('tests/Fixtures/Csv/'.$filename));
        Storage::disk('csv_imports')->put($import->path, $contents);
        $import->update(['file_hash' => hash('sha256', $contents)]);

        return $import;
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
            '期限種別' => '賞味期限',
            '期限日' => '2027-05-01',
            '備考' => '午前便',
        ], $overrides);
    }

    /** @param list<array<string, string>> $rows */
    private function makeImport(User $admin, array $rows, ?array $headers = null): CsvImport
    {
        $headers ??= app(PurchaseReceiptCsvImportDefinition::class)->requiredHeaders();
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
