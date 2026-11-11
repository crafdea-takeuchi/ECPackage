<?php

namespace Tests\Feature;

use App\CsvImports\ShipmentCsvImportDefinition;
use App\Enums\CsvImportStatus;
use App\Models\CsvImport;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\Unit;
use App\Models\User;
use App\Services\Csv\CsvImportManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShipmentCsvImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('csv_imports');
        config(['csv-imports.disk' => 'csv_imports']);
    }

    public function test_admin_can_open_upload_page_and_download_shipment_template(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/shipments/csv-imports/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Shipments/CsvImports/Create')
                ->where('context.type', 'shipments')
                ->where('context.template_available', true)
                ->has('context.fields', 11)
                ->where('context.fields.0.name', '発送番号')
                ->where('context.fields.0.requirement', '必須')
                ->has('context.help', 5));

        $response = $this->get('/admin/shipments/csv-imports/template');

        $response->assertOk()->assertDownload('shipments-template.csv');
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('発送番号,注文番号,発送ステータス,商品コード,発送数量,配送会社,追跡番号,発送予定日,発送日,取消理由,備考', $content);
    }

    public function test_valid_csv_groups_product_rows_into_one_draft_shipment(): void
    {
        [$admin, $order, $firstLine, $secondLine] = $this->shippingContext();
        $import = $this->makeImport($admin, [
            $this->validRow($order, $firstLine),
            $this->validRow($order, $secondLine, ['発送数量' => '1']),
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
        $shipment = Shipment::where('shipment_number', 'SHP-20261109-001')->firstOrFail();
        $this->assertSame('draft', $shipment->status);
        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame($admin->id, $shipment->created_by);
        $this->assertSame('ヤマト運輸', $shipment->carrier);
        $this->assertSame('午前中指定', $shipment->notes);
        $this->assertSame(2, $shipment->lines()->count());
        $this->assertDatabaseHas('shipment_lines', [
            'shipment_id' => $shipment->id,
            'order_line_id' => $firstLine->id,
            'quantity' => '2.0000',
        ]);
        $this->assertDatabaseHas('shipment_lines', [
            'shipment_id' => $shipment->id,
            'order_line_id' => $secondLine->id,
            'quantity' => '1.0000',
        ]);
        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_shipped_csv_uses_domain_action_and_automatically_completes_order(): void
    {
        [$admin, $order, $firstLine, $secondLine] = $this->shippingContext();
        $import = $this->makeImport($admin, [
            $this->validRow($order, $firstLine, [
                '発送ステータス' => '発送済み',
                '発送数量' => '3',
                '発送日' => '2026-11-09',
            ]),
            $this->validRow($order, $secondLine, [
                '発送ステータス' => '発送済み',
                '発送数量' => '2',
                '発送日' => '2026-11-09',
            ]),
        ]);

        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);

        $manager->execute($import);

        $shipment = Shipment::where('shipment_number', 'SHP-20261109-001')->firstOrFail();
        $this->assertSame('shipped', $shipment->status);
        $this->assertSame($admin->id, $shipment->shipped_by);
        $this->assertSame('2026-11-09', $shipment->shipped_on->format('Y-m-d'));
        $this->assertSame('shipped', $order->fresh()->status);
    }

    public function test_existing_draft_is_updated_by_shipment_number_and_replaces_lines(): void
    {
        [$admin, $order, $firstLine, $secondLine] = $this->shippingContext();
        $shipment = Shipment::factory()->create([
            'shipment_number' => 'SHP-20261109-001',
            'order_id' => $order->id,
            'status' => 'draft',
            'created_by' => $admin->id,
            'lock_version' => 2,
        ]);
        ShipmentLine::factory()->create([
            'shipment_id' => $shipment->id,
            'order_line_id' => $firstLine->id,
            'quantity' => 3,
        ]);
        $import = $this->makeImport($admin, [
            $this->validRow($order, $secondLine, [
                '発送数量' => '2',
                '追跡番号' => 'TRACK-UPDATED',
            ]),
        ]);

        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);

        $manager->execute($import);

        $shipment->refresh();
        $this->assertSame(3, $shipment->lock_version);
        $this->assertSame('TRACK-UPDATED', $shipment->tracking_number);
        $this->assertDatabaseMissing('shipment_lines', [
            'shipment_id' => $shipment->id,
            'order_line_id' => $firstLine->id,
        ]);
        $this->assertDatabaseHas('shipment_lines', [
            'shipment_id' => $shipment->id,
            'order_line_id' => $secondLine->id,
            'quantity' => '2.0000',
        ]);
    }

    public function test_existing_draft_can_be_cancelled_and_its_reservation_is_released(): void
    {
        [$admin, $order, $firstLine] = $this->shippingContext();
        $shipment = Shipment::factory()->create([
            'shipment_number' => 'SHP-20261109-001',
            'order_id' => $order->id,
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        ShipmentLine::factory()->create([
            'shipment_id' => $shipment->id,
            'order_line_id' => $firstLine->id,
            'quantity' => 3,
        ]);
        $import = $this->makeImport($admin, [
            $this->validRow($order, $firstLine, [
                '発送ステータス' => '取消',
                '商品コード' => '',
                '発送数量' => '',
                '取消理由' => '配送先変更のため作り直し',
            ]),
        ]);

        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);

        $manager->execute($import);

        $shipment->refresh();
        $this->assertSame('cancelled', $shipment->status);
        $this->assertSame($admin->id, $shipment->cancelled_by);
        $this->assertSame('配送先変更のため作り直し', $shipment->cancellation_reason);
    }

    public function test_csv_rejects_cumulative_over_shipment_and_finalized_shipment_changes(): void
    {
        [$admin, $order, $firstLine] = $this->shippingContext();
        $finalized = Shipment::factory()->create([
            'shipment_number' => 'SHP-FINAL',
            'order_id' => $order->id,
            'status' => 'shipped',
            'carrier' => 'ヤマト運輸',
            'shipped_on' => '2026-11-08',
            'created_by' => $admin->id,
            'shipped_by' => $admin->id,
        ]);
        ShipmentLine::factory()->create([
            'shipment_id' => $finalized->id,
            'order_line_id' => $firstLine->id,
            'quantity' => 1,
        ]);
        $import = $this->makeImport($admin, [
            $this->validRow($order, $firstLine, [
                '発送番号' => 'SHP-FINAL',
                '発送数量' => '1',
            ]),
            $this->validRow($order, $firstLine, [
                '発送番号' => 'SHP-NEW-001',
                '発送数量' => '2',
            ]),
            $this->validRow($order, $firstLine, [
                '発送番号' => 'SHP-NEW-002',
                '発送数量' => '1',
            ]),
        ]);

        app(CsvImportManager::class)->validate($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Invalid, $import->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'row_number' => 2,
            'column' => '発送ステータス',
            'message' => '発送済みまたは取消済みの発送はCSVで変更できません。',
        ]);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'row_number' => 4,
            'column' => '発送数量',
        ]);
    }

    /** @return array{User, Order, OrderLine, OrderLine} */
    private function shippingContext(): array
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $unit = Unit::factory()->create([
            'code' => 'piece-shipment-csv',
            'name' => '個',
            'quantity_scale' => 0,
            'status' => 'active',
        ]);
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
        $order = Order::factory()->create([
            'order_number' => 'ORD-20261109-001',
            'customer_id' => $customer->id,
            'status' => 'confirmed',
            'created_by' => $admin->id,
        ]);
        $firstLine = OrderLine::factory()->create([
            'order_id' => $order->id,
            'line_number' => 1,
            'product_id' => $firstProduct->id,
            'product_sku' => $firstProduct->sku,
            'product_name' => '鉄フライパン 26cm',
            'unit_name' => '個',
            'quantity' => 3,
        ]);
        $secondLine = OrderLine::factory()->create([
            'order_id' => $order->id,
            'line_number' => 2,
            'product_id' => $secondProduct->id,
            'product_sku' => $secondProduct->sku,
            'product_name' => '鉄フライパン 28cm',
            'unit_name' => '個',
            'quantity' => 2,
        ]);

        return [$admin, $order, $firstLine, $secondLine];
    }

    /** @return array<string, string> */
    private function validRow(Order $order, OrderLine $line, array $overrides = []): array
    {
        return array_replace([
            '発送番号' => 'SHP-20261109-001',
            '注文番号' => $order->order_number,
            '発送ステータス' => '発送準備中',
            '商品コード' => $line->product_sku,
            '発送数量' => '2',
            '配送会社' => 'ヤマト運輸',
            '追跡番号' => 'TRACK-001',
            '発送予定日' => '2026-11-10',
            '発送日' => '',
            '取消理由' => '',
            '備考' => '午前中指定',
        ], $overrides);
    }

    /** @param list<array<string, string>> $rows */
    private function makeImport(User $admin, array $rows): CsvImport
    {
        $headers = app(ShipmentCsvImportDefinition::class)->requiredHeaders();
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $header): string => $row[$header] ?? '', $headers), ',', '"', '');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        $path = 'tests/shipments-'.uniqid('', true).'.csv';
        Storage::disk('csv_imports')->put($path, $contents);

        return CsvImport::create([
            'type' => 'shipments',
            'original_filename' => 'shipments.csv',
            'disk' => 'csv_imports',
            'path' => $path,
            'file_hash' => hash('sha256', $contents),
            'status' => CsvImportStatus::Uploaded,
            'created_by' => $admin->id,
        ]);
    }
}
