<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SystemNameSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('system_name');
        if (is_string($value)) {
            $this->merge(['system_name' => trim($value)]);
        }
    }

    public function rules(): array
    {
        return [
            'system_name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
        ];
    }

    public function messages(): array
    {
        return [
            'system_name.required' => 'システム名を入力してください。',
            'system_name.string' => 'システム名は文字列で入力してください。',
            'system_name.max' => 'システム名は100文字以内で入力してください。',
            'system_name.not_regex' => 'システム名は1行で入力してください。',
        ];
    }
}
