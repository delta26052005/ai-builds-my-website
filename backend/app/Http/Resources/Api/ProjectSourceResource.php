<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'source_type' => $this->source_type,
            'processing_status' => $this->processing_status,
            'extraction' => $this->whenLoaded('extraction', fn () => $this->extraction === null ? null : [
                'status' => $this->extraction->status,
                'extraction_method' => $this->extraction->extraction_method,
                'page_count' => $this->extraction->page_count,
                'text_preview' => $this->extraction->status === 'completed'
                    ? mb_substr($this->extraction->extracted_text ?? '', 0, 500)
                    : null,
                'metadata' => $this->extraction->metadata,
                'error_code' => $this->extraction->error_code,
                'error_message' => $this->extraction->error_message,
                'processed_at' => $this->extraction->processed_at,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
