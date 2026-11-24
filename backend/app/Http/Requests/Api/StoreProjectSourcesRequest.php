<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectSourcesRequest extends FormRequest
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
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => [
                'required',
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png,webp',
                'extensions:pdf,jpg,jpeg,png,webp',
            ],
            'source_types' => ['sometimes', 'array', 'max:10'],
            'source_types.*' => ['nullable', 'in:pdf,price_list,image,brochure,other'],
        ];
    }
}
