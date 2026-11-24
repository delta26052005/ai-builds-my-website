<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WebsiteProject extends Model
{
    protected $fillable = [
        'business_name',
        'slug',
        'city',
        'state',
        'country',
        'business_category',
        'business_description',
        'business_email',
        'business_phone',
        'business_address',
        'website_url',
        'google_business_url',
        'social_links',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ProjectSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(ProjectSource::class);
    }

    /**
     * @return HasOne<BusinessResearchRun, $this>
     */
    public function businessResearchRun(): HasOne
    {
        return $this->hasOne(BusinessResearchRun::class);
    }

    /**
     * @return HasMany<BusinessResearchSource, $this>
     */
    public function businessResearchSources(): HasMany
    {
        return $this->hasMany(BusinessResearchSource::class);
    }

    /**
     * @return HasOne<BusinessFactRun, $this>
     */
    public function businessFactRun(): HasOne
    {
        return $this->hasOne(BusinessFactRun::class);
    }

    /**
     * @return HasMany<BusinessFact, $this>
     */
    public function businessFacts(): HasMany
    {
        return $this->hasMany(BusinessFact::class);
    }
}
