<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Inbound/outbound bot message log, per tenant per customer. */
class BotMessage extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'customer_phone',
        'direction',
        'message',
        'template_key',
        'bot_type',
    ];
}
