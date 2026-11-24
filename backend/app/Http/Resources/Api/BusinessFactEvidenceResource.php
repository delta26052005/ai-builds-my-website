<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessFactEvidenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_reference' => $this->source_reference,
            'source_url' => $this->source_url,
            'research_source_id' => $this->business_research_source_id,
            'project_source_id' => $this->project_source_id,
            'supporting_evidence' => $this->supporting_evidence,
        ];
    }
}
