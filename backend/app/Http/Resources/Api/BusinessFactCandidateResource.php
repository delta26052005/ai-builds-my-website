<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessFactCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'value' => $this->candidate_value,
            'verification_status' => $this->verification_status,
            'ai_confidence' => $this->ai_confidence,
            'requires_clarification' => $this->requires_clarification,
            'clarification_note' => $this->clarification_note,
            'evidence' => BusinessFactEvidenceResource::collection($this->whenLoaded('evidence')),
            'created_at' => $this->created_at,
        ];
    }
}
