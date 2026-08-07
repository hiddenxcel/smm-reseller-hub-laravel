<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record of one API call. Written for every request, including the ones
 * that never got as far as a key — see the table migration for why.
 */
class ApiLog extends Model
{
    use BelongsToTenant, HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'api_key_id',
        'action',
        'ip',
        'ok',
        'error',
        'details',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'details' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id');
    }
}
