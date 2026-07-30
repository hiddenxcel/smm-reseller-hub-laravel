<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantAi extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'tenant_ai';

    protected $fillable = [
        'tenant_id',
        'deepseek_api_key_enc',
        'status',
    ];

    protected $hidden = [
        'deepseek_api_key_enc',
    ];

    protected function casts(): array
    {
        return [
            'deepseek_api_key_enc' => 'encrypted',
        ];
    }
}
