<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One person told about one thing, and whether WhatsApp took it. */
class StaffAlert extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'bot_type',
        'to_phone',
        'message',
        'status',
        'reason',
        'is_test',
    ];

    protected function casts(): array
    {
        return ['is_test' => 'boolean'];
    }
}
