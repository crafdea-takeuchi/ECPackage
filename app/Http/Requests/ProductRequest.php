<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->boolean('images_present')) {
            $this->merge([
                'images' => $this->input('images', []),
                'category_ids' => $this->input('category_ids', []),
                'attribute_values' => $this->input('attribute_values', []),
            ]);
        }
        $components = collect($this->input('bundle_components', []))->map(function ($component): mixed {
            if (! is_array($component)) {
                return $component;
            }
            $component['quantity'] = trim((string) ($component['quantity'] ?? ''));

            return $component;
        })->all();
        $this->merge([
            'kind' => $this->input('kind', $this->route('product')?->kind ?? 'single'),
            'bundle_components' => $components,
            'shortage_policy' => $this->input('shortage_policy', $this->route('product')?->shortage_policy ?? 'reject'),
        ]);
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => strtoupper(trim($this->input('sku'))) ?: null]);
        }
    }

    public function rules(): array
    {
        return [
            'images_present' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'list', 'max:5'],
            'images.*' => ['array:id,file,alt_text'],
            'images.*.id' => ['nullable', 'integer', Rule::exists('product_images', 'id')->where('product_id', $this->route('product')?->id ?? 0)],
            'images.*.file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            'images.*.alt_text' => ['nullable', 'string', 'max:200'],
            'product_group_id' => ['nullable', 'integer', Rule::exists('product_groups', 'id')],
            'attribute_values' => ['sometimes', 'array', 'max:20'],
            'attribute_values.*' => ['nullable', 'string', 'max:255'],
            'category_ids' => ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
            'kind' => ['required', Rule::in(['single', 'bundle'])],
            'bundle_components' => ['present', 'array', 'max:100'],
            'bundle_components.*.component_product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')],
            'bundle_components.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999999.9999', 'decimal:0,4'],
            'name' => ['required', 'string', 'max:200'],
            'sku' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('products', 'sku')->ignore($this->route('product'))],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('status', 'active')],
            'registration_status' => ['required', Rule::in(['provisional', 'confirmed'])],
            'publication_status' => ['required', Rule::in($this->input('registration_status') === 'confirmed' ? ['private', 'public'] : ['private'])],
            'shortage_policy' => ['required', Rule::in(['reject', 'backorder'])],
            'default_sale_price' => ['nullable', 'required_if:registration_status,confirmed', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where(fn ($query) => $query->where('status', 'active')->orWhere('id', $this->route('product')?->tax_rate_id ?? 0))],
            'lock_version' => [$this->route('product') ? 'required' : 'nullable', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $groupId = $this->input('product_group_id');
            $values = $this->input('attribute_values', []);
            if (! $groupId) {
                if (collect($values)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty()) {
                    $validator->errors()->add('attribute_values', '基本商品を選択してから属性を入力してください。');
                }

                return;
            }
            $group = ProductGroup::with('definitions')->find($groupId);
            if (! $group) {
                return;
            }
            $currentGroupId = $this->route('product')?->product_group_id;
            if ($group->status !== 'active' && (int) $currentGroupId !== $group->id) {
                $validator->errors()->add('product_group_id', '有効な基本商品を選択してください。');
            }
            $definitions = $group->definitions->where('status', 'active')->keyBy('id');
            foreach ($values as $id => $value) {
                if (! ctype_digit((string) $id) || ! $definitions->has((int) $id)) {
                    $validator->errors()->add('attribute_values', '基本商品に設定された属性を入力してください。');

                    continue;
                }
                if ($value === null || $value === '') {
                    continue;
                }
                $definition = $definitions->get((int) $id);
                if ($definition->input_type === 'number' && ! preg_match('/^-?\\d{1,15}(\\.\\d{1,6})?$/', (string) $value)) {
                    $validator->errors()->add("attribute_values.$id", $definition->name.'は整数15桁・小数6桁以内の数値で入力してください。');
                }
                if ($definition->input_type === 'select' && ! in_array((string) $value, $definition->options ?? [], true)) {
                    $validator->errors()->add("attribute_values.$id", $definition->name.'の選択肢を選び直してください。');
                }
            }
            if ($this->input('registration_status') === 'confirmed') {
                foreach ($definitions->where('is_required', true) as $definition) {
                    if (($values[$definition->id] ?? '') === '') {
                        $validator->errors()->add("attribute_values.$definition->id", $definition->name.'を入力してください。');
                    }
                }
            }
        }, function (Validator $validator): void {
            $kind = $this->input('kind');
            $components = $this->input('bundle_components', []);
            if ($kind === 'single') {
                if ($components !== []) {
                    $validator->errors()->add('bundle_components', '単品にはセット内容を設定できません。');
                }

                return;
            }
            if ($this->input('registration_status') === 'confirmed' && count($components) === 0) {
                $validator->errors()->add('bundle_components', 'セット商品を本登録するには構成商品を1件以上設定してください。');
            }
            $unit = Unit::find($this->input('unit_id'));
            if ($unit && $unit->quantity_scale !== 0) {
                $validator->errors()->add('unit_id', 'セット商品の販売単位は整数単位を選択してください。');
            }
            $products = Product::with('unit:id,quantity_scale')->whereIn('id', collect($components)->pluck('component_product_id')->filter())->get()->keyBy('id');
            foreach ($components as $index => $component) {
                if (! is_array($component)) {
                    continue;
                }
                $product = $products->get((int) ($component['component_product_id'] ?? 0));
                if (! $product) {
                    continue;
                }
                if ($this->route('product') && $product->id === $this->route('product')->id) {
                    $validator->errors()->add("bundle_components.$index.component_product_id", 'セット商品自身は構成商品にできません。');
                }
                if ($product->kind !== 'single' || $product->registration_status !== 'confirmed') {
                    $validator->errors()->add("bundle_components.$index.component_product_id", '本登録済みの単品を選択してください。');
                }
                $quantity = (string) ($component['quantity'] ?? '');
                if (is_numeric($quantity) && $this->decimalPlaces($quantity) > $product->unit->quantity_scale) {
                    $validator->errors()->add("bundle_components.$index.quantity", $product->unit->quantity_scale === 0 ? 'この構成商品の数量は整数で入力してください。' : "この構成商品の数量は小数{$product->unit->quantity_scale}桁以内で入力してください。");
                }
            }
        }];
    }

    private function decimalPlaces(string $value): int
    {
        if (! str_contains($value, '.')) {
            return 0;
        }

        return strlen(rtrim(substr(strrchr($value, '.'), 1), '0'));
    }

    public function messages(): array
    {
        return [
            'images.max' => '商品画像は5枚以内で登録してください。',
            'images.*.id.exists' => 'この商品の画像を選び直してください。',
            'images.*.file.image' => 'JPEG・PNG・WebPの画像を選択してください。',
            'images.*.file.mimes' => 'JPEG・PNG・WebPの画像を選択してください。',
            'images.*.file.max' => '画像は1枚5MB以内にしてください。',
            'images.*.file.dimensions' => '画像の縦横は6000px以内にしてください。',
            'images.*.alt_text.max' => '画像の説明は200文字以内で入力してください。',
            'kind.in' => '販売商品の種類を選び直してください。',
            'bundle_components.present' => 'セット内容を送信してください。',
            'bundle_components.array' => 'セット内容を入力し直してください。',
            'bundle_components.*.component_product_id.distinct' => '同じ構成商品が重複しています。',
            'bundle_components.*.component_product_id.exists' => '構成商品を選び直してください。',
            'bundle_components.*.quantity.gt' => '構成数量は0より大きい数値で入力してください。',
            'product_group_id.exists' => '基本商品を選び直してください。',
            'attribute_values.array' => '属性を入力し直してください。',
            'attribute_values.max' => '属性は20件以内で入力してください。',
            'attribute_values.*.max' => '属性値は255文字以内で入力してください。',
            'category_ids.array' => '商品分類を選び直してください。',
            'category_ids.max' => '商品分類は100件以内で選択してください。',
            'category_ids.*.exists' => '存在する商品分類を選択してください。',
            'category_ids.*.distinct' => '同じ商品分類が重複しています。',
            'name.required' => '販売商品名を入力してください。',
            'name.max' => '販売商品名は200文字以内で入力してください。',
            'sku.unique' => 'この商品コードはすでに使われています。',
            'sku.regex' => '商品コードは半角英数字・ハイフン・アンダースコアで入力してください。',
            'sku.max' => '商品コードは50文字以内で入力してください。',
            'unit_id.required' => '単位を選択してください。',
            'unit_id.exists' => '有効な単位を選択してください。',
            'registration_status.in' => '登録状態を選び直してください。',
            'publication_status.in' => '仮登録の販売商品は公開できません。公開状態を確認してください。',
            'shortage_policy.in' => '在庫不足時の扱いを選び直してください。',
            'default_sale_price.required_if' => '本登録には標準販売単価が必要です。',
            'default_sale_price.regex' => '単価は0以上、整数15桁・小数4桁以内で入力してください。',
            'tax_rate_id.integer' => '税率区分を選び直してください。',
            'tax_rate_id.exists' => '有効な税率区分を選択してください。',
            'lock_version.required' => '画面を再読み込みしてから保存してください。',
        ];
    }
}
