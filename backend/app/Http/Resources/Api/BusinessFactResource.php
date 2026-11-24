<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessFactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $candidates = $this->whenLoaded('candidates');
        $activeCandidates = $candidates->filter(
            fn ($candidate): bool => $candidate->verification_status !== 'rejected',
        );
        $status = match (true) {
            $activeCandidates->count() > 1 => 'conflicting',
            $activeCandidates->isEmpty() => 'rejected',
            $activeCandidates->first()->verification_status === 'confirmed' => 'confirmed',
            default => 'pending',
        };

        return [
            'id' => $this->id,
            'fact_key' => $this->fact_key,
            'category' => $this->category,
            'status' => $status,
            'candidates' => BusinessFactCandidateResource::collection($candidates),
            'updated_at' => $this->updated_at,
        ];
    }
}
