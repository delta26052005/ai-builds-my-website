<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceExtraction extends Model
{
    protected $fillable = [
        'extracted_text',
        'extraction_method',
        'status',
        'queued_at',
        'page_count',
        'metadata',
        'error_code',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'queued_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProjectSource, $this>
     */
    public function projectSource(): BelongsTo
    {
        return $this->belongsTo(ProjectSource::class);
    }
}
