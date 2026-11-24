<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SaveWebsiteProjectRequest extends FormRequest
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
        $required = $this->isMethod('POST')
            ? ['required']
            : ['sometimes', 'required'];
        $nullable = $this->isMethod('POST')
            ? ['nullable']
            : ['sometimes', 'nullable'];

        return [
            'business_name' => [...$required, 'string', 'max:255'],
            'city' => [...$required, 'string', 'max:120'],
            'state' => [...$nullable, 'string', 'max:120'],
            'country' => [...$required, 'string', 'max:120'],
            'business_category' => [...$required, 'string', 'max:120'],
            'business_description' => [...$nullable, 'string', 'max:5000'],
            'business_email' => [...$nullable, 'email', 'max:255'],
            'business_phone' => [...$nullable, 'string', 'max:30', 'regex:/^[0-9+\-().\s]{7,30}$/'],
            'business_address' => [...$nullable, 'string', 'max:500'],
            'website_url' => [...$nullable, 'url:http,https', 'max:2048'],
            'google_business_url' => [...$nullable, 'url:http,https', 'max:2048'],
            'social_links' => [
                ...$nullable,
                'array:instagram,facebook,linkedin,youtube,x,tiktok',
            ],
            'social_links.instagram' => ['nullable', 'url:http,https', 'max:2048'],
            'social_links.facebook' => ['nullable', 'url:http,https', 'max:2048'],
            'social_links.linkedin' => ['nullable', 'url:http,https', 'max:2048'],
            'social_links.youtube' => ['nullable', 'url:http,https', 'max:2048'],
            'social_links.x' => ['nullable', 'url:http,https', 'max:2048'],
            'social_links.tiktok' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }
}
