<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneratedWebsite extends Model
{
    protected $fillable = [
        'title',
        'template_key',
        'content_json',
        'status',
        'current_version',
        'generation_token',
        'queued_at',
        'error_code',
        'error_message',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'current_version' => 'integer',
            'generated_at' => 'datetime',
            'queued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebsiteProject, $this>
     */
    public function websiteProject(): BelongsTo
    {
        return $this->belongsTo(WebsiteProject::class);
    }

    /**
     * @return HasMany<WebsiteRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(WebsiteRevision::class);
    }
}
