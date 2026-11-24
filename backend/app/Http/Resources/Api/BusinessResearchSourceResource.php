<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResearchSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_url' => $this->source_url,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'access_status' => $this->access_status,
            'access_message' => $this->access_message,
            'fetched_at' => $this->fetched_at,
        ];
    }
}
