<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGeneratedWebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'content_json' => ['required', 'array'],
            'change_summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expected_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
