<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
