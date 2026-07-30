<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide audit trail. actor_type distinguishes tenant / superadmin /
 * system, so this is not scoped to a single tenant.
 */
class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'activity_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_type',
        'actor_id',
        'action',
        'details',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }
}
