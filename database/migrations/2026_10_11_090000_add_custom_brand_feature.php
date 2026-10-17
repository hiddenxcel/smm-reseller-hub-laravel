<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = ['order_bot', 'support_bot', 'ai_tickets', 'ai_chat', 'number_rental'];

    public function up(): void
    {
        $all = [...self::OLD, 'custom_brand'];

        CheckConstraint::drop('plans', 'chk_plans_service_key');
        CheckConstraint::in('plans', 'service_key', $all, 'chk_plans_service_key');
        CheckConstraint::drop('subscriptions', 'chk_subs_service_key');
        CheckConstraint::in('subscriptions', 'service_key', $all, 'chk_subs_service_key');

        // Price lives in the plans table like every other service. Left alone
        // if it already exists, so a price edited in the console survives.
        if (! DB::table('plans')->where('code', 'custom_brand')->exists()) {
            DB::table('plans')->insert([
                'code' => 'custom_brand',
                'service_key' => 'custom_brand',
                'name' => 'Your own brand name',
                'description' => 'Your business name in the footer of every bot message, instead of ours.',
                'price_monthly' => 2.00,
                'price_yearly' => 19.20,
                'currency' => 'USD',
                'max_panels' => 1,
                'max_numbers' => 1,
                'status' => 'active',
                'sort_order' => 6,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('subscriptions')->where('service_key', 'custom_brand')->delete();
        DB::table('plans')->where('code', 'custom_brand')->delete();

        CheckConstraint::drop('plans', 'chk_plans_service_key');
        CheckConstraint::in('plans', 'service_key', self::OLD, 'chk_plans_service_key');
        CheckConstraint::drop('subscriptions', 'chk_subs_service_key');
        CheckConstraint::in('subscriptions', 'service_key', self::OLD, 'chk_subs_service_key');
    }
};
