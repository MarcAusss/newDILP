<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    protected $fillable = [
        'import_batch_id',
        'sort_order',
        'municipality',
        'municipality_key',
        'barangay',
        'barangay_key',
        'is_group_project',
        'group_project_key',
        'group_project_label',
        'beneficiary_total',
        'undertaking_count',
        'undertakings',
        'source_rows',
    ];

    protected function casts(): array
    {
        return [
            'is_group_project' => 'boolean',
            'beneficiary_total' => 'decimal:2',
            'undertakings' => 'array',
            'source_rows' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
