<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RegisteredProductSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([UnitSeeder::class, TaxRateSeeder::class]);

        $actor = User::query()
            ->where('role', 'admin')
            ->whereNotNull('email_verified_at')
            ->oldest('id')
            ->first();

        if (! $actor) {
            throw new RuntimeException('RegisteredProductSeeder requires a verified administrator. Run DatabaseSeeder first.');
        }

        DB::transaction(function () use ($actor): void {
            $categories = $this->categories();
            $groups = $this->groups();
            $unit = Unit::query()->where('code', 'piece')->firstOrFail();
            $taxRate = TaxRate::query()->where('code', 'standard')->firstOrFail();

            $this->product($actor, [
                'sku' => 'P-01M6397RX35HTC2CY1Y6HRWPEQ',
                'name' => 'CAMUGAKI(カムガキ)',
                'group' => $groups['camugaki'],
                'unit_id' => $unit->id,
                'tax_rate_id' => $taxRate->id,
                'price' => '470.0000',
                'category_id' => $categories['gum']->id,
                'values' => ['pieces' => '20', 'flavor' => 'レモンフレーバー'],
            ]);
            $this->product($actor, [
                'sku' => 'P-01M639J04G252AVD9APF5RKH21',
                'name' => 'Check-Up歯ブラシ',
                'group' => $groups['check-up'],
                'unit_id' => $unit->id,
                'tax_rate_id' => $taxRate->id,
                'price' => '2880.0000',
                'category_id' => $categories['toothbrush']->id,
                'values' => ['size' => 'S', 'count' => '12'],
            ]);
            $this->product($actor, [
                'sku' => 'P-01M639X17PDM195NTBTTD0N11J',
                'name' => 'クリーン&クリーンF1薬用ハンドウオッシュ',
                'group' => $groups['hand-wash'],
                'unit_id' => $unit->id,
                'tax_rate_id' => $taxRate->id,
                'price' => '4580.0000',
                'category_id' => $categories['hand-soap']->id,
                'values' => [],
            ]);
        }, 3);
    }

    /** @return array<string, Category> */
    private function categories(): array
    {
        $oralCare = $this->category('オーラルケア', null, 0);
        $cleaning = $this->category('各種洗浄/消臭剤', null, 0);

        return [
            'gum' => $this->category('ガム/グミ等食品', $oralCare, 10),
            'toothbrush' => $this->category('歯ブラシ', $oralCare, 10),
            'hand-soap' => $this->category('ハンドソープ', $cleaning, 10),
        ];
    }

    private function category(string $name, ?Category $parent, int $sortOrder): Category
    {
        return Category::query()->updateOrCreate(
            ['name' => $name, 'parent_id' => $parent?->id],
            ['sort_order' => $sortOrder, 'status' => 'active'],
        );
    }

    /** @return array<string, ProductGroup> */
    private function groups(): array
    {
        return [
            'camugaki' => $this->group(
                'CAMUGAKI(カムガキ)',
                implode("\n", [
                    '歯みがきのタブレット！',
                    '',
                    '口に１粒含んで嚙み砕き(約10秒)、泡を口全体になじませて･･･マウスウォッシュとしても、歯みがきとして使えます！',
                    '',
                    '携帯タイプなので、外出先やランチ後などいつでも手軽に使えて便利✌　非常用としても役立ちます。',
                    '',
                    '美白効果もあり、レモンフレーバーでお口も爽やか！',
                ]),
                [
                    ['code' => 'pieces', 'name' => '粒'],
                    ['code' => 'flavor', 'name' => '味'],
                ],
            ),
            'check-up' => $this->group(
                'Check-Up歯ブラシ',
                implode("\n", [
                    '〈LION〉',
                    '',
                    '歯垢をしっかり落とす',
                    'お口の奥まで無理なく毛先が届きます。',
                    '毛の硬さ：S / M',
                    '毛先のタイプ：ラウンド･スーパーテーパード（超極細毛）',
                ]),
                [
                    ['code' => 'size', 'name' => 'サイズ'],
                    ['code' => 'count', 'name' => '個数'],
                ],
            ),
            'hand-wash' => $this->group(
                'クリーン&クリーンF1薬用ハンドウオッシュ',
                implode("\n", [
                    '〈花王〉',
                    '',
                    '',
                    '泡持ちと泡切れを改良',
                    'へたりにくい「ながもっち泡」で手指のすみずみまでよく洗える。',
                    '素早くすすげるので節水にも！',
                    '汚れもバイ菌もニオイもすっきり。',
                ]),
                [],
            ),
        ];
    }

    /** @param array<int, array{code: string, name: string}> $definitions */
    private function group(string $name, string $description, array $definitions): ProductGroup
    {
        $group = ProductGroup::query()->updateOrCreate(
            ['name' => $name],
            ['description' => $description, 'status' => 'active'],
        );

        foreach ($definitions as $sortOrder => $definition) {
            $group->definitions()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'input_type' => 'text',
                    'options' => [],
                    'is_required' => false,
                    'sort_order' => $sortOrder,
                    'status' => 'active',
                ],
            );
        }

        return $group->fresh('definitions');
    }

    /**
     * @param array{
     *     sku: string,
     *     name: string,
     *     group: ProductGroup,
     *     unit_id: int,
     *     tax_rate_id: int,
     *     price: string,
     *     category_id: int,
     *     values: array<string, string>
     * } $data
     */
    private function product(User $actor, array $data): Product
    {
        $product = Product::query()->firstOrNew(['sku' => $data['sku']]);
        $product->fill([
            'name' => $data['name'],
            'kind' => 'single',
            'product_group_id' => $data['group']->id,
            'unit_id' => $data['unit_id'],
            'tax_rate_id' => $data['tax_rate_id'],
            'registration_status' => 'confirmed',
            'publication_status' => 'public',
            'shortage_policy' => 'reject',
            'default_sale_price' => $data['price'],
        ]);
        $product->created_by ??= $actor->id;
        $product->confirmed_by = $actor->id;
        $product->confirmed_at ??= now();
        $product->save();
        $product->categories()->sync([$data['category_id']]);

        $definitions = $data['group']->definitions->keyBy('code');
        $retainedDefinitionIds = [];
        foreach ($data['values'] as $code => $value) {
            $definition = $definitions->get($code);
            if (! $definition) {
                throw new RuntimeException("Product attribute definition is missing: {$code}");
            }

            $product->attributeValues()->updateOrCreate(
                ['product_attribute_definition_id' => $definition->id],
                ['value' => $value],
            );
            $retainedDefinitionIds[] = $definition->id;
        }

        $staleValues = $product->attributeValues();
        if ($retainedDefinitionIds !== []) {
            $staleValues->whereNotIn('product_attribute_definition_id', $retainedDefinitionIds);
        }
        $staleValues->delete();

        return $product;
    }
}
