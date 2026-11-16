<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeeSettingRequest extends FormRequest
{
    public const MAX_FEE = 999999999999999;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('cash_on_delivery_fee');
        if (is_string($value)) {
            $value = trim($value);
            $this->merge(['cash_on_delivery_fee' => $value === '' ? null : $value]);
        }
    }

    public function rules(): array
    {
        return ['cash_on_delivery_fee' => ['present', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_FEE]];
    }

    public function messages(): array
    {
        return [
            'cash_on_delivery_fee.present' => '代引き手数料を入力してください。未設定にする場合は空欄で保存してください。',
            'cash_on_delivery_fee.integer' => '代引き手数料は円単位の整数で入力してください。',
            'cash_on_delivery_fee.min' => '代引き手数料は0円以上で入力してください。',
            'cash_on_delivery_fee.max' => '代引き手数料は999,999,999,999,999円以下で入力してください。',
        ];
    }
}
