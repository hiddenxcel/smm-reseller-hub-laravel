import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Info, Plus, Send, Trash2, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { dateTime, Empty } from '../bits';
import { AnnouncementRow, AnnouncementState } from '../types';

type Props = {
    announcements: AnnouncementRow[];
    canManage: boolean;
};

export default function AnnouncementsIndex({ announcements, canManage }: Props) {
    const [editing, setEditing] = useState<AnnouncementRow | null>(null);
    const [creating, setCreating] = useState(false);

    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-extrabold">
                            Announcements
                        </h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            Notices shown on every reseller's dashboard.
                        </p>
                    </div>

                    {canManage && (
                        <button
                            type="button"
                            onClick={() => {
                                setEditing(null);
                                setCreating(true);
                            }}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                        >
                            <Plus className="size-4" />
                            New announcement
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Announcements — Control" />

            {/* Why this is a banner and not a WhatsApp message. */}
            <p className="rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                These appear on the reseller dashboard, not through their WhatsApp
                number — that number is their business asset and their Meta rate
                limit, and spending it to talk to them would cost them a template
                they need for their own customers.
            </p>

            {announcements.length === 0 ? (
                <Empty>Nothing written yet.</Empty>
            ) : (
                <div className="mt-4 space-y-3">
                    {announcements.map((announcement) => (
                        <Card
                            key={announcement.id}
                            announcement={announcement}
                            canManage={canManage}
                            onEdit={() => {
                                setCreating(false);
                                setEditing(announcement);
                            }}
                        />
                    ))}
                </div>
            )}

            {(editing || creating) && (
                <Dialog
                    announcement={editing}
                    onClose={() => {
                        setEditing(null);
                        setCreating(false);
                    }}
                />
            )}
        </AdminLayout>
    );
}

function Card({
    announcement,
    canManage,
    onEdit,
}: {
    announcement: AnnouncementRow;
    canManage: boolean;
    onEdit: () => void;
}) {
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const live = announcement.state === 'live';

    return (
        <div
            className={[
                'rounded-xl border bg-card p-5',
                live ? 'border-primary/40' : 'border-border',
            ].join(' ')}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <LevelIcon level={announcement.level} />
                        <h2 className="font-heading truncate font-bold">
                            {announcement.title}
                        </h2>
                        <StateChip state={announcement.state} />
                    </div>

                    <p className="mt-2 whitespace-pre-line text-sm text-muted-foreground">
                        {announcement.body}
                    </p>
                </div>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-border pt-3 text-xs text-muted-foreground">
                {announcement.author && <span>by {announcement.author}</span>}
                {announcement.publishedAt && (
                    <span>
                        {announcement.state === 'scheduled' ? 'shows' : 'published'}{' '}
                        {dateTime(announcement.publishedAt)}
                    </span>
                )}
                {announcement.expiresAt && (
                    <span>until {dateTime(announcement.expiresAt)}</span>
                )}
                {!announcement.dismissible && (
                    <span className="font-medium">cannot be dismissed</span>
                )}
            </div>

            {canManage && (
                <div className="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={onEdit}
                        className="rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-accent"
                    >
                        Edit
                    </button>

                    {live || announcement.state === 'scheduled' ? (
                        <button
                            type="button"
                            onClick={() =>
                                router.post(
                                    route('admin.announcements.act', [
                                        announcement.id,
                                        'unpublish',
                                    ]),
                                )
                            }
                            className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-accent"
                        >
                            <Undo2 className="size-3.5" />
                            Take down
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={() =>
                                router.post(
                                    route('admin.announcements.act', [
                                        announcement.id,
                                        'publish',
                                    ]),
                                )
                            }
                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-2.5 py-1.5 text-xs font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                        >
                            <Send className="size-3.5" />
                            Publish to everyone
                        </button>
                    )}

                    {confirmingDelete ? (
                        <button
                            type="button"
                            onClick={() =>
                                router.post(
                                    route('admin.announcements.act', [
                                        announcement.id,
                                        'delete',
                                    ]),
                                )
                            }
                            className="rounded-lg bg-destructive px-2.5 py-1.5 text-xs font-semibold text-white transition-opacity hover:opacity-90"
                        >
                            Confirm delete
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={() => setConfirmingDelete(true)}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-destructive/30 px-2.5 py-1.5 text-xs font-medium text-destructive transition-colors hover:bg-destructive/10"
                        >
                            <Trash2 className="size-3.5" />
                            Delete
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

function LevelIcon({ level }: { level: string }) {
    if (level === 'info') {
        return <Info className="size-4 shrink-0 text-muted-foreground" aria-hidden />;
    }

    return (
        <AlertTriangle
            className={[
                'size-4 shrink-0',
                level === 'critical'
                    ? 'text-destructive'
                    : 'text-amber-600 dark:text-amber-400',
            ].join(' ')}
            aria-hidden
        />
    );
}

function StateChip({ state }: { state: AnnouncementState }) {
    const styles: Record<AnnouncementState, string> = {
        live: 'bg-[#006300]/10 text-[#006300] dark:bg-[#0ca30c]/15 dark:text-[#0ca30c]',
        scheduled: 'bg-primary/10 text-primary',
        draft: 'bg-muted text-muted-foreground',
        expired: 'bg-muted text-muted-foreground',
    };

    return (
        <span
            className={`inline-flex shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase ${styles[state]}`}
        >
            {state}
        </span>
    );
}

function Dialog({
    announcement,
    onClose,
}: {
    announcement: AnnouncementRow | null;
    onClose: () => void;
}) {
    const isNew = announcement === null;

    const { data, setData, post, patch, processing, errors } = useForm({
        title: announcement?.title ?? '',
        body: announcement?.body ?? '',
        level: announcement?.level ?? 'info',
        dismissible: announcement?.dismissible ?? true,
        published_at: announcement?.publishedAt?.slice(0, 16) ?? '',
        expires_at: announcement?.expiresAt?.slice(0, 16) ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (isNew) {
            post(route('admin.announcements.store'), { onSuccess: onClose });
        } else {
            patch(route('admin.announcements.update', announcement.id), {
                onSuccess: onClose,
            });
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-foreground/40 p-4">
            <form
                onSubmit={submit}
                className="my-8 w-full max-w-lg rounded-xl border border-border bg-card p-6"
            >
                <h2 className="font-heading text-lg font-extrabold">
                    {isNew ? 'New announcement' : 'Edit announcement'}
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Saved as a draft. Nothing appears until you publish it.
                </p>

                <div className="mt-4 space-y-3">
                    <Field label="Title" error={errors.title}>
                        <input
                            type="text"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            maxLength={150}
                            autoFocus
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Message" error={errors.body}>
                        <textarea
                            value={data.body}
                            onChange={(e) => setData('body', e.target.value)}
                            rows={4}
                            maxLength={4000}
                            className={inputClass}
                        />
                    </Field>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Level" error={errors.level}>
                            <select
                                value={data.level}
                                onChange={(e) =>
                                    setData('level', e.target.value as typeof data.level)
                                }
                                className={inputClass}
                            >
                                <option value="info">Info</option>
                                <option value="warning">Warning</option>
                                <option value="critical">Critical</option>
                            </select>
                        </Field>

                        <Field label="Dismissible">
                            <select
                                value={data.dismissible ? '1' : '0'}
                                onChange={(e) =>
                                    setData('dismissible', e.target.value === '1')
                                }
                                className={inputClass}
                            >
                                <option value="1">Resellers can dismiss it</option>
                                <option value="0">Always shown</option>
                            </select>
                        </Field>

                        <Field
                            label="Show from (optional)"
                            error={errors.published_at}
                        >
                            <input
                                type="datetime-local"
                                value={data.published_at}
                                onChange={(e) => setData('published_at', e.target.value)}
                                className={inputClass}
                            />
                        </Field>

                        <Field label="Hide after (optional)" error={errors.expires_at}>
                            <input
                                type="datetime-local"
                                value={data.expires_at}
                                onChange={(e) => setData('expires_at', e.target.value)}
                                className={inputClass}
                            />
                        </Field>
                    </div>

                    <p className="text-xs text-muted-foreground">
                        A future "show from" schedules it. Leaving "hide after" empty
                        keeps it up until you take it down — set one for a
                        maintenance notice so it disappears on its own.
                    </p>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-border px-3 py-2 text-sm transition-colors hover:bg-accent"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : isNew ? 'Save draft' : 'Save changes'}
                    </button>
                </div>
            </form>
        </div>
    );
}

const inputClass =
    'w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className="mb-1 block text-xs font-medium text-muted-foreground">
                {label}
            </label>
            {children}
            {error && <p className="mt-1 text-xs text-destructive">{error}</p>}
        </div>
    );
}
