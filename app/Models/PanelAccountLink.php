<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp number linked to an account on the reseller's own panel. See the
 * migration for how the link is proven. Always read unscoped: it is used from
 * webhooks, which have no session.
 */
class PanelAccountLink extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'panel_id',
        'customer_phone',
        'panel_user_id',
        'panel_username',
        'verified_at',
        'pending_user_id',
        'pending_username',
        'code_hash',
        'code_expires_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'code_expires_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->panel_user_id !== null;
    }
}
