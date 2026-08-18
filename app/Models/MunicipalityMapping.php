<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MunicipalityMapping extends Model
{
    protected $fillable = [
        'province_id',
        'municipality',
        'municipality_key',
        'sort_order',
        'anchor_x',
        'anchor_y',
        'panel_x',
        'panel_y',
        'flow_direction',
        'configured',
    ];

    protected function casts(): array
    {
        return [
            'anchor_x' => 'integer',
            'anchor_y' => 'integer',
            'panel_x' => 'integer',
            'panel_y' => 'integer',
            'configured' => 'boolean',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
