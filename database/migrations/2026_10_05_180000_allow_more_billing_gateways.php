<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const OLD = ['nowpayments', 'binance', 'snippe', 'cryptomus', 'heleket'];

    private const NEW = [
        'snippe_ke', 'snippe_ug',
        'fimipay_ng', 'fimipay_gh', 'fimipay_cm', 'fimipay_za', 'fimipay_usd',
    ];

    public function up(): void
    {
        CheckConstraint::drop('subscription_payments', 'chk_subpay_gateway');
        CheckConstraint::in('subscription_payments', 'gateway', [...self::OLD, ...self::NEW], 'chk_subpay_gateway');
    }

    public function down(): void
    {
        CheckConstraint::drop('subscription_payments', 'chk_subpay_gateway');
        CheckConstraint::in('subscription_payments', 'gateway', self::OLD, 'chk_subpay_gateway');
    }
};
