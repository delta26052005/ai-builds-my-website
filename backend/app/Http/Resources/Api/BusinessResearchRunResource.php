<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResearchRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status,
            'source_count' => $this->source_count,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'processed_at' => $this->processed_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
