<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Validation\ValidationException;

class ValidateProductVariant
{
    /** Called inside the product transaction; serialize variant writes on the parent. */
    public function lockGroup(?int $groupId, ?int $previousGroupId = null): void
    {
        if (! $groupId) {
            return;
        }
        $group = ProductGroup::whereKey($groupId)->lockForUpdate()->first();
        if (! $group || ($group->status !== 'active' && $previousGroupId !== $groupId)) {
            throw ValidationException::withMessages(['product_group_id' => '基本商品が無効のため追加できません。']);
        }
    }

    public function validate(Product $product): void
    {
        if (! $product->product_group_id) {
            return;
        }
        $definitions = $product->group->definitions()->where('status', 'active')->get();
        if ($definitions->isEmpty()) {
            return;
        }
        $values = $product->attributeValues()->pluck('value', 'product_attribute_definition_id');
        // Empty provisional drafts do not yet define a variant combination.
        if ($product->registration_status === 'provisional' && $definitions->every(fn ($definition) => trim((string) $values->get($definition->id)) === '')) {
            return;
        }
        $duplicates = Product::where('product_group_id', $product->product_group_id)->whereKeyNot($product->id);
        foreach ($definitions as $definition) {
            $value = trim((string) $values->get($definition->id));
            if ($product->registration_status === 'confirmed' && $definition->is_required && $value === '') {
                throw ValidationException::withMessages(['attribute_values.'.$definition->id => $definition->name.'を入力してください。']);
            }
            if ($value === '') {
                $duplicates->whereDoesntHave('attributeValues', fn ($query) => $query
                    ->where('product_attribute_definition_id', $definition->id)->whereRaw("TRIM(value) <> ''"));
            } else {
                $duplicates->whereHas('attributeValues', function ($query) use ($definition, $value): void {
                    $query->where('product_attribute_definition_id', $definition->id);
                    if ($definition->input_type === 'number') {
                        $query->whereRaw('CAST(value AS DECIMAL(21,6)) = ?', [bcadd($value, '0', 6)]);
                    } else {
                        $query->where('value', $value);
                    }
                });
            }
        }
        if ($duplicates->exists()) {
            throw ValidationException::withMessages(['attribute_values' => 'この属性の組み合わせはすでに登録されています。']);
        }
    }
}
