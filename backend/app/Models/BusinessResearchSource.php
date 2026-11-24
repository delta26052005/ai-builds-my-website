<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessResearchSource extends Model
{
    protected $fillable = [
        'source_type',
        'source_url',
        'url_hash',
        'title',
        'snippet',
        'content',
        'access_status',
        'access_message',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<WebsiteProject, $this>
     */
    public function websiteProject(): BelongsTo
    {
        return $this->belongsTo(WebsiteProject::class);
    }

    /**
     * @return HasMany<BusinessFactEvidence, $this>
     */
    public function factEvidence(): HasMany
    {
        return $this->hasMany(BusinessFactEvidence::class);
    }
}
