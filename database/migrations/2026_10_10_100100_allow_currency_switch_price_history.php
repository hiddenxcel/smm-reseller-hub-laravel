<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;

/**
 * A price that moved because the shop changed currency is not a manual edit
 * or a bulk sweep, and the history only explains itself if it says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        CheckConstraint::drop('service_price_history', 'chk_svcprice_reason');
        CheckConstraint::in(
            'service_price_history',
            'reason',
            ['manual', 'bulk', 'rule', 'sync', 'import', 'currency_switch'],
            'chk_svcprice_reason',
        );
    }

    public function down(): void
    {
        CheckConstraint::drop('service_price_history', 'chk_svcprice_reason');
        CheckConstraint::in(
            'service_price_history',
            'reason',
            ['manual', 'bulk', 'rule', 'sync', 'import'],
            'chk_svcprice_reason',
        );
    }
};
