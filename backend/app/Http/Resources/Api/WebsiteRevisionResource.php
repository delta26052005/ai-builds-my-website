<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteRevisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'generated_website_id' => $this->generated_website_id,
            'version_number' => $this->version_number,
            'content_json' => $this->content_json,
            'change_summary' => $this->change_summary,
            'created_at' => $this->created_at,
        ];
    }
}
