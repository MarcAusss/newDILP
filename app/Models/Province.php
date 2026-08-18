<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'sheet_name',
        'miro_board_id',
        'miro_frame_id',
        'base_x',
        'base_y',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'base_x' => 'integer',
            'base_y' => 'integer',
        ];
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function generatedItems(): HasMany
    {
        return $this->hasMany(GeneratedMiroItem::class);
    }

    public function municipalityMappings(): HasMany
    {
        return $this->hasMany(MunicipalityMapping::class);
    }
}
