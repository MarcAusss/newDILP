<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = [
        'province_id',
        'original_filename',
        'stored_path',
        'sheet_name',
        'status',
        'source_rows',
        'municipality_count',
        'barangay_count',
        'beneficiary_total',
        'undertaking_total',
        'regular_project_count',
        'group_project_count',
        'total_approved_projects',
        'group_beneficiary_total',
        'group_undertaking_total',
        'warnings',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'beneficiary_total' => 'decimal:2',
            'group_beneficiary_total' => 'decimal:2',
            'warnings' => 'array',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('sort_order');
    }
}
