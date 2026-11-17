<?php

namespace App\Http\Requests;

use App\Actions\Orders\ShippingZones;
use Illuminate\Foundation\Http\FormRequest;

class ShippingSettingRequest extends FormRequest
{
    public const MAX_AMOUNT = FeeSettingRequest::MAX_FEE;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['shipping_zone_fees', 'free_shipping_thresholds'] as $field) {
            $values = $this->input($field);
            if (! is_array($values)) {
                continue;
            }
            foreach ($values as $code => $value) {
                if (is_string($value)) {
                    $values[$code] = trim($value) === '' ? null : trim($value);
                }
            }
            $this->merge([$field => $values]);
        }
    }

    public function rules(): array
    {
        $allowed = 'array:'.implode(',', ShippingZones::codes());
        $rules = [
            'shipping_zone_fees' => ['required', $allowed],
            'free_shipping_thresholds' => ['sometimes', $allowed],
        ];
        foreach (ShippingZones::codes() as $code) {
            $rules['shipping_zone_fees.'.$code] = ['bail', 'required', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT];
            $rules['free_shipping_thresholds.'.$code] = ['bail', 'sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'shipping_zone_fees.required' => '8地域すべての送料を入力してください。',
            'shipping_zone_fees.array' => '送料区分を選び直してください。',
            'free_shipping_thresholds.array' => '送料無料基準額の送料区分を選び直してください。',
        ];
        foreach (ShippingZones::LABELS as $code => $name) {
            foreach (['shipping_zone_fees' => '送料', 'free_shipping_thresholds' => '送料無料基準額'] as $key => $label) {
                $field = $key.'.'.$code;
                $messages[$field.'.required'] = $name.'の'.$label.'を入力してください。0円は送料無料として登録できます。';
                $messages[$field.'.integer'] = $name.'の'.$label.'は円単位の整数で入力してください。';
                $messages[$field.'.min'] = $name.'の'.$label.'は0円以上で入力してください。';
                $messages[$field.'.max'] = $name.'の'.$label.'は999,999,999,999,999円以下で入力してください。';
            }
        }

        return $messages;
    }
}
