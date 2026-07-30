<?php

namespace App\Models;

use App\Enums\ServiceKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A la carte pricing catalog: one plan per service.
 * Platform-owned (not tenant-scoped).
 */
class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'service_key',
        'price_monthly',
        'price_yearly',
        'currency',
        'max_panels',
        'max_numbers',
        'max_orders_monthly',
        'max_messages_monthly',
        'max_refills_monthly',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'service_key' => ServiceKey::class,
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
        ];
    }

    public static function forService(ServiceKey|string $service): ?self
    {
        return static::where('service_key', $service instanceof ServiceKey ? $service->value : $service)
            ->where('status', 'active')
            ->first();
    }
}
