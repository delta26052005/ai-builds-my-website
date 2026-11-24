<?php

namespace App\Services;

use App\Models\BusinessFact;
use App\Models\WebsiteProject;
use Illuminate\Support\Facades\DB;

class BusinessFactIngestionService
{
    private const ALLOWED_CATEGORIES = [
        'business',
        'location',
        'product',
        'service',
        'contact',
        'hours',
        'pricing',
        'other',
    ];

    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  array<string, array<string, mixed>>  $sourceMap
     * @return array{fact_count: int, skipped_count: int}
     */
    public function store(WebsiteProject $project, array $facts, array $sourceMap): array
    {
        $stored = 0;
        $skipped = 0;

        DB::transaction(function () use ($project, $facts, $sourceMap, &$stored, &$skipped): void {
            foreach ($facts as $factData) {
                if (! $this->validFact($factData, $sourceMap)) {
                    $skipped++;

                    continue;
                }

                $source = $sourceMap[$factData['source_ref']];
                $key = trim($factData['fact_key']);
                $category = $factData['category'];
                $value = trim($factData['value']);
                $evidenceText = trim($factData['evidence']);

                $fact = $project->businessFacts()->firstOrCreate(
                    ['fact_key' => $key],
                    ['category' => $category],
                );

                $candidate = $fact->candidates()->firstOrCreate(
                    ['value_hash' => hash('sha256', $this->normalize($value))],
                    [
                        'candidate_value' => $value,
                        'ai_confidence' => $factData['confidence'] ?? null,
                        'verification_status' => 'pending',
                        'requires_clarification' => $factData['requires_clarification'] ?? false,
                        'clarification_note' => $factData['clarification_note'] ?? null,
                    ],
                );

                $evidenceHash = hash('sha256', implode('|', [
                    $factData['source_ref'],
                    $source['source_url'] ?? '',
                    $this->normalize($evidenceText),
                ]));

                $candidate->evidence()->firstOrCreate(
                    ['evidence_hash' => $evidenceHash],
                    [
                        'business_research_source_id' => $source['research_source_id'] ?? null,
                        'project_source_id' => $source['project_source_id'] ?? null,
                        'source_type' => $source['source_type'],
                        'source_reference' => $source['source_reference'] ?? $factData['source_ref'],
                        'source_url' => $source['source_url'] ?? null,
                        'supporting_evidence' => $evidenceText,
                    ],
                );

                $this->refreshConflictStatuses($fact);
                $stored++;
            }
        });

        return ['fact_count' => $stored, 'skipped_count' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $sourceMap
     */
    private function validFact(array $data, array $sourceMap): bool
    {
        foreach (['fact_key', 'category', 'value', 'source_ref', 'evidence'] as $required) {
            if (! isset($data[$required]) || ! is_string($data[$required])) {
                return false;
            }
        }

        if (! preg_match('/^[a-z][a-z0-9_.:-]{1,99}$/', $data['fact_key'])
            || ! in_array($data['category'], self::ALLOWED_CATEGORIES, true)
            || mb_strlen(trim($data['value'])) === 0
            || mb_strlen($data['value']) > 2000
            || mb_strlen(trim($data['evidence'])) === 0
            || mb_strlen($data['evidence']) > 5000
            || ! array_key_exists($data['source_ref'], $sourceMap)) {
            return false;
        }

        $confidence = $data['confidence'] ?? null;
        $requiresClarification = $data['requires_clarification'] ?? false;
        $clarificationNote = $data['clarification_note'] ?? null;

        if ($confidence !== null && (! is_numeric($confidence) || (float) $confidence < 0 || (float) $confidence > 1)) {
            return false;
        }

        if (! is_bool($requiresClarification)
            || ($clarificationNote !== null && (! is_string($clarificationNote) || mb_strlen($clarificationNote) > 500))) {
            return false;
        }

        $sourceText = $sourceMap[$data['source_ref']]['content'] ?? '';
        $normalizedSource = $this->normalize($sourceText);
        $normalizedEvidence = $this->normalize($data['evidence']);
        $normalizedValue = $this->normalize($data['value']);

        return $normalizedEvidence !== ''
            && str_contains($normalizedSource, $normalizedEvidence)
            && str_contains($normalizedEvidence, $normalizedValue);
    }

    private function refreshConflictStatuses(BusinessFact $fact): void
    {
        $candidates = $fact->candidates()
            ->where('verification_status', '!=', 'rejected')
            ->lockForUpdate()
            ->get();

        if ($candidates->count() > 1) {
            $fact->candidates()
                ->where('verification_status', '!=', 'rejected')
                ->update(['verification_status' => 'conflicting']);

            return;
        }

        $candidate = $candidates->first();

        if ($candidate?->verification_status === 'conflicting') {
            $candidate->update(['verification_status' => 'pending']);
        }
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
    }
}
