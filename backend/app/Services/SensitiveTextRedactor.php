<?php

namespace App\Services;

class SensitiveTextRedactor
{
    public function redact(string $text): string
    {
        $text = preg_replace(
            '/(["\']?\b(?:api[_-]?key|secret|password|token|access[_-]?key)\b["\']?\s*[:=]\s*["\']?)[\w.\/+=-]{8,}/i',
            '$1[REDACTED]',
            $text,
        ) ?? $text;

        return preg_replace('/\bAIza[0-9A-Za-z_-]{25,}\b/', '[REDACTED_KEY]', $text) ?? $text;
    }
}
