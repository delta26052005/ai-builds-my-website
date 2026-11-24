<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessFact extends Model
{
    protected $fillable = [
        'fact_key',
        'category',
    ];

    /**
     * @return BelongsTo<WebsiteProject, $this>
     */
    public function websiteProject(): BelongsTo
    {
        return $this->belongsTo(WebsiteProject::class);
    }

    /**
     * @return HasMany<BusinessFactCandidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(BusinessFactCandidate::class);
    }
}
