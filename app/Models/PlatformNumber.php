<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-owned WhatsApp numbers available for rent (many countries, each
 * with its own currency and monthly cost). Not tenant-scoped — these belong
 * to the platform until rented.
 */
class PlatformNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'display_number',
        'phone_number_id',
        'cloud_api_token_enc',
        'waba_id',
        'country',
        'country_code',
        'currency',
        'monthly_cost',
        'status',
    ];

    protected $hidden = [
        'cloud_api_token_enc',
    ];

    protected function casts(): array
    {
        return [
            'cloud_api_token_enc' => 'encrypted',
            'monthly_cost' => 'decimal:2',
        ];
    }
}
