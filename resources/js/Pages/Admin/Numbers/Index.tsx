import PhoneInput from '@/components/PhoneInput';
import { COUNTRIES, flag, splitNumber } from '@/lib/countries';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ChevronDown,
    CircleCheck,
    CircleX,
    Copy,
    ExternalLink,
    KeyRound,
    Loader2,
    Pencil,
    Plus,
    Power,
    Radio,
    Search,
    ShieldCheck,
    Smartphone,
    Trash2,
    Undo2,
} from 'lucide-react';
import { FormEvent, ReactNode, useMemo, useState } from 'react';
import { Empty, shortDate } from '../bits';

type NumberRow = {
    id: number;
    displayNumber: string;
    phoneNumberId: string;
    wabaId: string | null;
    country: string | null;
    countryCode: string | null;
    price: number;
    status: 'available' | 'rented' | 'suspended';
    tokenSaved: boolean;
    tokenHint: string | null;
    rentedTo: { id: number; business: string; email: string; since: string | null } | null;
    timesRented: number;
    addedAt: string | null;
};

type Props = {
    numbers: NumberRow[];
    stats: {
        total: number;
        available: number;
        rented: number;
        suspended: number;
        countries: number;
        rentedValue: number;
    };
    setup: {
        webhookUrl: string;
        verifyToken: string;
        appSecretSet: boolean;
        verifyTokenSet: boolean;
        lastMessageAt: string | null;
    };
    currency: string;
    canManage: boolean;
};

type Verdict =
    | { ok: true; display_number?: string; name?: string; quality?: string; status?: string }
    | { ok: false; error: string };

type Filter = 'all' | 'available' | 'rented' | 'suspended';

const inputClass =
    'w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none transition-shadow focus:border-ring focus:ring-3 focus:ring-ring/40';

function price(value: number, currency: string): string {
    return `${currency === 'USD' ? '$' : `${currency} `}${value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function ago(iso: string | null): string {
    if (!iso) {
        return 'never';
    }

    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 90) {
        return 'just now';
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)} min ago`;
    }

    if (seconds < 86400) {
        return `${Math.round(seconds / 3600)} h ago`;
    }

    return `${Math.round(seconds / 86400)} d ago`;
}

