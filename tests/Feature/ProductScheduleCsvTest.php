<?php

namespace Tests\Feature;

use App\Actions\Products\ProductSchedule;
use App\CsvImports\ProductCsvImportDefinition;
use App\Enums\CsvImportStatus;
use App\Jobs\ExecuteCsvImport;
use App\Models\CsvImport;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\Csv\CsvImportManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductScheduleCsvTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function row(array $overrides = []): array
    {
        return array_replace(array_fill_keys(app(ProductCsvImportDefinition::class)->requiredHeaders(), ''), [
            '商品コード' => 'SCHEDULE-1', '商品名' => '期間商品', '単位コード' => 'piece',
            '登録状態' => '本登録', '公開状態' => '公開', '標準販売単価' => '100', '在庫不足時' => '取り寄せ',
        ], $overrides);
    }

    private function makeImport(array $rows): CsvImport
    {
        Storage::fake('csv_imports');
        Unit::factory()->create(['code' => 'piece']);
        $admin = User::factory()->admin()->create();
        $stream = fopen('php://temp', 'r+');
        $headers = app(ProductCsvImportDefinition::class)->requiredHeaders();
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($header) => $row[$header] ?? '', $headers), ',', '"', '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        Storage::disk('csv_imports')->put('schedules.csv', $contents);

        return CsvImport::create(['type' => 'products', 'original_filename' => 'schedules.csv', 'disk' => 'csv_imports', 'path' => 'schedules.csv',
            'file_hash' => hash('sha256', $contents), 'status' => CsvImportStatus::Uploaded, 'created_by' => $admin->id]);
    }

    public function test_csv_stores_tokyo_datetimes_as_utc_and_handles_empty_limits(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 00:00:00', 'UTC'));
        $import = $this->makeImport([$this->row([
            '公開開始日時' => '2026-11-18 08:00:00', '公開終了日時' => '2026-11-19 00:00:00',
            '販売開始日時' => '2026-11-18 09:00:00', '販売終了日時' => '2026-11-18 10:00:00',
        ]), $this->row(['商品コード' => 'SCHEDULE-2'])]);
        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);
        $manager->execute($import);
        $this->assertSame(CsvImportStatus::Completed, $import->fresh()->status);
        $first = Product::where('sku', 'SCHEDULE-1')->firstOrFail();
        $this->assertSame('2026-11-17 23:00:00', $first->publication_starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('on_sale', $first->sale_state);
        $this->travel(1)->hours();
        $this->assertSame('sale_ended', $first->sale_state);
        $second = Product::where('sku', 'SCHEDULE-2')->firstOrFail();
        foreach (array_keys(ProductSchedule::FIELDS) as $field) {
            $this->assertNull($second->$field);
        }
    }

    #[TestWith([['公開開始日時' => '2026-02-30 00:00:00'], '公開開始日時'])]
    #[TestWith([['販売開始日時' => 'not-a-date'], '販売開始日時'])]
    #[TestWith([['販売開始日時' => '2026-11-18 10:00:00', '販売終了日時' => '2026-11-18 10:00:00'], '販売終了日時'])]
    #[TestWith([['公開終了日時' => '2026-11-18 10:00:00', '販売終了日時' => '2026-11-18 11:00:00'], '販売終了日時'])]
    public function test_one_bad_schedule_prevents_all_registration_and_retains_row_errors(array $fields, string $column): void
    {
        $import = $this->makeImport([$this->row(), $this->row(['商品コード' => 'SCHEDULE-2', ...$fields])]);
        app(CsvImportManager::class)->validate($import);
        $this->assertSame(CsvImportStatus::Invalid, $import->fresh()->status);
        $this->assertTrue($import->errors()->where('row_number', 3)->where('column', $column)->exists());
        $this->assertDatabaseCount('products', 0);
    }

    public function test_runtime_failure_rolls_back_all_products_and_retains_failed_history(): void
    {
        $import = $this->makeImport([$this->row(), $this->row(['商品コード' => 'SCHEDULE-2'])]);
        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        Product::creating(function (Product $product): void {
            if ($product->sku === 'SCHEDULE-2') {
                throw new \RuntimeException('テスト登録失敗');
            }
        });
        try {
            $manager->execute($import);
            $this->fail('実登録の失敗は全件をロールバックする必要があります。');
        } catch (\RuntimeException $exception) {
            $this->assertSame('テスト登録失敗', $exception->getMessage());
            (new ExecuteCsvImport($import->id))->failed($exception);
        }
        $this->assertSame(CsvImportStatus::Failed, $import->fresh()->status);
        $this->assertDatabaseCount('products', 0);
        $this->assertNotNull($import->fresh()->system_error);
    }
}
