<?php

namespace App\Http\Requests;

use App\Actions\Orders\BankTransferDetails;
use Illuminate\Foundation\Http\FormRequest;

class BankTransferSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (BankTransferDetails::FIELDS as $field => $label) {
            $value = $this->input($field);
            if (is_string($value)) {
                $value = trim($value);
                if (in_array($field, ['bank_code', 'branch_code', 'account_number'], true)) {
                    $value = mb_convert_kana($value, 'n');
                }
                $value = $value === '' ? null : $value;
            }
            $values[$field] = $value;
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        return BankTransferDetails::rules();
    }

    public function messages(): array
    {
        return BankTransferDetails::messages();
    }
}
