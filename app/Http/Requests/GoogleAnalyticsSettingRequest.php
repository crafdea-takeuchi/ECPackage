<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GoogleAnalyticsSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('google_analytics_id');
        if (is_string($value)) {
            $this->merge(['google_analytics_id' => strtoupper(trim($value)) ?: null]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'google_analytics_id' => ['nullable', 'string', 'max:20', 'regex:/^G-[A-Z0-9]{4,18}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'google_analytics_id.string' => '測定IDは「G-」から始まる形式で入力してください。',
            'google_analytics_id.max' => '測定IDは「G-」から始まる20文字以内で入力してください。',
            'google_analytics_id.regex' => '測定IDは「G-」から始まる形式（例：G-ABC123XYZ9）で入力してください。',
        ];
    }
}
