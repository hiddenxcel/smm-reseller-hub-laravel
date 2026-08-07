<?php

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The super-admin table was created with only a username and a hash — enough
     * to log in, not enough to run a console.
     *
     * `role` is a coarse grade rather than a permission list. There are a handful
     * of admins, not hundreds, and the operations that actually need guarding are
     * few (suspending a reseller, touching money, adding another admin), so a
     * role each controller can check beats a join table nobody maintains.
     *
     * `status` is how an admin is taken out of service. Deleting the row would
     * orphan the audit trail that points at it, and the audit trail is the whole
     * reason this console records anything.
     */
    public function up(): void
    {
        Schema::table('superadmins', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('username');
            $table->string('email', 190)->nullable()->unique()->after('name');
            $table->string('role', 20)->default('admin')->after('password_hash');
            $table->string('status', 20)->default('active')->after('role');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });

        CheckConstraint::in('superadmins', 'role', ['owner', 'admin', 'support'], 'chk_superadmins_role');
        CheckConstraint::in('superadmins', 'status', ['active', 'disabled'], 'chk_superadmins_status');
    }

    public function down(): void
    {
        CheckConstraint::drop('superadmins', 'chk_superadmins_role');
        CheckConstraint::drop('superadmins', 'chk_superadmins_status');

        Schema::table('superadmins', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'email',
                'role',
                'status',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