function xsrf(): string {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

/**
 * Ask Meta whether a number and its token work together.
 *
 * fetch rather than an Inertia visit: nothing is saved and the page must not
 * move — the answer is a line of text in the form the admin is filling in.
 */
async function verify(body: { phone_number_id: string; token?: string; number_id?: number }): Promise<Verdict> {
    try {
        const response = await fetch(route('admin.numbers.verify'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify(body),
        });

        if (!response.ok) {
            return { ok: false, error: 'The check could not run. Try again.' };
        }

        return (await response.json()) as Verdict;
    } catch {
        return { ok: false, error: 'The check could not run. Try again.' };
    }
}

export default function NumbersIndex({ numbers, stats, setup, currency, canManage }: Props) {
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');
    const [editing, setEditing] = useState<NumberRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [confirm, setConfirm] = useState<{ number: NumberRow; kind: 'release' | 'delete' } | null>(null);

    const shown = useMemo(() => {
        const needle = query.trim().toLowerCase().replace(/\s/g, '');

        return numbers.filter((number) => {
            if (filter !== 'all' && number.status !== filter) {
                return false;
            }

            if (needle === '') {
                return true;
            }

            return [
                number.displayNumber,
                number.country ?? '',
                number.phoneNumberId,
                number.rentedTo?.business ?? '',
            ]
                .join(' ')
                .toLowerCase()
                .replace(/\s/g, '')
                .includes(needle);
        });
    }, [numbers, filter, query]);

    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-extrabold">Numbers</h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            The WhatsApp numbers resellers can rent. Add one here and it is on sale at once.
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
                            Add number
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Numbers — Control" />

            <div className="space-y-5">
                <SetupCard setup={setup} numbers={stats.total} />

                {/* ---- stats ---- */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat label="Available to rent" value={stats.available} tone="good" />
                    <Stat label="Rented out" value={stats.rented} tone="info" />
                    <Stat label="Suspended" value={stats.suspended} tone="muted" />
                    <Stat
                        label="Value out on rent"
                        value={price(stats.rentedValue, currency)}
                        hint={`${stats.countries} countr${stats.countries === 1 ? 'y' : 'ies'} in the pool`}
                    />
                </div>

                {/* ---- toolbar ---- */}
                {numbers.length > 0 && (
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="flex gap-1 rounded-lg bg-muted p-1">
                            {(
                                [
                                    ['all', 'All', stats.total],
                                    ['available', 'Available', stats.available],
                                    ['rented', 'Rented', stats.rented],
                                    ['suspended', 'Suspended', stats.suspended],
                                ] as const
                            ).map(([key, label, count]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setFilter(key)}
                                    className={[
                                        'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                        filter === key
                                            ? 'bg-card text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    ].join(' ')}
                                >
                                    {label}
                                    <span className="ml-1.5 text-xs tabular-nums opacity-60">{count}</span>
                                </button>
                            ))}
                        </div>

                        <label className="relative min-w-52 flex-1 sm:max-w-xs">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Search number, country or reseller"
                                className={`${inputClass} pl-9`}
                            />
                        </label>
                    </div>
                )}

                {/* ---- the numbers ---- */}
                {numbers.length === 0 ? (
                    <FirstNumber canManage={canManage} onAdd={() => setCreating(true)} />
                ) : shown.length === 0 ? (
                    <Empty>No numbers match.</Empty>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                        {shown.map((number) => (
                            <NumberCard
                                key={number.id}
                                number={number}
                                currency={currency}
                                canManage={canManage}
                                onEdit={() => {
                                    setCreating(false);
                                    setEditing(number);
                                }}
                                onConfirm={(kind) => setConfirm({ number, kind })}
                            />
                        ))}
                    </div>
                )}
            </div>

            {(editing || creating) && (
                <NumberDialog
                    number={editing}
                    currency={currency}
                    onClose={() => {
                        setEditing(null);
                        setCreating(false);
                    }}
                />
            )}

            {confirm && <ConfirmDialog confirm={confirm} onClose={() => setConfirm(null)} />}
        </AdminLayout>
    );
}

/* ----------------------------------------------------------------- setup -- */

function SetupCard({ setup, numbers }: { setup: Props['setup']; numbers: number }) {
    const problems = [!setup.appSecretSet, !setup.verifyTokenSet, numbers === 0].filter(Boolean).length;
    const [open, setOpen] = useState(problems > 0);

    const rows: Array<{ ok: boolean; title: string; fix: string }> = [
        {
            ok: setup.appSecretSet,
            title: 'Meta app secret is on the server',
            fix: 'Without it every incoming message is refused and no bot ever answers. Meta app → App settings → Basic → App secret, then set META_APP_SECRET in the server .env.',
        },
        {
            ok: setup.verifyTokenSet,
            title: 'Webhook verify token is set',
            fix: 'Set META_VERIFY_TOKEN in the server .env, then paste the same value into Meta.',
        },
        {
            ok: numbers > 0,
            title: 'At least one number is in the pool',
            fix: 'Add a number below, so resellers have something to rent.',
        },
    ];

    return (
        <section className="overflow-hidden rounded-xl border border-border bg-card">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                className="flex w-full items-center gap-3 px-5 py-4 text-left"
            >
                <span
                    className={[
                        'flex size-9 shrink-0 items-center justify-center rounded-xl',
                        problems === 0 ? 'bg-emerald-500/15 text-emerald-600' : 'bg-amber-500/15 text-amber-600',
                    ].join(' ')}
                >
                    {problems === 0 ? <ShieldCheck className="size-5" /> : <AlertTriangle className="size-5" />}
                </span>

                <span className="min-w-0 flex-1">
                    <span className="block font-heading font-bold">
                        {problems === 0 ? 'Ready to receive messages' : `${problems} thing${problems === 1 ? '' : 's'} to sort out`}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        <Radio className="mr-1 inline size-3" />
                        Last message received: {ago(setup.lastMessageAt)}
                    </span>
                </span>

                <ChevronDown className={['size-4 text-muted-foreground transition-transform', open ? 'rotate-180' : ''].join(' ')} />
            </button>

            {open && (
                <div className="space-y-4 border-t border-border px-5 py-4">
                    <ul className="space-y-2.5">
                        {rows.map((row) => (
                            <li key={row.title} className="flex items-start gap-2.5 text-sm">
                                {row.ok ? (
                                    <CircleCheck className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                                ) : (
                                    <CircleX className="mt-0.5 size-4 shrink-0 text-destructive" />
                                )}
                                <span>
                                    <span className={row.ok ? 'font-medium' : 'font-semibold'}>{row.title}</span>
                                    {!row.ok && <span className="mt-0.5 block text-xs text-muted-foreground">{row.fix}</span>}
                                </span>
                            </li>
                        ))}
                    </ul>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <CopyField label="Callback URL" value={setup.webhookUrl} />
                        <CopyField label="Verify token" value={setup.verifyToken || 'Not set'} disabled={!setup.verifyToken} />
                    </div>

                    <p className="text-xs text-muted-foreground">
                        In Meta: WhatsApp → Configuration → Edit webhook, paste both, then subscribe to{' '}
                        <strong>messages</strong>. This is done once for the whole platform, not per number.
                    </p>
                </div>
            )}
        </section>
    );
}

/* ----------------------------------------------------------------- small -- */

function CopyField({ label, value, disabled }: { label: string; value: string; disabled?: boolean }) {
    const [done, setDone] = useState(false);

    return (
        <div>
            <p className="mb-1 text-xs font-medium text-muted-foreground">{label}</p>
            <div className="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2">
                <code className="min-w-0 flex-1 truncate font-data text-xs">{value}</code>
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => {
                        void navigator.clipboard?.writeText(value).then(() => {
                            setDone(true);
                            window.setTimeout(() => setDone(false), 1500);
                        });
                    }}
                    aria-label={`Copy ${label}`}
                    className="shrink-0 text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
                >
                    {done ? <Check className="size-4 text-emerald-600" /> : <Copy className="size-4" />}
                </button>
            </div>
        </div>
    );
}

function Stat({
    label,
    value,
    hint,
    tone,
}: {
    label: string;
    value: ReactNode;
    hint?: string;
    tone?: 'good' | 'info' | 'muted';
}) {
    const dot =
        tone === 'good' ? 'bg-emerald-500' : tone === 'info' ? 'bg-sky-500' : tone === 'muted' ? 'bg-muted-foreground/50' : '';

    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                {dot && <span className={`size-1.5 rounded-full ${dot}`} />}
                {label}
            </p>
            <p className="font-heading mt-1.5 text-2xl font-extrabold tabular-nums">{value}</p>
            {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

function Status({ status }: { status: NumberRow['status'] }) {
    const style =
        status === 'available'
            ? 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-400'
            : status === 'rented'
              ? 'bg-sky-500/12 text-sky-700 dark:text-sky-400'
              : 'bg-muted text-muted-foreground';

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold capitalize ${style}`}>
            <span className="size-1.5 rounded-full bg-current" />
            {status}
        </span>
    );
}

function FirstNumber({ canManage, onAdd }: { canManage: boolean; onAdd: () => void }) {
    return (
        <div className="rounded-2xl border border-dashed border-border bg-card px-6 py-12 text-center">
            <span className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                <Smartphone className="size-6" />
            </span>
            <h2 className="font-heading mt-4 text-lg font-bold">No numbers to rent yet</h2>
            <p className="mx-auto mt-1.5 max-w-md text-sm text-muted-foreground">
                Add a WhatsApp number from your Meta account and resellers can rent it in one click, with no Meta setup of
                their own. You need its Phone number ID and an access token from Meta → WhatsApp → API Setup.
            </p>

            {canManage && (
                <button
                    type="button"
                    onClick={onAdd}
                    className="mt-5 inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"
                >
                    <Plus className="size-4" />
                    Add your first number
                </button>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ card -- */

function NumberCard({
    number,
    currency,
    canManage,
    onEdit,
    onConfirm,
}: {
    number: NumberRow;
    currency: string;
    canManage: boolean;
    onEdit: () => void;
    onConfirm: (kind: 'release' | 'delete') => void;
}) {
    const [checking, setChecking] = useState(false);
    const [result, setResult] = useState<Verdict | null>(null);

    // By name first: "+1" belongs to both the United States and Canada, and
    // matching on the code alone gave every American number a Canadian flag.
    const country =
        COUNTRIES.find((item) => item.name === number.country) ??
        COUNTRIES.find((item) => item.dial === number.countryCode);

    async function check() {
        setChecking(true);
        setResult(null);
        setResult(await verify({ phone_number_id: number.phoneNumberId, number_id: number.id }));
        setChecking(false);
    }

    return (
        <article
            className={[
                'flex flex-col rounded-xl border bg-card p-5',
                number.status === 'suspended' ? 'border-dashed opacity-80' : 'border-border',
            ].join(' ')}
        >
            <div className="flex items-start justify-between gap-3">
                <p className="flex min-w-0 items-center gap-2 text-sm text-muted-foreground">
                    <span className="text-base leading-none">{country ? flag(country.iso) : '🌐'}</span>
                    <span className="truncate">{number.country ?? 'Unknown country'}</span>
                </p>
                <Status status={number.status} />
            </div>

            <div className="mt-3 flex items-center gap-2">
                <p className="font-heading font-data text-xl font-extrabold tracking-tight tabular-nums">{number.displayNumber}</p>
                <CopyIcon value={number.displayNumber} label="Copy number" />
            </div>

            <p className="font-heading mt-1 text-sm font-semibold">
                {price(number.price, currency)}
                <span className="ml-1 text-xs font-normal text-muted-foreground">one-time</span>
            </p>

            <dl className="mt-4 space-y-1.5 text-xs">
                <Detail label="Phone number ID" value={number.phoneNumberId} copy />
                {number.wabaId && <Detail label="WABA ID" value={number.wabaId} copy />}
                <div className="flex items-center justify-between gap-2">
                    <dt className="text-muted-foreground">Access token</dt>
                    <dd className="flex items-center gap-1.5 font-medium">
                        {number.tokenSaved ? (
                            <>
                                <KeyRound className="size-3 text-emerald-600" />
                                <span className="font-data">••••{number.tokenHint}</span>
                            </>
                        ) : (
                            <span className="text-destructive">Missing</span>
                        )}
                    </dd>
                </div>
            </dl>

            {number.rentedTo && (
                <div className="mt-4 rounded-lg border border-sky-500/25 bg-sky-500/8 px-3 py-2.5 text-xs">
                    <p className="text-muted-foreground">Rented by</p>
                    <Link
                        href={route('admin.tenants.show', [number.rentedTo.id, 'overview'])}
                        className="mt-0.5 flex items-center gap-1 text-sm font-semibold hover:underline"
                    >
                        {number.rentedTo.business}
                        <ExternalLink className="size-3 opacity-60" />
                    </Link>
                    <p className="text-muted-foreground">
                        {number.rentedTo.email}
                        {number.rentedTo.since && ` · since ${shortDate(number.rentedTo.since)}`}
                    </p>
                </div>
            )}

            {number.timesRented > 0 && !number.rentedTo && (
                <p className="mt-3 text-xs text-muted-foreground">
                    Rented {number.timesRented} time{number.timesRented === 1 ? '' : 's'} before.
                </p>
            )}

            {result && (
                <div
                    className={[
                        'mt-4 rounded-lg px-3 py-2.5 text-xs',
                        result.ok ? 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-300' : 'bg-destructive/10 text-destructive',
                    ].join(' ')}
                >
                    {result.ok ? (
                        <>
                            <p className="flex items-center gap-1.5 font-semibold">
                                <CircleCheck className="size-3.5" />
                                Meta confirms this number works
                            </p>
                            <p className="mt-0.5 opacity-90">
                                {[result.name, result.quality && `quality ${result.quality}`, result.status]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </>
                    ) : (
                        <p className="flex items-start gap-1.5">
                            <CircleX className="mt-0.5 size-3.5 shrink-0" />
                            {result.error}
                        </p>
                    )}
                </div>
            )}

            {canManage && (
                <div className="mt-auto flex flex-wrap gap-2 pt-4">
                    <button
                        type="button"
                        onClick={check}
                        disabled={checking || !number.tokenSaved}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-accent disabled:opacity-50"
                    >
                        {checking ? <Loader2 className="size-3.5 animate-spin" /> : <ShieldCheck className="size-3.5" />}
                        Check with Meta
                    </button>

                    <button
                        type="button"
                        onClick={onEdit}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-accent"
                    >
                        <Pencil className="size-3.5" />
                        Edit
                    </button>

                    {number.status === 'available' && (
                        <button
                            type="button"
                            onClick={() => router.post(route('admin.numbers.act', [number.id, 'suspend']), {}, { preserveScroll: true })}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-accent"
                        >
                            <Power className="size-3.5" />
                            Suspend
                        </button>
                    )}

                    {number.status === 'suspended' && (
                        <button
                            type="button"
                            onClick={() => router.post(route('admin.numbers.act', [number.id, 'restore']), {}, { preserveScroll: true })}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-accent"
                        >
                            <Undo2 className="size-3.5" />
                            Restore
                        </button>
                    )}

                    {number.status === 'rented' && (
                        <button
                            type="button"
                            onClick={() => onConfirm('release')}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-amber-700 transition-colors hover:bg-amber-500/10 dark:text-amber-400"
                        >
                            <Undo2 className="size-3.5" />
                            Take back
                        </button>
                    )}

                    {number.status !== 'rented' && number.timesRented === 0 && (
                        <button
                            type="button"
                            onClick={() => onConfirm('delete')}
                            aria-label="Delete number"
                            className="ml-auto inline-flex items-center rounded-lg px-2 py-1.5 text-xs text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                        >
                            <Trash2 className="size-3.5" />
                        </button>
                    )}
                </div>
            )}
        </article>
    );
}

function Detail({ label, value, copy }: { label: string; value: string; copy?: boolean }) {
    return (
        <div className="flex items-center justify-between gap-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="flex min-w-0 items-center gap-1.5 font-medium">
                <span className="truncate font-data">{value}</span>
                {copy && <CopyIcon value={value} label={`Copy ${label}`} />}
            </dd>
        </div>
    );
}

function CopyIcon({ value, label }: { value: string; label: string }) {
    const [done, setDone] = useState(false);

    return (
        <button
            type="button"
            onClick={() => {
                void navigator.clipboard?.writeText(value).then(() => {
                    setDone(true);
                    window.setTimeout(() => setDone(false), 1400);
                });
            }}
            aria-label={label}
            className="shrink-0 text-muted-foreground transition-colors hover:text-foreground"
        >
            {done ? <Check className="size-3.5 text-emerald-600" /> : <Copy className="size-3.5" />}
        </button>
    );
}

/* ---------------------------------------------------------------- dialog -- */

function Field({
    label,
    error,
    hint,
    children,
    className = '',
}: {
    label: string;
    error?: string;
    hint?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <label className={`block ${className}`}>
            <span className="mb-1 block text-xs font-semibold">{label}</span>
            {children}
            {hint && !error && <span className="mt-1 block text-xs text-muted-foreground">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-destructive">{error}</span>}
        </label>
    );
}

function NumberDialog({ number, currency, onClose }: { number: NumberRow | null; currency: string; onClose: () => void }) {
    const isNew = number === null;

    const { data, setData, post, patch, processing, errors } = useForm({
        display_number: number?.displayNumber ?? '',
        phone_number_id: number?.phoneNumberId ?? '',
        waba_id: number?.wabaId ?? '',
        token: '',
        country: number?.country ?? '',
        country_code: number?.countryCode ?? '',
        price: number ? String(number.price) : '',
    });

    const [checking, setChecking] = useState(false);
    const [result, setResult] = useState<Verdict | null>(null);

    // The country follows the number: picking one in the phone field fills the
    // country name and code, so nobody types "Tanzania" or "255" by hand.
    function setNumber(value: string) {
        setData((current) => {
            const split = splitNumber(value);

            return {
                ...current,
                display_number: value,
                country: split?.country.name ?? current.country,
                country_code: split?.country.dial ?? current.country_code,
            };
        });
    }

    async function check() {
        setChecking(true);
        setResult(null);

        setResult(
            await verify({
                phone_number_id: data.phone_number_id,
                token: data.token || undefined,
                number_id: number?.id,
            }),
        );

        setChecking(false);
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (isNew) {
            post(route('admin.numbers.store'), options);
        } else {
            patch(route('admin.numbers.update', number.id), options);
        }
    }

    const rented = number?.status === 'rented';
    const reported = result && result.ok ? result.display_number : null;
    const mismatch =
        reported && data.display_number.replace(/\D/g, '') !== reported.replace(/\D/g, '') ? reported : null;

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-foreground/40 p-4">
            <form onSubmit={submit} className="my-6 w-full max-w-xl rounded-2xl border border-border bg-card p-6 shadow-2xl">
                <div className="flex items-start gap-3">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <Smartphone className="size-5" />
                    </span>
                    <div>
                        <h2 className="font-heading text-lg font-extrabold">
                            {isNew ? 'Add a number' : `Edit ${number.displayNumber}`}
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            From Meta → your app → WhatsApp → API Setup.
                        </p>
                    </div>
                </div>

                {rented && (
                    <p className="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200">
                        Rented by {number.rentedTo?.business}. A new token reaches their bot straight away, and the Phone
                        number ID cannot be changed until you take the number back.
                    </p>
                )}

                <div className="mt-5 space-y-4">
                    <Field label="WhatsApp number" error={errors.display_number} hint="Pick the country, then type the number.">
                        <PhoneInput id="display_number" value={data.display_number} onChange={setNumber} />
                    </Field>

                    {data.country && (
                        <p className="-mt-2 text-xs text-muted-foreground">
                            Country: <strong className="text-foreground">{data.country}</strong> (+{data.country_code})
                        </p>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Phone number ID" error={errors.phone_number_id} hint="The long number under “From”, not the phone number.">
                            <input
                                value={data.phone_number_id}
                                onChange={(event) => setData('phone_number_id', event.target.value.replace(/\s/g, ''))}
                                inputMode="numeric"
                                placeholder="e.g. 123456789012345"
                                disabled={rented}
                                className={`${inputClass} font-data disabled:opacity-60`}
                            />
                        </Field>

                        <Field label="WhatsApp Business account ID" error={errors.waba_id} hint="Optional.">
                            <input
                                value={data.waba_id}
                                onChange={(event) => setData('waba_id', event.target.value.replace(/\s/g, ''))}
                                inputMode="numeric"
                                className={`${inputClass} font-data`}
                            />
                        </Field>
                    </div>

                    <Field
                        label="Access token"
                        error={errors.token}
                        hint={
                            isNew
                                ? 'Use a permanent token from a System user — the 24-hour one on API Setup stops working tomorrow. Stored encrypted, never shown again.'
                                : 'Leave blank to keep the saved token. Stored encrypted, never shown.'
                        }
                    >
                        <input
                            type="password"
                            value={data.token}
                            onChange={(event) => setData('token', event.target.value)}
                            autoComplete="off"
                            placeholder={isNew ? 'EAAG…' : number?.tokenSaved ? `Saved (••••${number.tokenHint})` : 'EAAG…'}
                            className={`${inputClass} font-data`}
                        />
                    </Field>

                    {/* The step that saves an admin from a wrong ID or a dead
                        token: ask Meta now, not after someone has paid. */}
                    <div className="rounded-xl border border-border bg-muted/30 p-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                onClick={check}
                                disabled={checking || !data.phone_number_id || (isNew && !data.token)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-xs font-semibold transition-colors hover:bg-accent disabled:opacity-50"
                            >
                                {checking ? <Loader2 className="size-3.5 animate-spin" /> : <ShieldCheck className="size-3.5" />}
                                Check with Meta
                            </button>
                            <span className="text-xs text-muted-foreground">Confirms the ID and token work together before you save.</span>
                        </div>

                        {result && (
                            <div
                                className={[
                                    'mt-3 rounded-lg px-3 py-2 text-xs',
                                    result.ok
                                        ? 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-300'
                                        : 'bg-destructive/10 text-destructive',
                                ].join(' ')}
                            >
                                {result.ok ? (
                                    <>
                                        <p className="flex items-center gap-1.5 font-semibold">
                                            <CircleCheck className="size-3.5" />
                                            Works — {result.name || 'verified'} {result.display_number && `· ${result.display_number}`}
                                        </p>
                                        <p className="mt-0.5 opacity-90">
                                            {[result.quality && `quality ${result.quality}`, result.status].filter(Boolean).join(' · ')}
                                        </p>

                                        {mismatch && (
                                            <button
                                                type="button"
                                                onClick={() => setNumber(mismatch)}
                                                className="mt-2 rounded-md bg-background px-2 py-1 font-semibold text-foreground shadow-sm"
                                            >
                                                Meta says the number is {mismatch} — use it
                                            </button>
                                        )}
                                    </>
                                ) : (
                                    <p className="flex items-start gap-1.5">
                                        <CircleX className="mt-0.5 size-3.5 shrink-0" />
                                        {result.error}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>

                    <Field
                        label={`Price (${currency}, paid once)`}
                        error={errors.price}
                        hint={
                            data.price !== '' && !Number.isNaN(Number(data.price)) ? (
                                <>
                                    Resellers pay <strong>{price(Number(data.price), currency)}</strong> once, and the number is theirs
                                    until they hand it back. Set 0 to make it free.
                                </>
                            ) : (
                                'Charged once at checkout, in the platform billing currency.'
                            )
                        }
                    >
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            value={data.price}
                            onChange={(event) => setData('price', event.target.value)}
                            className={inputClass}
                        />
                    </Field>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-border px-4 py-2 text-sm font-medium transition-colors hover:bg-accent"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-60"
                    >
                        {processing && <Loader2 className="size-4 animate-spin" />}
                        {isNew ? 'Add to pool' : 'Save changes'}
                    </button>
                </div>
            </form>
        </div>
    );
}

function ConfirmDialog({
    confirm,
    onClose,
}: {
    confirm: { number: NumberRow; kind: 'release' | 'delete' };
    onClose: () => void;
}) {
    const { number, kind } = confirm;
    const [busy, setBusy] = useState(false);

    const copy =
        kind === 'release'
            ? {
                  title: `Take ${number.displayNumber} back?`,
                  body: `${number.rentedTo?.business ?? 'The renter'}'s bot on this number stops answering straight away, and the number goes back in the pool. Their conversations, orders and customers stay theirs.`,
                  action: 'Take it back',
              }
            : {
                  title: `Delete ${number.displayNumber}?`,
                  body: 'It has never been rented, so nothing points at it. This removes it for good.',
                  action: 'Delete',
              };

    function run() {
        setBusy(true);

        const options = { preserveScroll: true, onFinish: onClose };

        if (kind === 'release') {
            router.post(route('admin.numbers.act', [number.id, 'release']), {}, options);
        } else {
            router.delete(route('admin.numbers.destroy', number.id), options);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4">
            <div className="w-full max-w-md rounded-2xl border border-border bg-card p-6 shadow-2xl">
                <span className="flex size-10 items-center justify-center rounded-xl bg-destructive/10 text-destructive">
                    <AlertTriangle className="size-5" />
                </span>
                <h2 className="font-heading mt-3 text-lg font-extrabold">{copy.title}</h2>
                <p className="mt-1.5 text-sm text-muted-foreground">{copy.body}</p>

                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="rounded-lg border border-border px-4 py-2 text-sm font-medium hover:bg-accent">
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={run}
                        disabled={busy}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-destructive px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                    >
                        {busy && <Loader2 className="size-4 animate-spin" />}
                        {copy.action}
                    </button>
                </div>
            </div>
        </div>
    );
}
