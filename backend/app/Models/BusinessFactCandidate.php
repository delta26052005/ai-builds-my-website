<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessFactCandidate extends Model
{
    protected $fillable = [
        'candidate_value',
        'value_hash',
        'ai_confidence',
        'verification_status',
        'requires_clarification',
        'clarification_note',
    ];

    protected function casts(): array
    {
        return [
            'ai_confidence' => 'float',
            'requires_clarification' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<BusinessFact, $this>
     */
    public function businessFact(): BelongsTo
    {
        return $this->belongsTo(BusinessFact::class);
    }

    /**
     * @return HasMany<BusinessFactEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(BusinessFactEvidence::class);
    }
}
