<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CsvImportUploadRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $type = $this->route('csv_import_type');

        if (is_string($type) && $type !== '') {
            $this->merge(['type' => $type]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(config('csv-imports.types', [])))],
            'file' => [
                'required',
                'file',
                'extensions:csv',
                'max:'.(int) config('csv-imports.max_file_size_kb', 5120),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.required' => '取込種別を選択してください。',
            'type.in' => '選択した取込種別は使用できません。',
            'file.required' => 'CSVファイルを選択してください。',
            'file.file' => 'CSVファイルを選択してください。',
            'file.extensions' => '拡張子が.csvのファイルを選択してください。',
            'file.max' => 'CSVファイルは5MB以内にしてください。',
        ];
    }
}
