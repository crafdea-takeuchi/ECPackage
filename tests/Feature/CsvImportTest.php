<?php

namespace Tests\Feature;

use App\Enums\CsvImportStatus;
use App\Jobs\ValidateCsvImport;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\Csv\CsvImportManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('csv_imports');
        config(['csv-imports.disk' => 'csv_imports']);
    }

    public function test_admin_can_upload_csv_to_private_storage_and_validation_is_queued(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/purchase-receipts/csv-imports', [
            'type' => 'products',
            'file' => UploadedFile::fake()->createWithContent('products.csv', "sku,name\nP001,フライパン\n"),
        ]);

        $import = CsvImport::firstOrFail();
        $response->assertRedirect('/admin/purchase-receipts/csv-imports/'.$import->id);
        $this->assertSame(CsvImportStatus::Uploaded, $import->status);
        $this->assertSame('purchase_receipts', $import->type);
        $this->assertSame($admin->id, $import->created_by);
        Storage::disk('csv_imports')->assertExists($import->path);
        Queue::assertPushed(ValidateCsvImport::class, fn (ValidateCsvImport $job): bool => $job->csvImportId === $import->id);
    }

    public function test_common_validation_accepts_utf8_and_exposes_preview(): void
    {
        $import = $this->makeImport("sku,name\nP001,フライパン\nP002,鍋\n");

        app(CsvImportManager::class)->validate($import);
        $import->refresh();
        $preview = app(CsvImportManager::class)->preview($import);

        $this->assertSame(CsvImportStatus::Ready, $import->status);
        $this->assertSame('UTF-8', $import->encoding);
        $this->assertSame(2, $import->total_rows);
        $this->assertSame(2, $import->valid_rows);
        $this->assertSame(['sku', 'name'], $preview['headers']);
        $this->assertSame('フライパン', $preview['rows'][0]['data']['name']);
    }

    public function test_common_validation_converts_cp932_csv(): void
    {
        $utf8 = "sku,name\r\nP001,フライパン\r\n";
        $import = $this->makeImport(mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8'));

        app(CsvImportManager::class)->validate($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Ready, $import->status);
        $this->assertSame('CP932', $import->encoding);
        $this->assertSame('フライパン', app(CsvImportManager::class)->preview($import)['rows'][0]['data']['name']);
    }

    public function test_column_count_mismatch_is_saved_as_validation_error(): void
    {
        $import = $this->makeImport("sku,name\nP001,商品,余分\n");

        app(CsvImportManager::class)->validate($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Invalid, $import->status);
        $this->assertSame(1, $import->error_rows);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'row_number' => 2,
        ]);
    }

    public function test_duplicate_header_is_saved_as_validation_error(): void
    {
        $import = $this->makeImport("sku,sku\nP001,商品\n");

        app(CsvImportManager::class)->validate($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Invalid, $import->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'message' => 'ヘッダー名が重複しています。',
        ]);
    }

    public function test_stock_operation_csv_pages_are_individual_and_histories_are_scoped(): void
    {
        $admin = User::factory()->admin()->create();
        $stockImport = $this->makeImport("code,quantity\nS001,1\n", $admin, 'stock_operations');
        $productImport = $this->makeImport("code,name\nP001,商品\n", $admin, 'products');

        $this->actingAs($admin)
            ->get('/admin/stock-operations/csv-imports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StockOperations/CsvImports/Index')
                ->where('context.type', 'stock_operations')
                ->where('imports.total', 1)
                ->where('imports.data.0.id', $stockImport->id));

        $this->get('/admin/stock-operations/csv-imports/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StockOperations/CsvImports/Create')
                ->where('context.type', 'stock_operations')
                ->where('context.template_available', true)
                ->has('context.fields', 8));

        $this->get('/admin/stock-operations/csv-imports/'.$productImport->id)->assertNotFound();
    }

    public function test_shipment_csv_pages_are_individual_and_histories_are_scoped(): void
    {
        $admin = User::factory()->admin()->create();
        $shipmentImport = $this->makeImport("shipment_number,status\nSHP-001,draft\n", $admin, 'shipments');
        $stockImport = $this->makeImport("code,quantity\nS001,1\n", $admin, 'stock_operations');

        $this->actingAs($admin)
            ->get('/admin/shipments/csv-imports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Shipments/CsvImports/Index')
                ->where('context.type', 'shipments')
                ->where('imports.total', 1)
                ->where('imports.data.0.id', $shipmentImport->id));

        $this->get('/admin/shipments/csv-imports/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Shipments/CsvImports/Create')
                ->where('context.type', 'shipments')
                ->where('context.template_available', true)
                ->has('context.fields', 11));

        $this->get('/admin/shipments/csv-imports/'.$stockImport->id)->assertNotFound();
    }

    public function test_customer_cannot_access_csv_import_admin_pages(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get('/admin/products/csv-imports')->assertForbidden();
        $this->actingAs($customer)->post('/admin/products/csv-imports', [])->assertForbidden();
    }

    private function makeImport(string $contents, ?User $creator = null, string $type = 'unregistered'): CsvImport
    {
        $creator ??= User::factory()->admin()->create();
        $path = 'tests/'.uniqid('', true).'.csv';
        Storage::disk('csv_imports')->put($path, $contents);

        return CsvImport::create([
            'type' => $type,
            'original_filename' => 'test.csv',
            'disk' => 'csv_imports',
            'path' => $path,
            'file_hash' => hash('sha256', $contents),
            'status' => CsvImportStatus::Uploaded,
            'created_by' => $creator->id,
        ]);
    }
}
