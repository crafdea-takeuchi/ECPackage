<?php

namespace Tests\Fixtures;

use App\Models\OrderSetting;

class BankTransfer
{
    public static function details(array $overrides = []): array
    {
        return [
            'bank_name' => 'テスト銀行', 'bank_code' => '0001',
            'branch_name' => 'テスト支店', 'branch_code' => '001',
            'account_type' => '普通', 'account_number' => '001234567890',
            'account_holder' => 'テスト株式会社', 'account_holder_kana' => 'テスト（カ',
            'instructions' => '注文番号を振込名義へ添えてください。',
            ...$overrides,
        ];
    }

    public static function configure(array $overrides = []): array
    {
        $details = self::details($overrides);
        $settings = OrderSetting::current();
        $settings->bank_transfer = $details;
        $settings->save();

        return $details;
    }
}
