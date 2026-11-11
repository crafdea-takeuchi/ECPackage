<?php

namespace App\CsvImports;

use App\Contracts\CsvImportDefinition;
use App\Exceptions\CsvImportFormatException;
use App\Models\Category;
use App\Models\CsvImport;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Collection;

class ProductCsvImportDefinition implements CsvImportDefinition
{
    private const SKU = '商品コード';

    private const NAME = '商品名';

    private const DESCRIPTION = '商品説明';

    private const UNIT = '単位コード';

    private const REGISTRATION = '登録状態';

    private const PUBLICATION = '公開状態';

    private const SHORTAGE = '在庫不足時';

    private const PRICE = '標準販売単価';

    private const TAX = '税率コード';

    private const GROUP = '基本商品';

    private const CATEGORIES = '商品分類';

    private const ATTRIBUTES = '属性';

    /** @var array<string, true>|null */
    private ?array $existingSkus = null;

    /** @var array<string, true> */
    private array $seenSkus = [];

    private ?Collection $units = null;

    private ?Collection $taxRates = null;

    private ?Collection $groups = null;

    private ?Collection $categoryPaths = null;

    public function type(): string
    {
        return 'products';
    }

    /** @return list<string> */
    public function requiredHeaders(): array
    {
        return [
            self::SKU,
            self::NAME,
            self::DESCRIPTION,
            self::UNIT,
            self::REGISTRATION,
            self::PUBLICATION,
            self::SHORTAGE,
            self::PRICE,
            self::TAX,
            self::GROUP,
            self::CATEGORIES,
            self::ATTRIBUTES,
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $sku = strtoupper($this->value($row, self::SKU));
        $name = $this->value($row, self::NAME);
        $description = $this->value($row, self::DESCRIPTION);
        $unitCode = $this->value($row, self::UNIT);
        $registration = $this->registrationStatus($this->value($row, self::REGISTRATION));
        $publication = $this->publicationStatus($this->value($row, self::PUBLICATION));
        $shortage = $this->shortagePolicy($this->value($row, self::SHORTAGE));
        $price = $this->value($row, self::PRICE);
        $taxCode = $this->value($row, self::TAX);
        $groupName = $this->value($row, self::GROUP);

        if ($sku === '') {
            $this->error($errors, self::SKU, '商品コードを入力してください。');
        } elseif (mb_strlen($sku) > 50 || ! preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $sku)) {
            $this->error($errors, self::SKU, '商品コードは50文字以内の半角英数字・ハイフン・アンダースコアで入力してください。');
        } elseif (isset($this->existingSkuMap()[$sku])) {
            $this->error($errors, self::SKU, 'この商品コードはすでに登録されています。');
        } elseif (isset($this->seenSkus[$sku])) {
            $this->error($errors, self::SKU, 'CSV内で商品コードが重複しています。');
        }
        if ($sku !== '') {
            $this->seenSkus[$sku] = true;
        }

        if ($name === '') {
            $this->error($errors, self::NAME, '商品名を入力してください。');
        } elseif (mb_strlen($name) > 200) {
            $this->error($errors, self::NAME, '商品名は200文字以内にしてください。');
        }

        if (mb_strlen($description) > 5000) {
            $this->error($errors, self::DESCRIPTION, '商品説明は5000文字以内にしてください。');
        }

        if ($unitCode === '') {
            $this->error($errors, self::UNIT, '単位コードを入力してください。');
        } elseif (! $this->unitMap()->has($unitCode)) {
            $this->error($errors, self::UNIT, '有効な単位コードが見つかりません。');
        }

        if ($registration === null) {
            $this->error($errors, self::REGISTRATION, '「仮登録」または「本登録」を入力してください。');
        }

        if ($publication === null) {
            $this->error($errors, self::PUBLICATION, '「非公開」または「公開」を入力してください。');
        } elseif ($registration === 'provisional' && $publication === 'public') {
            $this->error($errors, self::PUBLICATION, '仮登録の商品は公開できません。');
        }

        if ($shortage === null) {
            $this->error($errors, self::SHORTAGE, '「販売停止」または「取り寄せ」を入力してください。');
        }

        if ($price !== '' && ! preg_match('/^\d{1,15}(\.\d{1,4})?$/', $price)) {
            $this->error($errors, self::PRICE, '標準販売単価は0以上、整数15桁・小数4桁以内で入力してください。');
        } elseif ($registration === 'confirmed' && $price === '') {
            $this->error($errors, self::PRICE, '本登録には標準販売単価が必要です。');
        }

        if ($taxCode !== '' && ! $this->taxRateMap()->has($taxCode)) {
            $this->error($errors, self::TAX, '有効な税率コードが見つかりません。');
        }

        $group = $this->resolveGroup($groupName, $errors);
        $this->resolveCategoryIds($this->value($row, self::CATEGORIES), $errors);
        $this->validateAttributes($this->value($row, self::ATTRIBUTES), $group, $registration, $errors);

        return $errors;
    }

