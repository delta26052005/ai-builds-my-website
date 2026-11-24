<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessFactRun extends Model
{
    protected $fillable = [
        'status',
        'queued_at',
        'fact_count',
        'skipped_count',
        'error_code',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebsiteProject, $this>
     */
    public function websiteProject(): BelongsTo
    {
        return $this->belongsTo(WebsiteProject::class);
    }
}
