<?php

namespace App\Actions\Inventory;

use DateTimeImmutable;
use Illuminate\Validation\Rule;

class ReceiptExpiration
{
    public static function normalizeType(?string $value): ?string
    {
        return match (trim($value ?? '')) {
            '' => null,
            '賞味期限', 'best_before' => 'best_before',
            '消費期限', 'use_by' => 'use_by',
            default => trim($value),
        };
    }

    public static function displayType(string $value): string
    {
        return match (self::normalizeType($value)) {
            'best_before' => '賞味期限',
            'use_by' => '消費期限',
            default => $value,
        };
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(string $prefix = '', bool $allowUntypedDate = false): array
    {
        return [
            $prefix.'manufactured_on' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'expiration_type' => [
                'nullable', 'string', Rule::in(['best_before', 'use_by']),
                ...($allowUntypedDate ? [] : ['required_with:'.$prefix.'expires_on']),
            ],
            $prefix.'expires_on' => ['nullable', 'required_with:'.$prefix.'expiration_type', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'manufactured_on.date_format' => '製造日はYYYY-MM-DD形式で入力してください。',
            'expiration_type.required_with' => '期限日を指定した場合は、期限種別も入力してください。',
            'expiration_type.in' => '期限種別は「賞味期限」または「消費期限」を入力してください。',
            'expiration_type.string' => '期限種別は「賞味期限」または「消費期限」を入力してください。',
            'expires_on.required_with' => '期限種別を指定した場合は、期限日も入力してください。',
            'expires_on.date_format' => '期限日はYYYY-MM-DD形式で入力してください。',
        ];
    }

    public static function isDate(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public static function isBeforeManufacture(mixed $manufactured, mixed $expires): bool
    {
        return self::isDate($manufactured) && self::isDate($expires) && $expires < $manufactured;
    }
}
