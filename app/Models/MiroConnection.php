<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MiroConnection extends Model
{
    protected $fillable = [
        'team_id',
        'user_id',
        'access_token',
        'refresh_token',
        'token_type',
        'scopes',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }
}
