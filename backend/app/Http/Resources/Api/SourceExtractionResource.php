<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SourceExtractionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'extraction_method' => $this->extraction_method,
            'page_count' => $this->page_count,
            'metadata' => $this->metadata,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'processed_at' => $this->processed_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
