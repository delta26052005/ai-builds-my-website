<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeneratedWebsiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'website_project_id' => $this->website_project_id,
            'title' => $this->title,
            'template_key' => $this->template_key,
            'content_json' => $this->content_json,
            'status' => $this->status,
            'current_version' => $this->current_version,
            'queued_at' => $this->queued_at,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'generated_at' => $this->generated_at,
            'is_published' => $this->is_published,
            'public_slug' => $this->public_slug,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
