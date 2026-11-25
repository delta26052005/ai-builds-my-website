<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RegenerateWebsiteSectionRequest extends FormRequest
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
            'section' => [
                'required',
                'string',
                'in:hero,about,services_or_products,highlights,contact,footer',
            ],
        ];
    }
}
