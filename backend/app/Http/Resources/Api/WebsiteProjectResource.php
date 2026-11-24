<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'slug' => $this->slug,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'business_category' => $this->business_category,
            'business_description' => $this->business_description,
            'business_email' => $this->business_email,
            'business_phone' => $this->business_phone,
            'business_address' => $this->business_address,
            'website_url' => $this->website_url,
            'google_business_url' => $this->google_business_url,
            'social_links' => $this->social_links,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'sources' => ProjectSourceResource::collection($this->whenLoaded('sources')),
        ];
    }
}
