import { Link } from '@inertiajs/react';
import { ChevronRight, CircleCheck, CircleMinus, LifeBuoy } from 'lucide-react';
import { Card } from '../OrderBot/bits';
import { BotStatus, Overview } from './types';

/**
 * What a support reseller opens the page to find out.
 *
 * The handoff banner leads because it is the only thing here with a person on
 * the other end of it: each one is a customer who pressed 5 and is sitting in
 * a chat waiting. Ticket counts and readiness can wait below it.
 */
export function OverviewTab({ data, status }: { data: Overview; status: BotStatus }) {
    const { checks, tickets, awaitingHuman, menu } = data;

    const missing = [
        !checks.subscription && {
            label: 'Subscription',
            why: 'No active support bot subscription.',
            fix: 'Get it',
            href: route('onboarding'),
        },
        !checks.whatsapp && {
            label: 'WhatsApp number',
            why: 'No number connected for the support bot.',
            fix: 'Connect',
            href: route('support-bot', 'number'),
        },
        !checks.panel && {
            label: 'Panel',
            why: 'Status and refill need a panel to ask.',
            fix: 'Connect',
            href: route('order-bot.providers'),
        },
        !checks.rules && {
            label: 'Guarantee rules',
            why: 'Automatic reading is off and there is no rule, so every refill is refused.',
            fix: 'Add one',
            href: route('support-bot', 'rules'),
        },
    ].filter(Boolean) as Array<{ label: string; why: string; fix: string; href: string }>;

    return (
        <div className="space-y-4 sm:space-y-6">
            {awaitingHuman > 0 && (
                <Link
                    href={route('support-bot.inbox')}
                    className="flex items-center gap-3 rounded-2xl border border-primary/30 bg-primary/5 p-4 transition-colors hover:bg-primary/10"
                >
                    <LifeBuoy className="size-5 shrink-0 text-primary" aria-hidden />
                    <div className="min-w-0 flex-1">
                        <p className="font-medium">
                            {awaitingHuman === 1
                                ? 'One customer is waiting for you'
                                : `${awaitingHuman} customers are waiting for you`}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            The bot has stopped answering them.
                        </p>
                    </div>
                    <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                </Link>
            )}

            {/* Four numbers in one card; the first two are links, since they
                are the ones with something to do behind them. */}
            <section
                aria-label="Support totals"
                className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-border bg-border lg:grid-cols-4"
            >
                <Figure
                    label="Waiting for you"
                    value={awaitingHuman}
                    href={route('support-bot.inbox')}
                    alert={awaitingHuman > 0}
                />
                <Figure
                    label="Open tickets"
                    value={tickets.open}
                    href={route('support-bot.tickets')}
                />
                <Figure label="Pending" value={tickets.pending} />
                <Figure label="Resolved" value={tickets.resolved} />
            </section>

            {/* Only what is missing: four green ticks say nothing a working
                bot has not already said. */}
            {missing.length > 0 ? (
                <Card
                    title="Before the bot can answer"
                    description="A silent bot has one of these causes."
                >
                    <ul className="space-y-3">
                        {missing.map((item) => (
                            <li key={item.label} className="flex items-start gap-3">
                                <CircleMinus
                                    className="mt-0.5 size-4 shrink-0 text-[oklch(0.65_0.15_70)]"
                                    aria-label="Not ready"
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium">{item.label}</span>
                                    <span className="block text-sm text-muted-foreground">
                                        {item.why}
                                    </span>
                                </span>
                                <Link
                                    href={item.href}
                                    className="shrink-0 text-xs font-semibold text-primary"
                                >
                                    {item.fix}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            ) : (
                <p className="flex items-center gap-2 rounded-2xl border border-border bg-card px-4 py-3 text-sm text-muted-foreground">
                    <CircleCheck className="size-4 shrink-0 text-primary" aria-hidden />
                    Subscription, number, panel and guarantee rules are all in place.
                </p>
            )}

            <Card
                title="What customers see"
                description="The quick menu, when they message this number."
            >
                <ol className="space-y-1">
                    {menu.map((option) => (
                        <li
                            key={option.value}
                            className="flex items-center justify-between gap-3 rounded-xl px-3 py-2 text-sm odd:bg-muted/40"
                        >
                            <span
                                className={option.enabled ? '' : 'text-muted-foreground line-through'}
                            >
                                {option.label}
                            </span>
                            {!option.enabled && (
                                <span className="shrink-0 text-xs text-muted-foreground">off</span>
                            )}
                        </li>
                    ))}
                </ol>
                <p className="mt-3 text-xs text-muted-foreground">
                    Options without a switch are always offered — a customer must never be unable
                    to reach a person.
                </p>
            </Card>

            {status.subscription === 'sandbox' && (
                <Card
                    title="Test mode"
                    description="The bot only answers your own test numbers until you go live."
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

function Figure({
    label,
    value,
    href,
    alert = false,
}: {
    label: string;
    value: number;
    href?: string;
    alert?: boolean;
}) {
    const inner = (
        <>
            <p className="text-xs text-muted-foreground sm:text-sm">{label}</p>
            <p
                className={`font-heading mt-1 text-2xl font-extrabold tracking-tight ${
                    alert ? 'text-primary' : ''
                }`}
            >
                {value}
            </p>
        </>
    );

    return href ? (
        <Link href={href} className="bg-card p-4 transition-colors hover:bg-accent/50">
            {inner}
        </Link>
    ) : (
        <div className="bg-card p-4">{inner}</div>
    );
}
