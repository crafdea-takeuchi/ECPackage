<?php

namespace Tests\Feature;

use App\CsvImports\ProductCsvImportDefinition;
use App\Enums\CsvImportStatus;
use App\Exceptions\CsvImportFormatException;
use App\Jobs\ExecuteCsvImport;
use App\Models\Category;
use App\Models\CsvImport;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Services\Csv\CsvImportManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ProductGroup $group;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('csv_imports');
        config(['csv-imports.disk' => 'csv_imports']);

        $this->admin = User::factory()->admin()->create();
        Unit::create(['code' => 'piece', 'name' => '個', 'quantity_scale' => 0, 'status' => 'active']);
        TaxRate::create(['code' => 'standard', 'name' => '標準税率', 'rate' => 10, 'status' => 'active']);

        $parent = Category::create(['name' => 'キッチン', 'sort_order' => 1, 'status' => 'active']);
        $this->category = Category::create(['name' => '調理器具', 'parent_id' => $parent->id, 'sort_order' => 1, 'status' => 'active']);

        $this->group = ProductGroup::create(['name' => 'フライパン', 'status' => 'active']);
        $this->group->definitions()->create([
            'name' => '色',
            'code' => 'color',
            'input_type' => 'select',
            'options' => ['赤', '青'],
            'is_required' => true,
            'sort_order' => 1,
            'status' => 'active',
        ]);
    }

    public function test_product_page_can_open_csv_upload_with_product_type_selected(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/products/csv-imports/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Products/CsvImports/Create')
                ->where('context.type', 'products')
                ->where('context.template_available', true)
                ->has('context.fields', 12)
                ->where('context.fields.0.name', '商品コード')
                ->where('context.fields.0.requirement', '必須')
                ->where('context.fields.10.requirement', '任意')
                ->where('context.fields.10.description', '登録済みの商品分類を完全な階層名で指定します。階層は半角の「>」で表し、複数指定する場合は半角の「|」で区切ります。「>」前後のスペースは省略できます。')
                ->where('context.fields.10.example', 'オーラルケア > 歯ブラシ|各種洗浄/消臭剤 > ハンドソープ'));
    }

    public function test_admin_can_download_product_csv_template(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/products/csv-imports/template');

        $response->assertOk()->assertDownload('products-template.csv');
        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertSame(
            "商品コード,商品名,商品説明,単位コード,登録状態,公開状態,在庫不足時,標準販売単価,税率コード,基本商品,商品分類,属性\n",
            substr($content, 3),
        );
    }

    public function test_valid_product_csv_is_validated_and_imported_with_relations(): void
    {
        $import = $this->makeImport([
            $this->validRow(),
            $this->validRow([
                '商品コード' => 'PAN-002',
                '商品名' => '青いフライパン',
                '登録状態' => '仮登録',
                '公開状態' => '非公開',
                '標準販売単価' => '',
                '属性' => 'color=青',
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

        $confirmed = Product::where('sku', 'PAN-001')->firstOrFail();
        $this->assertSame('single', $confirmed->kind);
        $this->assertSame('confirmed', $confirmed->registration_status);
        $this->assertSame('public', $confirmed->publication_status);
        $this->assertSame('backorder', $confirmed->shortage_policy);
        $this->assertSame($this->group->id, $confirmed->product_group_id);
        $this->assertSame($this->admin->id, $confirmed->created_by);
        $this->assertSame($this->admin->id, $confirmed->confirmed_by);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertDatabaseHas('category_product', [
            'product_id' => $confirmed->id,
            'category_id' => $this->category->id,
        ]);
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $confirmed->id,
            'value' => '赤',
        ]);

        $provisional = Product::where('sku', 'PAN-002')->firstOrFail();
        $this->assertNull($provisional->confirmed_at);
        $this->assertNull($provisional->default_sale_price);
    }

    public function test_product_csv_reports_duplicate_sku_and_invalid_attribute(): void
    {
        $import = $this->makeImport([
            $this->validRow(),
            $this->validRow([
                '商品名' => '重複商品',
                '属性' => 'color=緑',
            ]),
        ]);

        app(CsvImportManager::class)->validate($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Invalid, $import->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'row_number' => 3,
            'column' => '商品コード',
            'message' => 'CSV内で商品コードが重複しています。',
        ]);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'row_number' => 3,
            'column' => '属性',
            'message' => '属性「color」の選択肢が正しくありません。',
        ]);
    }

    public function test_confirmed_product_requires_price_and_required_attributes(): void
    {
        $import = $this->makeImport([
            $this->validRow(['標準販売単価' => '', '属性' => '']),
        ]);

        app(CsvImportManager::class)->validate($import);
        $import->refresh();

        $this->assertSame(CsvImportStatus::Invalid, $import->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'column' => '標準販売単価',
        ]);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'column' => '属性',
            'message' => '必須属性「color」を入力してください。',
        ]);
    }

    public function test_ready_product_csv_can_be_queued_from_detail_page(): void
    {
        Queue::fake();
        $import = $this->makeImport([$this->validRow()]);
        app(CsvImportManager::class)->validate($import);

        $this->actingAs($this->admin)
            ->post('/admin/products/csv-imports/'.$import->id.'/execute')
            ->assertSessionHasNoErrors();

        Queue::assertPushed(ExecuteCsvImport::class, fn (ExecuteCsvImport $job): bool => $job->csvImportId === $import->id);
    }

    #[TestWith(['キッチン>調理器具'])]
    #[TestWith(['キッチン >調理器具'])]
    #[TestWith(['キッチン> 調理器具'])]
    #[TestWith(['キッチン > 調理器具'])]
    public function test_category_separator_accepts_optional_spaces(string $path): void
    {
        $import = $this->makeImport([$this->validRow(['商品分類' => $path])]);
        $manager = app(CsvImportManager::class);

        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);
        $manager->execute($import);

        $this->assertSame(CsvImportStatus::Completed, $import->fresh()->status);
        $this->assertSame([$this->category->id], Product::firstOrFail()->categories()->pluck('categories.id')->all());
    }

    public function test_multiple_root_deep_and_slash_categories_are_imported(): void
    {
        $root = Category::factory()->create(['name' => '各種洗浄/消臭剤']);
        $soap = Category::factory()->create(['name' => 'ハンドソープ', 'parent_id' => $root->id]);
        $leaf = Category::factory()->create(['name' => 'フライパン', 'parent_id' => $this->category->id]);
        $import = $this->makeImport([$this->validRow([
            '商品分類' => 'キッチン|キッチン>調理器具 >フライパン|各種洗浄/消臭剤 > ハンドソープ',
        ])]);
        $manager = app(CsvImportManager::class);

        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);
        $manager->execute($import);

        $this->assertEqualsCanonicalizing(
            [$this->category->parent_id, $leaf->id, $soap->id],
            Product::firstOrFail()->categories()->pluck('categories.id')->all(),
        );
    }

    public function test_empty_categories_imports_an_uncategorized_product(): void
    {
        $import = $this->makeImport([$this->validRow(['商品分類' => ''])]);
        $manager = app(CsvImportManager::class);

        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);
        $manager->execute($import);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('category_product', 0);
    }

    public function test_arrow_is_a_literal_category_name_character_and_not_a_separator(): void
    {
        $category = Category::factory()->create(['name' => '変更前 → 変更後']);
        $import = $this->makeImport([$this->validRow(['商品分類' => '変更前 → 変更後'])]);
        $manager = app(CsvImportManager::class);

        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);
        $manager->execute($import);

        $this->assertSame([$category->id], Product::firstOrFail()->categories()->pluck('categories.id')->all());
    }

    #[TestWith(['未登録分類'])]
    #[TestWith(['キッチン → 調理器具'])]
    public function test_unregistered_and_old_hierarchy_paths_are_rejected(string $path): void
    {
        $import = $this->makeImport([$this->validRow(['商品分類' => $path])]);

        app(CsvImportManager::class)->validate($import);

        $this->assertSame(CsvImportStatus::Invalid, $import->fresh()->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'column' => '商品分類',
            'message' => "有効な商品分類「{$path}」が見つかりません。",
        ]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_inactive_category_or_ancestor_is_rejected(bool $ancestor): void
    {
        Category::findOrFail($ancestor ? $this->category->parent_id : $this->category->id)->update(['status' => 'inactive']);
        $import = $this->makeImport([$this->validRow()]);

        app(CsvImportManager::class)->validate($import);

        $this->assertSame(CsvImportStatus::Invalid, $import->fresh()->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'column' => '商品分類',
            'message' => '有効な商品分類「キッチン > 調理器具」が見つかりません。',
        ]);
    }

    public function test_ambiguous_category_path_is_rejected(): void
    {
        Category::factory()->create(['name' => '調理器具', 'parent_id' => $this->category->parent_id]);
        $import = $this->makeImport([$this->validRow()]);

        app(CsvImportManager::class)->validate($import);

        $this->assertSame(CsvImportStatus::Invalid, $import->fresh()->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'column' => '商品分類',
            'message' => '商品分類「キッチン > 調理器具」が複数あります。管理画面で階層名を一意にしてください。',
        ]);
    }

    #[TestWith(['キッチン > 調理器具|キッチン > 調理器具'])]
    #[TestWith(['キッチン>調理器具|キッチン > 調理器具'])]
    public function test_duplicate_resolved_category_ids_are_rejected(string $paths): void
    {
        $import = $this->makeImport([$this->validRow(['商品分類' => $paths])]);

        app(CsvImportManager::class)->validate($import);

        $this->assertSame(CsvImportStatus::Invalid, $import->fresh()->status);
        $this->assertDatabaseHas('csv_import_errors', [
            'csv_import_id' => $import->id,
            'column' => '商品分類',
            'message' => '同じ商品分類が重複しています。',
        ]);
    }

    #[TestWith(['rename'])]
    #[TestWith(['inactive'])]
    #[TestWith(['inactive_ancestor'])]
    #[TestWith(['delete'])]
    #[TestWith(['ambiguous'])]
    public function test_changed_categories_fail_execution_and_roll_back_all_product_data(string $change): void
    {
        $this->app->instance(ProductCsvImportDefinition::class, new ProductCsvImportDefinition);
        Category::factory()->create(['name' => '特集']);
        $import = $this->makeImport([
            $this->validRow(['商品分類' => '特集']),
            $this->validRow(['商品コード' => 'PAN-002']),
        ]);
        $manager = app(CsvImportManager::class);
        $manager->validate($import);
        $this->assertSame(CsvImportStatus::Ready, $import->fresh()->status);

        match ($change) {
            'rename' => $this->category->update(['name' => '変更済み']),
            'inactive' => $this->category->update(['status' => 'inactive']),
            'inactive_ancestor' => Category::findOrFail($this->category->parent_id)->update(['status' => 'inactive']),
            'delete' => $this->category->delete(),
            'ambiguous' => Category::factory()->create(['name' => '調理器具', 'parent_id' => $this->category->parent_id]),
        };
        $createdSkus = [];
        Product::created(function (Product $product) use (&$createdSkus): void {
            $createdSkus[] = $product->sku;
        });

        try {
            $manager->execute($import);
            $this->fail('商品分類を解決できない取込は失敗する必要があります。');
        } catch (CsvImportFormatException $exception) {
            $this->assertStringContainsString('商品分類の再検証に失敗しました', $exception->getMessage());
        }

        $this->assertSame(['PAN-001'], $createdSkus);
        $this->assertSame(CsvImportStatus::Failed, $import->fresh()->status);
        $this->assertSame(0, $import->fresh()->imported_rows);
        $this->assertStringContainsString('商品分類の再検証に失敗しました', $import->fresh()->system_error);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('category_product', 0);
        $this->assertDatabaseCount('product_attribute_values', 0);
    }

    public function test_execution_rejects_duplicate_category_ids_even_if_marked_ready(): void
    {
        $import = $this->makeImport([$this->validRow(['商品分類' => 'キッチン>調理器具|キッチン >調理器具'])]);
        $import->update(['status' => CsvImportStatus::Ready]);

        try {
            app(CsvImportManager::class)->execute($import);
            $this->fail('分類IDが重複する取込は失敗する必要があります。');
        } catch (CsvImportFormatException $exception) {
            $this->assertStringContainsString('同じ商品分類が重複しています。', $exception->getMessage());
        }

        $this->assertSame(CsvImportStatus::Failed, $import->fresh()->status);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('category_product', 0);
    }

    /** @return array<string, string> */
    private function validRow(array $overrides = []): array
    {
        return array_replace([
            '商品コード' => 'PAN-001',
            '商品名' => '鉄フライパン',
            '商品説明' => 'IH対応',
            '単位コード' => 'piece',
            '登録状態' => '本登録',
            '公開状態' => '公開',
            '在庫不足時' => '取り寄せ',
            '標準販売単価' => '3000',
            '税率コード' => 'standard',
            '基本商品' => 'フライパン',
            '商品分類' => 'キッチン > 調理器具',
            '属性' => 'color=赤',
        ], $overrides);
    }

    /** @param list<array<string, string>> $rows */
    private function makeImport(array $rows): CsvImport
    {
        $headers = app(ProductCsvImportDefinition::class)->requiredHeaders();
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $header): string => $row[$header] ?? '', $headers), ',', '"', '');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        $path = 'tests/products-'.uniqid('', true).'.csv';
        Storage::disk('csv_imports')->put($path, $contents);

        return CsvImport::create([
            'type' => 'products',
            'original_filename' => 'products.csv',
            'disk' => 'csv_imports',
            'path' => $path,
            'file_hash' => hash('sha256', $contents),
            'status' => CsvImportStatus::Uploaded,
            'created_by' => $this->admin->id,
        ]);
    }
}
