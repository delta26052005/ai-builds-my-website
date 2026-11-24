<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProjectSource extends Model
{
    protected $fillable = [
        'original_filename',
        'stored_path',
        'mime_type',
        'file_size',
        'source_type',
        'processing_status',
    ];

    /**
     * @return BelongsTo<WebsiteProject, $this>
     */
    public function websiteProject(): BelongsTo
    {
        return $this->belongsTo(WebsiteProject::class);
    }

    /**
     * @return HasOne<SourceExtraction, $this>
     */
    public function extraction(): HasOne
    {
        return $this->hasOne(SourceExtraction::class);
    }

    /**
     * @return HasMany<BusinessFactEvidence, $this>
     */
    public function factEvidence(): HasMany
    {
        return $this->hasMany(BusinessFactEvidence::class);
    }
}
