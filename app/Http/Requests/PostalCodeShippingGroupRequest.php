<?php

namespace App\Http\Requests;

use App\Models\PostalCodeShippingGroup;
use App\Models\PostalCodeShippingPostalCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PostalCodeShippingGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $postalCodes = $this->input('postal_codes');
        if (is_array($postalCodes)) {
            $postalCodes = array_values(array_map(
                fn (mixed $value): mixed => is_string($value)
                    ? preg_replace('/[\s\-‐‑–—―ー－]/u', '', mb_convert_kana(trim($value), 'a'))
                    : $value,
                $postalCodes,
            ));
        }

        $threshold = $this->input('free_shipping_threshold');
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'shipping_fee' => is_string($this->input('shipping_fee')) ? trim($this->input('shipping_fee')) : $this->input('shipping_fee'),
            'free_shipping_threshold' => is_string($threshold) && trim($threshold) === '' ? null : (is_string($threshold) ? trim($threshold) : $threshold),
            'postal_codes' => $postalCodes,
        ]);
    }

    public function rules(): array
    {
        /** @var PostalCodeShippingGroup|null $group */
        $group = $this->route('postalCodeGroup');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('postal_code_shipping_groups', 'name')->ignore($group?->getKey())],
            'shipping_fee' => ['bail', 'required', 'integer', 'min:0', 'max:'.ShippingSettingRequest::MAX_AMOUNT],
            'free_shipping_threshold' => ['bail', 'nullable', 'integer', 'min:0', 'max:'.ShippingSettingRequest::MAX_AMOUNT],
            'postal_codes' => ['required', 'array', 'min:1', 'max:1000'],
            'postal_codes.*' => ['bail', 'required', 'string', 'regex:/\A\d{7}\z/', 'distinct:strict'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $postalCodes = $this->input('postal_codes');
            if (! is_array($postalCodes) || $validator->errors()->has('postal_codes') || $validator->errors()->has('postal_codes.*')) {
                return;
            }

            /** @var PostalCodeShippingGroup|null $group */
            $group = $this->route('postalCodeGroup');
            $duplicates = PostalCodeShippingPostalCode::query()
                ->with('group:id,name')
                ->whereIn('postal_code', $postalCodes)
                ->when($group, fn ($query) => $query->where('postal_code_shipping_group_id', '!=', $group->id))
                ->get();

            if ($duplicates->isNotEmpty()) {
                $labels = $duplicates->map(fn (PostalCodeShippingPostalCode $postal): string => $postal->postal_code.'（'.$postal->group->name.'）')->join('、');
                $validator->errors()->add('postal_codes', 'ほかの郵便番号別送料グループに登録済みの郵便番号があります：'.$labels);
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' => 'グループ名を入力してください。',
            'name.unique' => '同じグループ名が登録されています。',
            'shipping_fee.required' => '送料を入力してください。',
            'shipping_fee.integer' => '送料は円単位の整数で入力してください。',
            'shipping_fee.min' => '送料は0円以上で入力してください。',
            'shipping_fee.max' => '送料が上限を超えています。',
            'free_shipping_threshold.integer' => '送料無料基準額は円単位の整数で入力してください。',
            'free_shipping_threshold.min' => '送料無料基準額は0円以上で入力してください。',
            'free_shipping_threshold.max' => '送料無料基準額が上限を超えています。',
            'postal_codes.required' => '郵便番号を1件以上入力してください。',
            'postal_codes.min' => '郵便番号を1件以上入力してください。',
            'postal_codes.max' => '1グループに登録できる郵便番号は1,000件までです。',
            'postal_codes.*.regex' => '郵便番号は7桁の数字で入力してください。',
            'postal_codes.*.distinct' => '同じ郵便番号が重複しています。',
        ];
    }
}
