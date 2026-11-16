<?php

namespace App\Actions\Orders;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BankTransferDetails
{
    public const ACCOUNT_TYPES = ['普通', '当座', '貯蓄'];

    public const FIELDS = [
        'bank_name' => '銀行名', 'bank_code' => '銀行コード',
        'branch_name' => '支店名', 'branch_code' => '支店コード',
        'account_type' => '口座種別', 'account_number' => '口座番号',
        'account_holder' => '口座名義', 'account_holder_kana' => '口座名義カナ',
        'instructions' => 'お客様向け案内文',
    ];

    public static function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'regex:/\A[0-9]+\z/', 'max:10'],
            'branch_name' => ['required', 'string', 'max:100'],
            'branch_code' => ['nullable', 'string', 'regex:/\A[0-9]+\z/', 'max:10'],
            'account_type' => ['required', 'string', Rule::in(self::ACCOUNT_TYPES)],
            'account_number' => ['required', 'string', 'regex:/\A[0-9]+\z/', 'max:20'],
            'account_holder' => ['required', 'string', 'max:100'],
            'account_holder_kana' => ['required', 'string', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function messages(): array
    {
        $messages = [];
        foreach (self::FIELDS as $field => $label) {
            $max = match ($field) {
                'instructions' => 2000,
                'bank_code', 'branch_code' => 10,
                'account_number' => 20,
                default => 100,
            };
            $messages[$field.'.required'] = $label.'を入力してください。';
            $messages[$field.'.string'] = $label.'は文字列で入力してください。';
            $messages[$field.'.max'] = $label.'は'.$max.'文字以内で入力してください。';
            $messages[$field.'.regex'] = $label.'は半角数字で入力してください。';
        }
        $messages['account_type.in'] = '口座種別は「普通」「当座」「貯蓄」から選択してください。';

        return $messages;
    }

    public static function registered(?array $details): bool
    {
        return $details !== null && Validator::make($details, self::rules())->passes();
    }

    public static function maskedNumber(string $number): string
    {
        return strlen($number) > 3 ? '****'.substr($number, -3) : str_repeat('*', strlen($number));
    }
}
