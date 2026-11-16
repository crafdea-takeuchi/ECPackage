<?php

namespace App\Http\Requests;

use App\Actions\Inventory\DisposeExpiredInventory;
use Illuminate\Foundation\Http\FormRequest;

class DisposeExpiredInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'lots' => ['required', 'array', 'min:1', 'max:20'],
            'lots.*.inventory_lot_id' => ['required', 'integer', 'distinct'],
            'lots.*.product_id' => ['required', 'integer'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            ...DisposeExpiredInventory::confirmationRules(),
        ];
    }

    public function messages(): array
    {
        return [
            ...DisposeExpiredInventory::confirmationMessages(),
            'lots.required' => '廃棄するロットを選択してください。',
            'lots.min' => '廃棄するロットを1件以上選択してください。',
            'lots.max' => '一度に廃棄できるのは表示中の20ロットまでです。',
            'lots.*.inventory_lot_id.required' => '対象ロットを選び直してください。',
            'lots.*.inventory_lot_id.integer' => '対象ロットを選び直してください。',
            'lots.*.inventory_lot_id.distinct' => '同じロットが重複しています。',
            'lots.*.product_id.required' => '販売商品を選び直してください。',
            'lots.*.product_id.integer' => '販売商品を選び直してください。',
            'occurred_on.required' => '処理日を入力してください。',
            'occurred_on.date_format' => '処理日はYYYY-MM-DD形式で入力してください。',
            'notes.string' => '備考は文字列で入力してください。',
            'notes.max' => '備考は2000文字以内で入力してください。',
        ];
    }
}
