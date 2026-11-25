<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteRevision extends Model
{
    protected $fillable = [
        'version_number',
        'content_json',
        'change_summary',
    ];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'version_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GeneratedWebsite, $this>
     */
    public function generatedWebsite(): BelongsTo
    {
        return $this->belongsTo(GeneratedWebsite::class);
    }
}
