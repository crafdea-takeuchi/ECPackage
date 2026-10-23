<?php

namespace App\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $settings = collect($this->input('purchase_settings', []))->map(function ($setting): mixed {
            if (! is_array($setting)) {
                return $setting;
            }

            foreach (['supplier_product_code', 'default_purchase_price', 'minimum_order_quantity', 'lead_time_days'] as $field) {
                $setting[$field] = trim((string) ($setting[$field] ?? ''));
                if ($setting[$field] === '') {
                    $setting[$field] = null;
                }
            }

            return $setting;
        })->all();

        $this->merge([
            'code' => mb_strtoupper(trim((string) $this->input('code', ''))),
            'name' => trim((string) $this->input('name', '')),
            'contact_name' => trim((string) $this->input('contact_name', '')) ?: null,
            'email' => mb_strtolower(trim((string) $this->input('email', ''))) ?: null,
            'phone' => trim((string) $this->input('phone', '')) ?: null,
            'postal_code' => trim((string) $this->input('postal_code', '')) ?: null,
            'address' => trim((string) $this->input('address', '')) ?: null,
            'notes' => trim((string) $this->input('notes', '')) ?: null,
            'purchase_settings' => $settings,
        ]);
    }

    public function rules(): array
    {
        $supplierId = $this->route('supplier')?->id;

        return [
            'code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('suppliers')->ignore($supplierId)],
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'lock_version' => [$supplierId ? 'required' : 'nullable', 'integer', 'min:0'],
            'purchase_settings' => ['present', 'array', 'max:100'],
            'purchase_settings.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where(fn (Builder $query): Builder => $query->whereIn('registration_status', ['provisional', 'confirmed'])->where('kind', 'single'))],
            'purchase_settings.*.supplier_product_code' => ['nullable', 'string', 'max:100'],
            'purchase_settings.*.default_purchase_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999999.9999', 'decimal:0,4'],
            'purchase_settings.*.minimum_order_quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999999999999.9999', 'decimal:0,4'],
            'purchase_settings.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'purchase_settings.*.is_primary' => ['required', 'boolean'],
            'purchase_settings.*.status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->input('purchase_settings', []) as $index => $setting) {
                if (is_array($setting) && ($setting['status'] ?? null) === 'inactive' && ($setting['is_primary'] ?? false)) {
                    $validator->errors()->add("purchase_settings.$index.is_primary", '無効な仕入設定は通常の仕入先にできません。');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'code.regex' => '仕入先コードは半角英大文字・数字・ハイフン・アンダースコアで入力してください。',
            'code.unique' => 'この仕入先コードはすでに使われています。',
            'code.max' => '仕入先コードは50文字以内で入力してください。',
            'name.required' => '仕入先名を入力してください。',
            'name.max' => '仕入先名は150文字以内で入力してください。',
            'contact_name.max' => '担当者名は100文字以内で入力してください。',
            'email.email' => 'メールアドレスの形式を確認してください。',
            'phone.max' => '電話番号は50文字以内で入力してください。',
            'postal_code.max' => '郵便番号は20文字以内で入力してください。',
            'address.max' => '住所は500文字以内で入力してください。',
            'notes.max' => '備考は2000文字以内で入力してください。',
            'status.in' => '有効または無効を選択してください。',
            'lock_version.required' => '画面を再読み込みしてから保存してください。',
            'purchase_settings.max' => '仕入商品は100件以内で設定してください。',
            'purchase_settings.*.product_id.required' => '販売商品を選択してください。',
            'purchase_settings.*.product_id.distinct' => '同じ販売商品が重複しています。',
            'purchase_settings.*.product_id.exists' => '販売商品を選び直してください。',
            'purchase_settings.*.supplier_product_code.max' => '仕入先商品コードは100文字以内で入力してください。',
            'purchase_settings.*.default_purchase_price.numeric' => '基準仕入単価は数値で入力してください。',
            'purchase_settings.*.default_purchase_price.min' => '基準仕入単価は0以上で入力してください。',
            'purchase_settings.*.default_purchase_price.decimal' => '基準仕入単価は小数4桁以内で入力してください。',
            'purchase_settings.*.minimum_order_quantity.numeric' => '最小発注数は数値で入力してください。',
            'purchase_settings.*.minimum_order_quantity.gt' => '最小発注数は0より大きい数値で入力してください。',
            'purchase_settings.*.minimum_order_quantity.decimal' => '最小発注数は小数4桁以内で入力してください。',
            'purchase_settings.*.lead_time_days.integer' => '標準納期は0〜3650日の整数で入力してください。',
            'purchase_settings.*.lead_time_days.min' => '標準納期は0〜3650日の整数で入力してください。',
            'purchase_settings.*.lead_time_days.max' => '標準納期は0〜3650日の整数で入力してください。',
        ];
    }
}
