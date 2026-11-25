<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WebsiteContentValidator
{
    public const TEMPLATE_KEYS = ['modern_business', 'local_business'];

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $content): array
    {
        $allowedKeys = [
            'business_name',
            'tagline',
            'template_key',
            'theme',
            'navigation',
            'hero',
            'about',
            'services_or_products',
            'highlights',
            'gallery',
            'contact',
            'footer',
            'content_provenance',
        ];

        if (array_diff(array_keys($content), $allowedKeys) !== []) {
            throw ValidationException::withMessages([
                'content_json' => ['Website content contains unsupported fields.'],
            ]);
        }

        $validated = Validator::make($content, [
            'business_name' => ['required', 'string', 'max:160'],
            'tagline' => ['present', 'nullable', 'string', 'max:140'],
            'template_key' => ['required', 'string', 'in:'.implode(',', self::TEMPLATE_KEYS)],
            'theme' => ['required', 'array:primary_color,secondary_color,font_style'],
            'theme.primary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme.secondary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme.font_style' => ['required', 'string', 'in:modern,classic,editorial'],
            'navigation' => ['present', 'array', 'max:6'],
            'navigation.*' => ['required', 'array:label,href'],
            'navigation.*.label' => ['required', 'string', 'max:40'],
            'navigation.*.href' => [
                'required',
                'string',
                'in:#hero,#about,#products,#services,#highlights,#gallery,#contact',
            ],
            'hero' => ['required', 'array:heading,description,image,button_text'],
            'hero.heading' => ['required', 'string', 'max:120'],
            'hero.description' => ['present', 'nullable', 'string', 'max:600'],
            'hero.image' => ['present', 'nullable', 'url', 'max:2048', $this->httpsUrl()],
            'hero.button_text' => ['present', 'nullable', 'string', 'max:40'],
            'about' => ['required', 'array:heading,description'],
            'about.heading' => ['required', 'string', 'max:100'],
            'about.description' => ['present', 'nullable', 'string', 'max:1000'],
            'services_or_products' => ['present', 'array', 'max:50'],
            'services_or_products.*' => ['required', 'array:name,description'],
            'services_or_products.*.name' => ['required', 'string', 'max:100'],
            'services_or_products.*.description' => ['present', 'nullable', 'string', 'max:500'],
            'highlights' => ['present', 'array', 'max:8'],
            'highlights.*' => ['required', 'string', 'max:180'],
            'gallery' => ['present', 'array', 'max:10'],
            'gallery.*' => ['required', 'array:image_url,alt,source_id'],
            'gallery.*.image_url' => ['present', 'nullable', 'url', 'max:2048', $this->httpsUrl()],
            'gallery.*.alt' => ['required', 'string', 'max:160'],
            'gallery.*.source_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'contact' => ['required', 'array:address,phone,email,social_links'],
            'contact.address' => ['present', 'nullable', 'string', 'max:250'],
            'contact.phone' => ['present', 'nullable', 'string', 'max:50'],
            'contact.email' => ['present', 'nullable', 'email', 'max:254'],
            'contact.social_links' => ['present', 'array', 'max:8'],
            'contact.social_links.*' => ['required', 'array:label,url'],
            'contact.social_links.*.label' => ['required', 'string', 'max:40'],
            'contact.social_links.*.url' => ['required', 'string', 'url', 'max:2048', $this->httpsUrl()],
            'footer' => ['required', 'array:copyright_text,show_business_name'],
            'footer.copyright_text' => ['present', 'nullable', 'string', 'max:200'],
            'footer.show_business_name' => ['required', 'boolean'],
            'content_provenance' => ['sometimes', 'array'],
        ])->validate();

        foreach ($validated['content_provenance'] ?? [] as $field => $origin) {
            if (! is_string($field)
                || ! preg_match('/^(business_name|tagline|navigation\.\d+\.label|hero\.(heading|description|button_text)|about\.(heading|description)|services_or_products\.\d+\.(name|description)|highlights\.\d+|gallery\.\d+\.alt|contact\.(address|phone|email)|contact\.social_links\.\d+\.(label|url)|footer\.copyright_text)$/', $field)
                || ! in_array($origin, ['ai_generated_unverified', 'owner_authored', 'legacy_unclassified'], true)) {
                throw ValidationException::withMessages([
                    'content_json.content_provenance' => ['Website content provenance is invalid.'],
                ]);
            }
        }

        foreach ([
            'navigation',
            'services_or_products',
            'highlights',
            'gallery',
            'contact.social_links',
        ] as $path) {
            $value = data_get($validated, $path);

            if (! is_array($value) || ! array_is_list($value)) {
                throw ValidationException::withMessages([
                    'content_json.'.$path => ['This field must be a list.'],
                ]);
            }
        }

        return $validated;
    }

    /**
     * Validate model-generated factual values against owner-confirmed candidates.
     *
     * @param  array<string, mixed>  $content
     * @param  list<array{fact_key: string, category: string, value: string}>  $facts
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateGenerated(
        array $content,
        array $facts,
        bool $allowPreviouslyApprovedMedia = false,
    ): array {
        $content = $this->validate($content);
        $valuesByKey = [];

        foreach ($facts as $fact) {
            $valuesByKey[$fact['fact_key']][] = $fact['value'];
        }

        $businessNames = $valuesByKey['business_name'] ?? [];

        if (! $this->containsValue($businessNames, $content['business_name'])) {
            $this->unsupported('business_name');
        }

        if (! $this->containsValue($businessNames, $content['hero']['heading'])) {
            $this->unsupported('hero.heading');
        }

        if (! in_array(mb_strtolower($content['about']['heading']), ['about', 'about us'], true)) {
            $this->unsupported('about.heading');
        }

        $confirmedValues = array_merge(...array_values($valuesByKey ?: [[]]));

        foreach ([
            'tagline' => $content['tagline'],
            'hero.description' => $content['hero']['description'],
            'about.description' => $content['about']['description'],
            'footer.copyright_text' => $content['footer']['copyright_text'],
        ] as $field => $value) {
            if ($value !== null && $value !== '') {
                $this->validateGroundedCopy($field, $value, $confirmedValues);
            }
        }

        if ($content['hero']['button_text'] !== null
            && ! in_array(mb_strtolower($content['hero']['button_text']), [
                'contact',
                'contact us',
                'get in touch',
                'learn more',
                'view services',
            ], true)) {
            $this->unsupported('hero.button_text');
        }

        $navigationLabels = [
            '#hero' => ['home'],
            '#about' => ['about'],
            '#services' => ['services', 'products'],
            '#highlights' => ['highlights'],
            '#gallery' => ['gallery'],
            '#contact' => ['contact'],
        ];

        foreach ($content['navigation'] as $index => $item) {
            if (! in_array(
                mb_strtolower($item['label']),
                $navigationLabels[$item['href']],
                true,
            )) {
                $this->unsupported('navigation.'.$index.'.label');
            }
        }

        foreach ([
            'address' => ['business_address', 'address'],
            'phone' => ['business_phone', 'phone'],
            'email' => ['business_email', 'email'],
        ] as $field => $keys) {
            $value = $content['contact'][$field];

            if ($value !== null && ! $this->matchesAnyKey($valuesByKey, $keys, $value)) {
                $this->unsupported('contact.'.$field);
            }
        }

        $productAndServiceValues = [];

        foreach ($facts as $fact) {
            if (in_array($fact['category'], ['product', 'service'], true)
                || str_starts_with($fact['fact_key'], 'product.')
                || str_starts_with($fact['fact_key'], 'service.')) {
                $productAndServiceValues[] = $fact['value'];
            }
        }

        foreach ($content['services_or_products'] as $index => $item) {
            if (! $this->containsValue($productAndServiceValues, $item['name'])) {
                $this->unsupported('services_or_products.'.$index.'.name');
            }

            if ($item['description'] !== null
                && $item['description'] !== '') {
                $this->validateGroundedCopy(
                    'services_or_products.'.$index.'.description',
                    $item['description'],
                    $confirmedValues,
                );
            }
        }

        foreach ($content['highlights'] as $index => $highlight) {
            $this->validateGroundedCopy('highlights.'.$index, $highlight, $confirmedValues);
        }

        foreach ($content['contact']['social_links'] as $index => $link) {
            if (! $this->containsValue($confirmedValues, $link['url'])) {
                $this->unsupported('contact.social_links.'.$index.'.url');
            }
        }

        if (! $allowPreviouslyApprovedMedia
            && ($content['hero']['image'] !== null || $content['gallery'] !== [])) {
            $this->unsupported('gallery');
        }

        return $content;
    }

    /**
     * Permit paraphrased marketing copy while rejecting detectable unsupported claims.
     *
     * @param  list<string>  $confirmedValues
     */
    private function validateGroundedCopy(string $field, string $value, array $confirmedValues): void
    {
        if (preg_match('/\b(?:ignore|override|reveal|secret|password|api key|system prompt|instructions?)\b/i', $value)) {
            $this->unsupported($field);
        }

        $sourceText = $this->normalizeClaimText(implode(' ', $confirmedValues));
        $claimPatterns = [
            '/\b(?:award[\s-]+winning|winner of awards?)\b/i',
            '/\b(?:certified|licensed|insured|accredited)\b/i',
            '/\b(?:best|leading|renowned|world[\s-]+class|top[\s-]+rated|number one|no\.? 1)\b/i',
            '/\b(?:trusted by|chosen by|known for|speciali[sz](?:e|es|ed|ing) in|serving|serves)\b/i',
            '/\b(?:thousands|hundreds|millions|decades)\b/i',
            '/\b(?:established|since\s+\d{4}|over\s+\d+\s+years?)\b/i',
            '/\b(?:open daily|open 24\/7|same[\s-]+day|free delivery|guarantee(?:d)?)\b/i',
            '/\b(?:opening hours?|business hours?|open (?:on|from|until|at|weekends?))\b/i',
            '/\b(?:prices?|pricing|starting at|per (?:hour|day|month)|discounts?|on sale)\b/i',
            '/\b(?:located in|based in|local to|customer reviews?|client testimonials?|star ratings?)\b/i',
            '/\b(?:expert|professional|premium|affordable|reliable|personalized|custom)\b/i',
        ];

        foreach ($claimPatterns as $pattern) {
            if (preg_match_all($pattern, $value, $matches)) {
                foreach ($matches[0] as $claim) {
                    if (! str_contains($sourceText, $this->normalizeClaimText($claim))) {
                        $this->unsupported($field);
                    }
                }
            }
        }

        preg_match_all('#\d+(?:[.,:/-]\d+)*(?:\s?%)?#', $value, $valueNumbers);
        preg_match_all('#\d+(?:[.,:/-]\d+)*(?:\s?%)?#', implode(' ', $confirmedValues), $sourceNumbers);
        $sourceDigits = array_map(
            static fn (string $number): string => preg_replace('/\D/', '', $number) ?? '',
            $sourceNumbers[0],
        );

        foreach ($valueNumbers[0] as $number) {
            $digits = preg_replace('/\D/', '', $number) ?? '';

            if ($digits !== '' && ! in_array($digits, $sourceDigits, true)) {
                $this->unsupported($field);
            }
        }
    }

    private function normalizeClaimText(string $value): string
    {
        $normalized = mb_strtolower($value);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    private function matchesAnyKey(array $valuesByKey, array $keys, string $value): bool
    {
        foreach ($keys as $key) {
            if ($this->containsValue($valuesByKey[$key] ?? [], $value)) {
                return true;
            }
        }

        return false;
    }

    private function containsValue(array $values, string $value): bool
    {
        foreach ($values as $candidate) {
            if (mb_strtolower(trim($candidate)) === mb_strtolower(trim($value))) {
                return true;
            }
        }

        return false;
    }

    private function unsupported(string $field): never
    {
        throw ValidationException::withMessages([
            'content_json.'.$field => ['This generated value is not supported by owner-confirmed business facts.'],
        ]);
    }

    private function httpsUrl(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $parts = parse_url($value);

            if (! is_array($parts)
                || strtolower($parts['scheme'] ?? '') !== 'https'
                || ! isset($parts['host'])) {
                $fail('The URL must use HTTPS and include a valid host.');
            }
        };
    }
}
