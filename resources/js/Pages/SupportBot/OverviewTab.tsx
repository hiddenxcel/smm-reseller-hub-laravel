import { Link } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, LifeBuoy } from 'lucide-react';
import { Card, Metric } from '../OrderBot/bits';
import { BotStatus, Overview } from './types';

/**
 * What a support reseller opens the page to find out.
 *
 * The handoff banner leads because it is the only thing here with a person on
 * the other end of it: each one is a customer who pressed 5 and is sitting in
 * a chat waiting. Ticket counts and readiness checks can wait below it.
 */
export function OverviewTab({ data, status }: { data: Overview; status: BotStatus }) {
    const { checks, tickets, awaitingHuman, menu } = data;

    return (
        <div className="space-y-6">
            {awaitingHuman > 0 && (
                <Link
                    href={route('support-bot.inbox')}
                    className="flex items-start gap-3 rounded-xl border border-primary/30 bg-primary/5 p-4 transition-colors hover:bg-primary/10"
                >
                    <LifeBuoy className="mt-0.5 size-5 shrink-0 text-primary" aria-hidden />
                    <div>
                        <p className="font-medium">
                            {awaitingHuman === 1
                                ? 'One customer is waiting for a person'
                                : `${awaitingHuman} customers are waiting for a person`}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            The bot has stopped answering them. Open the inbox to reply.
                        </p>
                    </div>
                </Link>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Metric label="Waiting for a person" value={String(awaitingHuman)} />
                <Metric label="Open tickets" value={String(tickets.open)} />
                <Metric label="Pending" value={String(tickets.pending)} />
                <Metric label="Resolved" value={String(tickets.resolved)} />
            </div>

            <Card
                title="Before the bot can answer"
                description="A silent bot has one of these causes. Each is checked separately so you know which."
            >
                <ul className="space-y-1">
                    <Check
                        ok={checks.subscription}
                        label="Subscription"
                        missing="No active support bot subscription."
                        href={route('onboarding')}
                    />
                    <Check
                        ok={checks.whatsapp}
                        label="WhatsApp number"
                        missing="No number connected for the support bot."
                        href={route('onboarding')}
                    />
                    <Check
                        ok={checks.panel}
                        label="Panel"
                        missing="Status and refill need a panel to ask."
                        href={route('order-bot.providers')}
                    />
                    <Check
                        ok={checks.rules}
                        label="Guarantee rules"
                        missing="Without a rule, every refill request is refused."
                        href={route('support-bot', 'rules')}
                    />
                </ul>
            </Card>

            <Card
                title="The Quick Menu"
                description="What a customer sees when they message this number."
            >
                <ul className="space-y-1.5">
                    {menu.map((option) => (
                        <li
                            key={option.value}
                            className="flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-sm odd:bg-muted/40"
                        >
                            <span className={option.enabled ? '' : 'text-muted-foreground line-through'}>
                                {option.label}
                            </span>
                            {!option.enabled && (
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    switched off
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
                <p className="mt-3 text-xs text-muted-foreground">
                    Options without a switch are always offered — a customer must never be
                    unable to reach a person.
                </p>
            </Card>

            {status.subscription === 'sandbox' && (
                <Card
                    title="Sandbox"
                    description="While the subscription is in sandbox the bot only answers your own test numbers."
                >
                    {data.testNumbers.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No test numbers yet. Add one under{' '}
                            <Link href={route('support-bot', 'settings')} className="underline">
                                Settings
                            </Link>{' '}
                            to try the bot before paying.
                        </p>
                    ) : (
                        <ul className="flex flex-wrap gap-2">
                            {data.testNumbers.map((number) => (
                                <li
                                    key={number}
                                    className="font-data rounded-full bg-muted px-3 py-1 text-sm"
                                >
                                    {number}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}
        </div>
    );
}

function Check({
    ok,
    label,
    missing,
    href,
}: {
    ok: boolean;
    label: string;
    missing: string;
    href: string;
}) {
    return (
        <li className="flex items-start gap-3 py-1.5">
            {ok ? (
                <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden />
            ) : (
                <CircleAlert
                    className="mt-0.5 size-4 shrink-0 text-[oklch(0.65_0.15_70)]"
                    aria-hidden
                />
            )}
            <span className="min-w-0">
                <span className="block text-sm font-medium">{label}</span>
                {!ok && (
                    <span className="block text-sm text-muted-foreground">
                        {missing}{' '}
                        <Link href={href} className="underline">
                            Fix it
                        </Link>
                    </span>
                )}
            </span>
            <span className="sr-only">{ok ? 'ready' : 'not ready'}</span>
        </li>
    );
}
