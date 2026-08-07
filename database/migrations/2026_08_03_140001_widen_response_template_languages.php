<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * `response_templates.lang` allowed en/fr/sw, but the bots ship five
     * locales — BotLang::SUPPORTED has tr and hi as well, and both have full
     * `lang/bot/*.php` files behind them.
     *
     * The constraint was written before those locales existed. Left as it was,
     * the Templates screen would refuse to save a Turkish or Hindi override
     * with a database error, for a language the bot already speaks.
     */
    public function up(): void
    {
        CheckConstraint::drop('response_templates', 'chk_template_lang');
        CheckConstraint::in('response_templates', 'lang', ['en', 'fr', 'sw', 'tr', 'hi'], 'chk_template_lang');
    }

    public function down(): void
    {
        CheckConstraint::drop('response_templates', 'chk_template_lang');
        CheckConstraint::in('response_templates', 'lang', ['en', 'fr', 'sw'], 'chk_template_lang');
    }
};
