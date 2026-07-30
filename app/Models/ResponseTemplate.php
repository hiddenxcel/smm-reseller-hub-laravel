<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Bot reply templates. tenant_id NULL = platform default, which a tenant row
 * overrides — so this is deliberately NOT tenant-scoped: resolution has to see
 * both the tenant's row and the fallback default.
 */
class ResponseTemplate extends Model
{
    use HasFactory;

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'template_key',
        'lang',
        'content',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * Most specific template wins: the tenant's own row before the platform
     * default, and the requested language before any other.
     */
    public static function resolve(int $tenantId, string $key, string $lang = 'en'): ?string
    {
        return static::where('template_key', $key)
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            // Postgres sorts false < true, so "tenant row first" is ASC on the
            // IS NULL test, and "requested language first" is DESC on the match.
            ->orderByRaw('(tenant_id IS NULL) ASC')
            ->orderByRaw('(lang = ?) DESC', [$lang])
            ->value('content');
    }
}
