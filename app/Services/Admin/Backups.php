<?php

namespace App\Services\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Database dumps: taking them, listing them, downloading them.
 *
 * **There is deliberately no restore.** Restoring from a web button is one
 * mis-click from overwriting every reseller's live data with a week-old copy,
 * and the blast radius is the whole platform. Restoring is a shell command run
 * by someone who has thought about it:
 *
 *     pg_restore --clean --if-exists -d "$DB" backup.dump
 *
 * That is not a limitation to route around later — a restore that is slightly
 * inconvenient is a restore that happens on purpose.
 *
 * Dumps live on the `local` disk, outside public/, and are only ever served
 * through a controller that checks the admin's role. A backup is a complete
 * copy of every reseller's data, including encrypted credentials; a
 * predictable public URL would be the worst leak the platform could have.
 */
class Backups
{
    private const DIRECTORY = 'backups';

    /** How long a dump may take before it is assumed to have hung. */
    private const TIMEOUT_SECONDS = 600;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Run pg_dump into a timestamped file.
     *
     * Returns the filename on success. The custom format (-Fc) is used rather
     * than plain SQL because it restores selectively and compresses on the way
     * out — a plain dump of a busy platform is large and all-or-nothing.
     *
     * @return array{ok: bool, file?: string, error?: string}
     */
    public function create(): array
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            return [
                'ok' => false,
                'error' => 'Backups are only implemented for PostgreSQL.',
            ];
        }

        Storage::disk('local')->makeDirectory(self::DIRECTORY);

        $file = 'backup-'.Carbon::now()->format('Y-m-d_His').'.dump';
        $path = Storage::disk('local')->path(self::DIRECTORY.'/'.$file);

        $process = new Process(
            [
                'pg_dump',
                '--format=custom',
                '--no-owner',
                '--no-acl',
                '--file='.$path,
                '--host='.$config['host'],
                '--port='.$config['port'],
                '--username='.$config['username'],
                $config['database'],
            ],
            // The password goes through the environment rather than the command
            // line: arguments are visible to every process on the box.
            env: ['PGPASSWORD' => (string) $config['password']],
            timeout: self::TIMEOUT_SECONDS,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            // Remove the partial file — a truncated dump that looks like a
            // backup is worse than no backup.
            Storage::disk('local')->delete(self::DIRECTORY.'/'.$file);

            AdminAudit::record('backups.failed', [
                'error' => trim($process->getErrorOutput()) ?: 'pg_dump failed',
            ]);

            return [
                'ok' => false,
                'error' => trim($process->getErrorOutput()) ?: 'pg_dump failed.',
            ];
        }

        AdminAudit::record('backups.create', [
            'file' => $file,
            'bytes' => Storage::disk('local')->size(self::DIRECTORY.'/'.$file),
        ]);

        return ['ok' => true, 'file' => $file];
    }

    /**
     * Dumps on disk, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::DIRECTORY)) {
            return [];
        }

        return collect($disk->files(self::DIRECTORY))
            ->filter(fn (string $path) => str_ends_with($path, '.dump'))
            ->map(fn (string $path) => [
                'file' => basename($path),
                'bytes' => $disk->size($path),
                'at' => Carbon::createFromTimestamp($disk->lastModified($path))
                    ->toIso8601String(),
            ])
            ->sortByDesc('at')
            ->values()
            ->all();
    }

    /** Absolute path for a download, or null if the name is not one of ours. */
    public function pathFor(string $file): ?string
    {
        // Basename first: a name like ../../.env must not escape the directory.
        $file = basename($file);

        if (! str_ends_with($file, '.dump')) {
            return null;
        }

        $relative = self::DIRECTORY.'/'.$file;

        if (! Storage::disk('local')->exists($relative)) {
            return null;
        }

        AdminAudit::record('backups.download', ['file' => $file]);

        return Storage::disk('local')->path($relative);
    }

    public function delete(string $file): bool
    {
        $file = basename($file);

        if (! str_ends_with($file, '.dump')) {
            return false;
        }

        $relative = self::DIRECTORY.'/'.$file;

        if (! Storage::disk('local')->exists($relative)) {
            return false;
        }

        Storage::disk('local')->delete($relative);

        AdminAudit::record('backups.delete', ['file' => $file]);

        return true;
    }

    /** Rough size of what a dump would cover, for the screen's context. */
    public function databaseSize(): ?string
    {
        if (config('database.default') !== 'pgsql') {
            return null;
        }

        try {
            $row = DB::selectOne(
                'select pg_size_pretty(pg_database_size(current_database())) as size',
            );

            return $row?->size;
        } catch (\Throwable) {
            return null;
        }
    }
}
