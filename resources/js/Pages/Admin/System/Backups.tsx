import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router } from '@inertiajs/react';
import { Database, Download, Terminal, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { dateTime, Empty } from '../bits';
import { BackupRow } from '../types';

type Props = {
    backups: BackupRow[];
    databaseSize: string | null;
};

export default function Backups({ backups, databaseSize }: Props) {
    const [running, setRunning] = useState(false);

    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-extrabold">Backups</h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            Database dumps.
                            {databaseSize && (
                                <> Current database size: {databaseSize}.</>
                            )}
                        </p>
                    </div>

                    <button
                        type="button"
                        disabled={running}
                        onClick={() => {
                            setRunning(true);
                            router.post(
                                route('admin.backups.create'),
                                {},
                                { onFinish: () => setRunning(false) },
                            );
                        }}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-60"
                    >
                        <Database className="size-4" />
                        {running ? 'Running…' : 'Take a backup now'}
                    </button>
                </div>
            }
        >
            <Head title="Backups — Control" />

            {/* Why there is no restore button. */}
            <section className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-5">
                <div className="flex items-start gap-2.5">
                    <Terminal className="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-300" />
                    <div className="min-w-0">
                        <h2 className="font-heading text-sm font-bold text-amber-900 dark:text-amber-100">
                            Restoring is a shell command, on purpose
                        </h2>
                        <p className="mt-1 text-sm text-amber-800 dark:text-amber-200">
                            Restoring from a web button is one mis-click from
                            overwriting every reseller's live data with a week-old
                            copy. Download the dump and run it where you can think
                            about it:
                        </p>
                        <pre className="scroll-slim mt-2 overflow-x-auto rounded-lg bg-amber-950/10 px-3 py-2 font-mono text-xs text-amber-900 dark:bg-black/30 dark:text-amber-100">
{`pg_restore --clean --if-exists \\
  -h <host> -U <user> -d <database> \\
  backup-YYYY-MM-DD_HHMMSS.dump`}
                        </pre>
                    </div>
                </div>
            </section>

            <section className="mt-4 rounded-xl border border-border bg-card p-5">
                <h2 className="font-heading font-bold">Dumps on disk</h2>
                <p className="mb-4 text-sm text-muted-foreground">
                    Stored outside the public directory and served only through this
                    screen — a dump is a complete copy of every reseller's data.
                </p>

                {backups.length === 0 ? (
                    <Empty>No backups yet.</Empty>
                ) : (
                    <ul className="space-y-2 text-sm">
                        {backups.map((backup) => (
                            <li
                                key={backup.file}
                                className="flex flex-wrap items-center gap-3 rounded-lg border border-border px-3 py-2"
                            >
                                <span className="min-w-0 flex-1 truncate font-mono text-xs">
                                    {backup.file}
                                </span>

                                <span className="shrink-0 tabular-nums text-muted-foreground">
                                    {formatBytes(backup.bytes)}
                                </span>

                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {dateTime(backup.at)}
                                </span>

                                <a
                                    href={route('admin.backups.download', backup.file)}
                                    className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-border px-2 py-1 text-xs font-medium transition-colors hover:bg-accent"
                                >
                                    <Download className="size-3.5" />
                                    Download
                                </a>

                                <DeleteButton file={backup.file} />
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </AdminLayout>
    );
}

function DeleteButton({ file }: { file: string }) {
    const [confirming, setConfirming] = useState(false);

    if (!confirming) {
        return (
            <button
                type="button"
                onClick={() => setConfirming(true)}
                aria-label="Delete backup"
                className="shrink-0 rounded-lg border border-destructive/30 p-1.5 text-destructive transition-colors hover:bg-destructive/10"
            >
                <Trash2 className="size-3.5" />
            </button>
        );
    }

    return (
        <button
            type="button"
            onClick={() => router.delete(route('admin.backups.delete', file))}
            className="shrink-0 rounded-lg bg-destructive px-2 py-1 text-xs font-semibold text-white transition-opacity hover:opacity-90"
        >
            Confirm
        </button>
    );
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(1)} ${units[unit]}`;
}