    public function importRows(array $rows, User $user, CsvImport $import): int
    {
        $nodes = Category::query()->orderBy('id')->lockForUpdate()->get();
        $this->categoryPaths = collect(Category::treeOptions($nodes))
            ->where('effective_active', true)
            ->groupBy('path');

        foreach ($rows as $row) {
            $errors = [];
            $categoryIds = $this->resolveCategoryIds($this->value($row, self::CATEGORIES), $errors);

            if ($errors !== []) {
                $sku = $this->value($row, self::SKU);
                throw new CsvImportFormatException("商品分類の再検証に失敗しました（商品コード: {$sku}）: ".implode(' ', array_column($errors, 'message')));
            }

            $group = $this->groupForImport($this->value($row, self::GROUP));
            $registration = $this->registrationStatus($this->value($row, self::REGISTRATION));

            $product = new Product([
                'sku' => strtoupper($this->value($row, self::SKU)),
                'name' => $this->value($row, self::NAME),
                'description' => $this->nullable($this->value($row, self::DESCRIPTION)),
                'kind' => 'single',
                'product_group_id' => $group?->id,
                'unit_id' => $this->unitMap()->get($this->value($row, self::UNIT)),
                'registration_status' => $registration,
                'publication_status' => $this->publicationStatus($this->value($row, self::PUBLICATION)),
                'shortage_policy' => $this->shortagePolicy($this->value($row, self::SHORTAGE)),
                'default_sale_price' => $this->nullable($this->value($row, self::PRICE)),
                'tax_rate_id' => $this->taxRateMap()->get($this->value($row, self::TAX)),
            ]);
            $product->created_by = $user->id;
            $product->confirmed_by = $registration === 'confirmed' ? $user->id : null;
            $product->confirmed_at = $registration === 'confirmed' ? now() : null;
            $product->save();

            $product->categories()->sync($categoryIds);

            if ($group !== null) {
                $definitions = $group->definitions->where('status', 'active')->keyBy('code');
                foreach ($this->parseAttributes($this->value($row, self::ATTRIBUTES)) as $code => $value) {
                    $product->attributeValues()->create([
                        'product_attribute_definition_id' => $definitions->get($code)->id,
                        'value' => $value,
                    ]);
                }
            }
        }

        return count($rows);
    }

    public function templateRows(): array
    {
        return [];
    }

    /** @return array<string, true> */
    private function existingSkuMap(): array
    {
        return $this->existingSkus ??= Product::query()
            ->pluck('sku')
            ->mapWithKeys(fn (string $sku): array => [strtoupper($sku) => true])
            ->all();
    }

    private function unitMap(): Collection
    {
        return $this->units ??= Unit::query()->where('status', 'active')->pluck('id', 'code');
    }

    private function taxRateMap(): Collection
    {
        return $this->taxRates ??= TaxRate::query()->where('status', 'active')->pluck('id', 'code');
    }

    private function groupMap(): Collection
    {
        return $this->groups ??= ProductGroup::query()
            ->where('status', 'active')
            ->with(['definitions' => fn ($query) => $query->where('status', 'active')])
            ->get()
            ->groupBy('name');
    }

    private function categoryPaths(): Collection
    {
        return $this->categoryPaths ??= collect(Category::treeOptions())
            ->where('effective_active', true)
            ->groupBy('path');
    }

    /** @param list<array{column: string|null, message: string}> $errors */
    private function resolveGroup(string $name, array &$errors): ?ProductGroup
    {
        if ($name === '') {
            return null;
        }

        $matches = $this->groupMap()->get($name, collect());

        if ($matches->isEmpty()) {
            $this->error($errors, self::GROUP, '有効な基本商品が見つかりません。');

            return null;
        }

        if ($matches->count() > 1) {
            $this->error($errors, self::GROUP, '同名の基本商品が複数あります。管理画面で名前を一意にしてください。');

            return null;
        }

        return $matches->first();
    }

    private function groupForImport(string $name): ?ProductGroup
    {
        if ($name === '') {
            return null;
        }

        return $this->groupMap()->get($name)->first();
    }

