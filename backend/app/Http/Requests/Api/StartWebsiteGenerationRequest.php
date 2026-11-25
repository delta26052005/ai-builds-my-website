<?php

namespace App\Http\Requests\Api;

use App\Services\WebsiteContentValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartWebsiteGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'template_key' => ['sometimes', 'string', Rule::in(WebsiteContentValidator::TEMPLATE_KEYS)],
        ];
    }
}
