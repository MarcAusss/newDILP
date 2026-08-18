<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedMiroItem extends Model
{
    protected $fillable = [
        'province_id',
        'board_id',
        'stable_key',
        'item_type',
        'miro_item_id',
        'label',
        'x',
        'y',
        'meta',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
