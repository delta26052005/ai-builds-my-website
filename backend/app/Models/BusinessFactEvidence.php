<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessFactEvidence extends Model
{
    protected $fillable = [
        'business_research_source_id',
        'project_source_id',
        'source_type',
        'source_reference',
        'source_url',
        'supporting_evidence',
        'evidence_hash',
    ];

    /**
     * @return BelongsTo<BusinessFactCandidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(BusinessFactCandidate::class, 'business_fact_candidate_id');
    }

    /**
     * @return BelongsTo<BusinessResearchSource, $this>
     */
    public function researchSource(): BelongsTo
    {
        return $this->belongsTo(BusinessResearchSource::class);
    }

    /**
     * @return BelongsTo<ProjectSource, $this>
     */
    public function projectSource(): BelongsTo
    {
        return $this->belongsTo(ProjectSource::class);
    }
}
