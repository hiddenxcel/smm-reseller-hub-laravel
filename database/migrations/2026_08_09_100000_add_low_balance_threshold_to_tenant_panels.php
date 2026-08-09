<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When to warn a reseller that a panel is running out of money.
 *
 * This was a single constant of $5 for everybody, which is the wrong shape for
 * the question. A reseller running a $30 float and one running $3,000 are
 * different businesses, and $5 is either a useful warning or a notice that the
 * money already ran out — depending on which of them is reading it.
 *
 * Per panel rather than per reseller, because the same reseller can hold both:
 * a main panel carrying the volume and a second one kept topped up for a
 * single service. One figure across both would be wrong for one of them.
 *
 * Null means "use the default", not "never warn". A reseller who has never
 * thought about this still gets warned, which is the entire point of the
 * feature; turning the warning off is a separate decision and deliberately
 * not expressible here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_panels', function (Blueprint $table) {
            $table->decimal('low_balance_threshold', 12, 2)->nullable()->after('balance_currency');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_panels', function (Blueprint $table) {
            $table->dropColumn('low_balance_threshold');
        });
    }
};
