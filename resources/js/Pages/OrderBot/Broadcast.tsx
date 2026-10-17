import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { Loader2, Megaphone, Send } from 'lucide-react';
import { useEffect, useState } from 'react';
import { relativeTime } from '../Services/bits';

interface Segment {
    key: string;
    label: string;
}

interface Row {
    id: number;
    message: string;
    audience: string;
    status: 'sending' | 'done' | 'cancelled' | 'paused';
    note: string | null;
    total: number;
    sent: number;
    failed: number;
    skipped: number;
    at: string | null;
}

interface Summary {
    total: number;
    now: number;
    whatsapp: number;
    telegram: number;
    later: number;
    unreachable: number;
}

interface Props {
    segments: Segment[];
    channels: { whatsapp: boolean; telegram: boolean };
    blocker: string | null;
    maxLength: number;
    recent: Row[];
}

const STATUS: Record<Row['status'], { label: string; className: string }> = {
    sending: { label: 'Sending', className: 'text-amber-600' },
    done: { label: 'Done', className: 'text-emerald-600' },
    cancelled: { label: 'Cancelled', className: 'text-muted-foreground' },
    paused: { label: 'Paused', className: 'text-destructive' },
};

export default function Broadcast({ segments, channels, blocker, maxLength, recent }: Props) {
    const [message, setMessage] = useState('');
    const [audience, setAudience] = useState(segments[0]?.key ?? 'all');
    const [summary, setSummary] = useState<Summary | null>(null);
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let cancelled = false;
        setSummary(null);
        setConfirming(false);

        fetch(route('order-bot.broadcast.preview', { audience }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                if (!cancelled && data) {
                    setSummary(data);
                }
            })
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
    }, [audience]);

    // While anything is still going out, keep the progress fresh.
    const running = recent.some((row) => row.status === 'sending');

    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = setInterval(() => router.reload({ only: ['recent', 'blocker'] }), 5000);

        return () => clearInterval(timer);
    }, [running]);

    const send = () => {
        router.post(
            route('order-bot.broadcast.store'),
            { message, audience },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setConfirming(false);
                },
                onSuccess: () => setMessage(''),
            },
        );
    };

    const act = (row: Row, action: 'cancel' | 'resume') =>
        router.post(route(`order-bot.broadcast.${action}`, row.id), {}, { preserveScroll: true });

    const canSend = !blocker && message.trim().length > 0 && summary !== null && summary.now > 0 && !busy;

    return (
        <AuthenticatedLayout>
            <Head title="Broadcast" />

            <div className="mx-auto max-w-3xl space-y-5 px-4 py-5 sm:px-8">
                <div>
                    <h1 className="font-heading flex items-center gap-2 text-xl font-extrabold tracking-tight sm:text-2xl">
                        <Megaphone className="size-5" aria-hidden />
                        Broadcast
                    </h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Tell your customers about a new service, a price change or an update. Write it once and it goes to everyone you choose.
                    </p>
                </div>

                {blocker && (
                    <p className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-sm">{blocker}</p>
                )}

                <section className="space-y-4 rounded-xl border border-border bg-card p-4">
                    <div className="space-y-1.5">
                        <label htmlFor="bc-message" className="text-sm font-medium">
                            Message
                        </label>
                        <textarea
                            id="bc-message"
                            value={message}
                            maxLength={maxLength}
                            rows={6}
                            onChange={(event) => {
                                setMessage(event.target.value);
                                setConfirming(false);
                            }}
                            placeholder="New service: TikTok Likes, from TZS 800 per 1,000. Send hi to order."
                            className="w-full resize-y rounded-lg border border-border bg-background p-3 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                        />
                        <p className="text-right text-xs text-muted-foreground tabular-nums">
                            {message.length}/{maxLength}
                        </p>
                    </div>

                    <div className="space-y-1.5">
                        <label htmlFor="bc-audience" className="text-sm font-medium">
                            Send to
                        </label>
                        <select
                            id="bc-audience"
                            value={audience}
                            onChange={(event) => setAudience(event.target.value)}
                            className="h-10 w-full rounded-lg border border-border bg-background px-3 text-sm"
                        >
                            {segments.map((segment) => (
                                <option key={segment.key} value={segment.key}>
                                    {segment.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="rounded-lg bg-muted/50 p-3 text-sm">
                        {summary === null ? (
                            <span className="flex items-center gap-2 text-muted-foreground">
                                <Loader2 className="size-3.5 animate-spin" aria-hidden />
                                Counting
                            </span>
                        ) : (
                            <div className="space-y-1.5">
                                <p>
                                    <span className="font-semibold tabular-nums">{summary.now}</span> of{' '}
                                    <span className="tabular-nums">{summary.total}</span> customers will get it now
                                    {summary.now > 0 && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            ({summary.whatsapp} on WhatsApp, {summary.telegram} on Telegram)
                                        </span>
                                    )}
                                    .
                                </p>
                                {summary.later > 0 && (
                                    <p className="text-muted-foreground">
                                        {summary.later} WhatsApp {summary.later === 1 ? 'customer' : 'customers'} last wrote over 24 hours
                                        ago. WhatsApp only allows a pre-approved template for them, which is coming soon, so they are left out for now.
                                    </p>
                                )}
                                {summary.unreachable > 0 && (
                                    <p className="text-muted-foreground">
                                        {summary.unreachable} cannot be reached because that channel is not connected.
                                    </p>
                                )}
                                {(!channels.whatsapp && !channels.telegram) && (
                                    <p className="text-destructive">Connect a WhatsApp number or Telegram bot first.</p>
                                )}
                            </div>
                        )}
                    </div>

                    {confirming ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="text-sm">Send to {summary?.now} customers now?</p>
                            <Button size="sm" variant="outline" onClick={() => setConfirming(false)} disabled={busy}>
                                Not yet
                            </Button>
                            <Button size="sm" onClick={send} disabled={busy}>
                                {busy ? <Loader2 className="size-3.5 animate-spin" /> : <Send className="size-3.5" />}
                                Yes, send
                            </Button>
                        </div>
                    ) : (
                        <Button disabled={!canSend} onClick={() => setConfirming(true)}>
                            <Send className="size-3.5" />
                            Send broadcast
                        </Button>
                    )}
                </section>

                {recent.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-heading text-base font-bold">Recent broadcasts</h2>
                        <ul className="space-y-3">
                            {recent.map((row) => {
                                const reached = row.total - row.skipped;
                                const done = row.sent + row.failed;
                                const percent = reached > 0 ? Math.min(100, Math.round((done / reached) * 100)) : 100;

                                return (
                                    <li key={row.id} className="space-y-2 rounded-xl border border-border bg-card p-4 text-sm">
                                        <div className="flex items-start justify-between gap-3">
                                            <p className="line-clamp-2 min-w-0 whitespace-pre-line">{row.message}</p>
                                            <span className={`shrink-0 text-xs font-semibold ${STATUS[row.status].className}`}>
                                                {STATUS[row.status].label}
                                            </span>
                                        </div>

                                        <div className="h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden>
                                            <div className="h-full bg-primary transition-all" style={{ width: `${percent}%` }} />
                                        </div>

                                        <p className="text-xs text-muted-foreground">
                                            {row.audience} · {relativeTime(row.at)} · {row.sent} sent
                                            {row.failed > 0 && `, ${row.failed} failed`}
                                            {row.skipped > 0 && `, ${row.skipped} left out`}
                                        </p>

                                        {row.note && <p className="text-xs text-destructive">{row.note}</p>}

                                        {(row.status === 'sending' || row.status === 'paused') && (
                                            <div className="flex gap-2">
                                                {row.status === 'paused' && (
                                                    <Button size="sm" variant="outline" className="h-8" onClick={() => act(row, 'resume')}>
                                                        Resume
                                                    </Button>
                                                )}
                                                <Button size="sm" variant="outline" className="h-8" onClick={() => act(row, 'cancel')}>
                                                    Cancel
                                                </Button>
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
