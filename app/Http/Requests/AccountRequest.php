<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->routeIs('admin.*') || ($this->user()?->isAdmin() ?? false);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)->letters()->numbers()],
            'role' => $this->routeIs('admin.*') ? ['required', Rule::in(['customer', 'admin'])] : ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'お名前', 'email' => 'メールアドレス', 'password' => 'パスワード', 'role' => '権限'];
    }
}