    /**
     * @param  list<array{column: string|null, message: string}>  $errors
     * @return list<int>
     */
    private function resolveCategoryIds(string $value, array &$errors): array
    {
        $ids = [];

        foreach ($this->split($value) as $path) {
            $normalizedPath = implode(' > ', array_map(fn (string $name): string => trim($name, ' '), explode('>', $path)));
            $matches = $this->categoryPaths()->get($normalizedPath, collect());

            if ($matches->isEmpty()) {
                $this->error($errors, self::CATEGORIES, "有効な商品分類「{$path}」が見つかりません。");
            } elseif ($matches->count() > 1) {
                $this->error($errors, self::CATEGORIES, "商品分類「{$path}」が複数あります。管理画面で階層名を一意にしてください。");
            } else {
                $id = $matches->first()['id'];

                if (in_array($id, $ids, true)) {
                    $this->error($errors, self::CATEGORIES, '同じ商品分類が重複しています。');
                } else {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /** @param list<array{column: string|null, message: string}> $errors */
    private function validateAttributes(string $value, ?ProductGroup $group, ?string $registration, array &$errors): void
    {
        if ($value === '') {
            if ($group !== null && $registration === 'confirmed') {
                foreach ($group->definitions->where('is_required', true) as $definition) {
                    $this->error($errors, self::ATTRIBUTES, "必須属性「{$definition->code}」を入力してください。");
                }
            }

            return;
        }

        if ($group === null) {
            $this->error($errors, self::ATTRIBUTES, '属性を入力する場合は基本商品を指定してください。');

            return;
        }

        $attributes = $this->parseAttributes($value, $errors);
        $definitions = $group->definitions->where('status', 'active')->keyBy('code');

        foreach ($attributes as $code => $attributeValue) {
            $definition = $definitions->get($code);

            if ($definition === null) {
                $this->error($errors, self::ATTRIBUTES, "基本商品に属性コード「{$code}」がありません。");

                continue;
            }

            if (mb_strlen($attributeValue) > 255) {
                $this->error($errors, self::ATTRIBUTES, "属性「{$code}」は255文字以内にしてください。");
            }
            if ($definition->input_type === 'number' && ! preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $attributeValue)) {
                $this->error($errors, self::ATTRIBUTES, "属性「{$code}」は整数15桁・小数6桁以内の数値で入力してください。");
            }
            if ($definition->input_type === 'select' && ! in_array($attributeValue, $definition->options ?? [], true)) {
                $this->error($errors, self::ATTRIBUTES, "属性「{$code}」の選択肢が正しくありません。");
            }
        }

        if ($registration === 'confirmed') {
            foreach ($definitions->where('is_required', true) as $definition) {
                if (! isset($attributes[$definition->code])) {
                    $this->error($errors, self::ATTRIBUTES, "必須属性「{$definition->code}」を入力してください。");
                }
            }
        }
    }

    /**
     * @param  list<array{column: string|null, message: string}>|null  $errors
     * @return array<string, string>
     */
    private function parseAttributes(string $value, ?array &$errors = null): array
    {
        $attributes = [];

        foreach ($this->split($value) as $pair) {
            if (! str_contains($pair, '=')) {
                if ($errors !== null) {
                    $this->error($errors, self::ATTRIBUTES, "属性「{$pair}」は 属性コード=値 の形式で入力してください。");
                }

                continue;
            }

            [$code, $attributeValue] = array_map('trim', explode('=', $pair, 2));

            if ($code === '' || $attributeValue === '') {
                if ($errors !== null) {
                    $this->error($errors, self::ATTRIBUTES, '属性コードと値の両方を入力してください。');
                }

                continue;
            }

            if (array_key_exists($code, $attributes)) {
                if ($errors !== null) {
                    $this->error($errors, self::ATTRIBUTES, "属性コード「{$code}」が重複しています。");
                }

                continue;
            }

            $attributes[$code] = $attributeValue;
        }

        return $attributes;
    }

    /** @return list<string> */
    private function split(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode('|', $value)),
            fn (string $item): bool => $item !== '',
        ));
    }

    private function registrationStatus(string $value): ?string
    {
        return ['仮登録' => 'provisional', 'provisional' => 'provisional', '本登録' => 'confirmed', 'confirmed' => 'confirmed'][$value] ?? null;
    }

    private function publicationStatus(string $value): ?string
    {
        return ['非公開' => 'private', 'private' => 'private', '公開' => 'public', 'public' => 'public'][$value] ?? null;
    }

    private function shortagePolicy(string $value): ?string
    {
        return ['販売停止' => 'reject', 'reject' => 'reject', '取り寄せ' => 'backorder', 'backorder' => 'backorder'][$value] ?? null;
    }

    private function value(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /** @param list<array{column: string|null, message: string}> $errors */
    private function error(array &$errors, string $column, string $message): void
    {
        $errors[] = ['column' => $column, 'message' => $message];
    }
}
