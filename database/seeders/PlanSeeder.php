<?php

namespace Database\Seeders;

use App\Enums\ServiceKey;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The a la carte pricing catalog (one plan per service, USD base).
 * Idempotent: re-running updates prices without creating duplicates.
 * Yearly = monthly * 12 * 0.8 (20% off).
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'order_bot',
                'service_key' => ServiceKey::OrderBot,
                'name' => 'Order Bot',
                'description' => 'Customers place orders through WhatsApp.',
                'price_monthly' => 17.00,
                'price_yearly' => 163.20,
                'max_panels' => 5,
                'sort_order' => 1,
            ],
            [
                'code' => 'support_bot',
                'service_key' => ServiceKey::SupportBot,
                'name' => 'Support Bot',
                'description' => 'Refill, status, cancel and speed-up, automatically.',
                'price_monthly' => 17.00,
                'price_yearly' => 163.20,
                'max_panels' => 5,
                'sort_order' => 2,
            ],
            [
                'code' => 'ai_tickets',
                'service_key' => ServiceKey::AiTickets,
                'name' => 'AI Tickets',
                'description' => 'AI-powered support tickets inside your website.',
                'price_monthly' => 11.00,
                'price_yearly' => 105.60,
                'max_panels' => 1,
                'sort_order' => 3,
            ],
            [
                'code' => 'ai_chat',
                'service_key' => ServiceKey::AiChat,
                'name' => 'AI Chat',
                'description' => 'AI Support inside your Order Bot — answers customers automatically on WhatsApp.',
                'price_monthly' => 5.00,
                'price_yearly' => 48.00,
                'max_panels' => 1,
                'sort_order' => 4,
            ],
            [
                'code' => 'number_rental',
                'service_key' => ServiceKey::NumberRental,
                'name' => 'Rent a Number',
                'description' => 'Rent a Cloud API number when you have no Meta Business account.',
                'price_monthly' => 11.00,
                'price_yearly' => 105.60,
                'max_panels' => 1,
                'sort_order' => 5,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['code' => $plan['code']],
                [...$plan, 'currency' => 'USD', 'max_numbers' => 1, 'status' => 'active'],
            );
        }
    }
}
