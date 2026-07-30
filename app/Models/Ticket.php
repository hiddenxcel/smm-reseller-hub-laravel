<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'customer_identifier',
        'category',
        'subcategory',
        'order_ref',
        'subject',
        'status',
        'priority',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }
}
