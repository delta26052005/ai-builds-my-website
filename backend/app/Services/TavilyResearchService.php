<?php

namespace App\Services;

use App\Models\BusinessResearchSource;
use App\Models\WebsiteProject;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TavilyResearchService
{
    private const MAX_RESULTS = 8;

    private const MAX_CONTENT_LENGTH = 15000;

    /**
     * @return array{source_count: int}
     */
    public function research(WebsiteProject $project): array
    {
        $suppliedLinks = $this->suppliedLinks($project);

        DB::transaction(function () use ($project, $suppliedLinks): void {
            foreach ($suppliedLinks as $link) {
                $this->saveSource($project, $link, false);
            }
        });

        $apiKey = config('services.tavily.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new ProviderException(
                'provider_not_configured',
                'Business research is not configured. Add a Tavily API key on the server.',
            );
        }

        $query = $this->makeQuery($project, $suppliedLinks);

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->post('https://api.tavily.com/search', [
                    'query' => $query,
                    'search_depth' => 'basic',
                    'max_results' => self::MAX_RESULTS,
                    'include_answer' => false,
                    'include_raw_content' => true,
                ]);
        } catch (ConnectionException) {
            throw new ProviderException(
                'provider_timeout',
                'Business research could not reach the search provider. Please try again.',
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ProviderException(
                'invalid_provider_key',
                'Business research could not authenticate with the search provider.',
            );
        }

        if ($response->status() === 429) {
            throw new ProviderException(
                'provider_rate_limited',
                'The search provider is temporarily rate-limiting requests. Please try again later.',
            );
        }

        if (! $response->successful()) {
            throw new ProviderException(
                'provider_unavailable',
                'The search provider is temporarily unavailable. Please try again later.',
            );
        }

        $results = $response->json('results');

        if (! is_array($results)) {
            throw new ProviderException(
                'invalid_provider_response',
                'The search provider returned an unsupported response.',
            );
        }

        DB::transaction(function () use ($project, $results): void {
            foreach (array_slice($results, 0, self::MAX_RESULTS) as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $url = $this->safePublicUrl($result['url'] ?? null);

                if ($url === null) {
                    continue;
                }

                $rawContent = $this->safeText($result['raw_content'] ?? null, self::MAX_CONTENT_LENGTH);
                $snippet = $this->safeText($result['content'] ?? null, 2000);
                $this->saveSource($project, [
                    'source_url' => $url,
                    'title' => $this->safeText($result['title'] ?? null, 500),
                    'snippet' => $snippet,
                    'content' => $rawContent,
                    'source_type' => $this->sourceType($url),
                ], $rawContent !== '' || $snippet !== '');
            }
        });

        return [
            'source_count' => $project->businessResearchSources()->count(),
        ];
    }

    /**
     * @return list<array{source_url: string, source_type: string, title: string|null, snippet: string|null, content: string|null}>
     */
    private function suppliedLinks(WebsiteProject $project): array
    {
        $links = [];

        foreach ([
            'website' => $project->website_url,
            'google_business' => $project->google_business_url,
        ] as $type => $value) {
            $url = $this->safePublicUrl($value);

            if ($url !== null) {
                $links[] = $this->linkRecord($url, $type);
            }
        }

        foreach ($project->social_links ?? [] as $value) {
            $url = $this->safePublicUrl($value);

            if ($url !== null) {
                $links[] = $this->linkRecord($url, 'social');
            }
        }

        return $links;
    }

    /**
     * @return array{source_url: string, source_type: string, title: null, snippet: null, content: null}
     */
    private function linkRecord(string $url, string $sourceType): array
    {
        return [
            'source_url' => $url,
            'source_type' => $sourceType,
            'title' => null,
            'snippet' => null,
            'content' => null,
        ];
    }

    /**
     * @param  list<array{source_url: string, source_type: string}>  $links
     */
    private function makeQuery(WebsiteProject $project, array $links): string
    {
        $parts = array_filter([
            $project->business_name,
            $project->business_category,
            $project->city,
            $project->state,
            $project->country,
            $project->business_description,
        ]);
        $urls = array_column($links, 'source_url');

        if ($urls !== []) {
            $parts[] = 'Public pages related to: '.implode(' ', array_slice($urls, 0, 6));
        }

        $query = (new SensitiveTextRedactor)->redact(implode(' ', $parts));

        return mb_substr($query, 0, 1500);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveSource(WebsiteProject $project, array $data, bool $accessible): void
    {
        $url = $this->safePublicUrl($data['source_url'] ?? null);

        if ($url === null) {
            return;
        }

        $hash = hash('sha256', $url);
        $existing = BusinessResearchSource::query()
            ->where('website_project_id', $project->id)
            ->where('url_hash', $hash)
            ->first();

        $source = $existing ?? new BusinessResearchSource([
            'source_url' => $url,
            'url_hash' => $hash,
            'source_type' => $data['source_type'] ?? 'tavily',
        ]);

        $source->fill([
            'title' => $this->safeText($data['title'] ?? null, 500) ?: $source->title,
            'snippet' => $this->safeText($data['snippet'] ?? null, 2000) ?: $source->snippet,
            'content' => $this->safeText($data['content'] ?? null, self::MAX_CONTENT_LENGTH) ?: $source->content,
            'access_status' => $accessible ? 'accessible' : 'inaccessible',
            'access_message' => $accessible
                ? null
                : 'Direct fetching was not attempted; Tavily did not return usable public content for this URL.',
            'fetched_at' => $accessible ? now() : $source->fetched_at,
        ]);

        $project->businessResearchSources()->save($source);
    }

    private function sourceType(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (str_contains($host, 'instagram.') || str_contains($host, 'facebook.')
            || str_contains($host, 'linkedin.') || str_contains($host, 'youtube.')
            || str_contains($host, 'tiktok.') || str_contains($host, 'twitter.')
            || str_contains($host, 'x.com')) {
            return 'social';
        }

        if (str_contains($host, 'google.') || str_contains($host, 'maps.')) {
            return 'google_business';
        }

        return 'tavily';
    }

    private function safePublicUrl(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || $host === 'localhost'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return null;
        }

        $ipHost = trim($host, '[]');

        if (filter_var($ipHost, FILTER_VALIDATE_IP) !== false
            && filter_var($ipHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $safeUrl = $scheme.'://'.$host.$port.$path;

        return mb_strlen($safeUrl) <= 2048 ? $safeUrl : null;
    }

    private function safeText(mixed $value, int $maxLength): string
    {
        if (! is_string($value)) {
            return '';
        }

        return mb_substr(trim(strip_tags($value)), 0, $maxLength);
    }
}
