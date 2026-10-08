import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card } from './bits';
import { StaffAlertsData } from './types';

const STATE: Record<StaffAlertsData['numbers'][number]['state'], { label: string; tone: string }> = {
    open: { label: 'Can be told now', tone: 'bg-primary/10 text-primary' },
    closed: {
        label: 'Has not written to the bot in 24 hours: alerts will not arrive',
        tone: 'bg-amber-500/10 text-amber-500',
    },
    never: {
        label: 'Has never written to the bot: alerts cannot arrive. Check the number',
        tone: 'bg-destructive/10 text-destructive',
    },
};

/**
 * Whether the team is really told.
 *
 * WhatsApp delivers a free-form message only to someone who has written to the
 * bot in the last 24 hours, so a number on the list can be silently
 * unreachable. This shows which ones are, lets each be tested in a click, and
 * lists what was sent and whether it got through. Email covers for the rest.
 */
export function StaffAlertsCard({ bot, data }: { bot: 'order' | 'support'; data: StaffAlertsData }) {
    const [busy, setBusy] = useState<string | null>(null);

    const test = (phone: string) => {
        setBusy(phone);
        router.post(
            route('staff-alerts.test', bot),
            { phone },
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    const saveEmail = (mode: string) =>
        router.post(route('staff-alerts.email', bot), { mode }, { preserveScroll: true });

    return (
        <Card
            title="Team alerts"
            description="Who is told when a customer needs you, and whether it got through."
        >
            {data.numbers.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No staff numbers, so nobody is told on WhatsApp. Add them above, or choose email
                    below.
                </p>
            ) : (
                <ul className="divide-y divide-border">
                    {data.numbers.map((number) => (
                        <li key={number.phone} className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                            <div className="min-w-0 flex-1">
                                <p className="font-data text-sm">{number.phone}</p>
                                <p
                                    className={`mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium ${STATE[number.state].tone}`}
                                >
                                    {STATE[number.state].label}
                                </p>
                            </div>

                            <button
                                type="button"
                                onClick={() => test(number.phone)}
                                disabled={busy !== null}
                                className="shrink-0 rounded-lg border border-border px-3 py-1.5 text-xs font-medium hover:bg-accent disabled:opacity-60"
                            >
                                {busy === number.phone ? 'Sending…' : 'Send a test'}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="mt-4 border-t border-border pt-4">
                <label className="block text-sm font-medium" htmlFor={`email-mode-${bot}`}>
                    Also email {data.email ? <span className="font-data">{data.email}</span> : 'me'}
                </label>
                <select
                    id={`email-mode-${bot}`}
                    value={data.emailMode}
                    onChange={(event) => saveEmail(event.target.value)}
                    className="mt-1.5 h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option value="failed">Only when WhatsApp could not reach everyone (recommended)</option>
                    <option value="all">Every alert</option>
                    <option value="off">Never</option>
                </select>
            </div>

            {data.recent.length > 0 && (
                <div className="mt-4 border-t border-border pt-4">
                    <p className="mb-2 text-sm font-medium">Recent alerts</p>
                    <ul className="space-y-2">
                        {data.recent.map((alert) => (
                            <li key={alert.id} className="text-sm">
                                <div className="flex items-start gap-2">
                                    <span
                                        className={`mt-0.5 inline-block shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium ${
                                            alert.status === 'sent'
                                                ? 'bg-primary/10 text-primary'
                                                : 'bg-destructive/10 text-destructive'
                                        }`}
                                    >
                                        {alert.status === 'sent' ? 'Sent' : 'Not delivered'}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate">
                                            {alert.isTest ? 'Test alert' : alert.message.replace(/\*/g, '').split('\n')[0]}
                                        </span>
                                        <span className="font-data block text-xs text-muted-foreground">
                                            {alert.to}
                                            {alert.reason && ` · ${alert.reason}`}
                                        </span>
                                    </span>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Card>
    );
}
