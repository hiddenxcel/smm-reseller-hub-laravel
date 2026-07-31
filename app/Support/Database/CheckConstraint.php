<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The old MySQL schema used ENUM columns. Postgres enums are painful to alter
 * later, so columns are varchar with a CHECK constraint instead.
 *
 * SQLite (which the test suite uses for speed) cannot ADD CONSTRAINT after
 * the fact, so the constraint is skipped there — the application-level enum
 * casts still reject bad values, and the real constraint is exercised against
 * Postgres in CI and production.
 */
class CheckConstraint
{
    /** Constrain a column to a fixed set of values. */
    public static function in(string $table, string $column, array $allowed, ?string $name = null): void
    {
        if (! static::supported()) {
            return;
        }

        $name ??= "chk_{$table}_{$column}";
        $values = collect($allowed)
            ->map(fn (string $value) => "'".str_replace("'", "''", $value)."'")
            ->implode(', ');

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IN ({$values}))");
    }

    /**
     * Drop a constraint by name. IF EXISTS so a migration that narrows an
     * allowed set stays runnable against a database built before the
     * constraint was named, and re-runnable after a rollback.
     */
    public static function drop(string $table, string $name): void
    {
        if (! static::supported()) {
            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
    }

    private static function supported(): bool
    {
        return Schema::getConnection()->getDriverName() !== 'sqlite';
    }
}
