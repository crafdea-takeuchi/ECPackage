<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isAdmin() && $this->user()->hasVerifiedEmail();
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $key): ?string => trim((string) $this->input($key, '')) ?: null;
        $postalCode = $text('shipping_postal_code');

        $this->merge([
            'notes' => $text('notes'),
            'shipping_name' => $text('shipping_name'),
            'shipping_postal_code' => $postalCode === null ? null : mb_convert_kana($postalCode, 'a'),
            'shipping_prefecture' => $text('shipping_prefecture'),
            'shipping_address_line1' => $text('shipping_address_line1'),
            'shipping_address_line2' => $text('shipping_address_line2'),
        ]);
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
            'shipping_name' => ['required', 'string', 'max:100'],
            'shipping_postal_code' => ['required', 'string', 'regex:/\A\d{3}-?\d{4}\z/'],
            'shipping_prefecture' => ['required', 'string', Rule::in(Order::PREFECTURES)],
            'shipping_address_line1' => ['required', 'string', 'max:200'],
            'shipping_address_line2' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.max' => '備考は2000文字以内で入力してください。',
            'shipping_name.required' => 'お届け先のお名前を入力してください。',
            'shipping_name.max' => 'お名前は100文字以内で入力してください。',
            'shipping_postal_code.required' => '郵便番号を入力してください。',
            'shipping_postal_code.regex' => '郵便番号は「123-4567」の形式で入力してください。',
            'shipping_prefecture.required' => '都道府県を選択してください。',
            'shipping_prefecture.in' => '都道府県を選択し直してください。',
            'shipping_address_line1.required' => '市区町村・番地を入力してください。',
            'shipping_address_line1.max' => '市区町村・番地は200文字以内で入力してください。',
            'shipping_address_line2.max' => '建物名・部屋番号は200文字以内で入力してください。',
        ];
    }

    /**
     * @return array{shipping_name: string, shipping_postal_code: string, shipping_prefecture: string, shipping_address_line1: string, shipping_address_line2: ?string}
     */
    public function shippingAddress(): array
    {
        $address = $this->safe()->only([
            'shipping_name', 'shipping_postal_code', 'shipping_prefecture',
            'shipping_address_line1', 'shipping_address_line2',
        ]);
        $postalCode = str_replace('-', '', $address['shipping_postal_code']);
        $address['shipping_postal_code'] = substr($postalCode, 0, 3).'-'.substr($postalCode, 3);

        return $address;
    }
}
