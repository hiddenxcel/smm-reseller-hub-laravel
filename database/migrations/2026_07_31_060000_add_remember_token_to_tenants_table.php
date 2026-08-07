<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Both auth models extend Authenticatable, which writes remember_token
 * whenever someone ticks "remember me" — so the column has to exist. Without
 * it the credentials check passes and the request then dies on the write,
 * which is a confusing way to fail: it looks like the password was wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->rememberToken();
        });

        Schema::table('superadmins', function (Blueprint $table) {
            $table->rememberToken();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('remember_token');
        });

        Schema::table('superadmins', function (Blueprint $table) {
            $table->dropColumn('remember_token');
        });
    }
};
