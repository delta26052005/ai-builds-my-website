<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class GeminiJsonClient
{
    /**
     * @return array<string, mixed>
     */
    public function generateJson(
        string $systemInstruction,
        string $userContent,
        int $maxOutputTokens = 8192,
        string $taskName = 'AI generation',
    ): array {
        $apiKey = config('services.gemini.api_key');
        $model = config('services.gemini.model');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new ProviderException(
                'provider_not_configured',
                $taskName.' is not configured. Add a Gemini API key on the server.',
            );
        }

        if (! is_string($model) || ! preg_match('/^[a-zA-Z0-9._-]{1,100}$/', $model)) {
            throw new ProviderException(
                'invalid_provider_configuration',
                'The configured Gemini model name is invalid.',
            );
        }

        if ($maxOutputTokens < 1 || $maxOutputTokens > 8192) {
            throw new ProviderException(
                'invalid_provider_configuration',
                'The configured Gemini output limit is invalid.',
            );
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(35)
                ->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent',
                    [
                        'systemInstruction' => [
                            'parts' => [['text' => $systemInstruction]],
                        ],
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [['text' => $userContent]],
                        ]],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'temperature' => 0.1,
                            'maxOutputTokens' => $maxOutputTokens,
                        ],
                    ],
                );
        } catch (ConnectionException) {
            throw new ProviderException(
                'provider_timeout',
                $taskName.' could not reach Gemini. Please try again.',
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ProviderException(
                'invalid_provider_key',
                $taskName.' could not authenticate with Gemini.',
            );
        }

        if ($response->status() === 429) {
            throw new ProviderException(
                'provider_rate_limited',
                'Gemini is temporarily rate-limiting requests. Please try again later.',
            );
        }

        if (! $response->successful()) {
            Log::warning('Gemini JSON generation request failed.', [
                'http_status' => $response->status(),
                'provider_status' => $response->json('error.status'),
            ]);

            throw new ProviderException(
                'provider_unavailable',
                $taskName.' is temporarily unavailable. Please try again later.',
            );
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text)) {
            throw new ProviderException(
                'invalid_model_response',
                'Gemini returned a response that '.$taskName.' could not validate.',
            );
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderException(
                'invalid_model_response',
                'Gemini returned malformed structured data.',
            );
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new ProviderException(
                'invalid_model_response',
                'Gemini returned an unsupported structured response.',
            );
        }

        return $decoded;
    }
}
