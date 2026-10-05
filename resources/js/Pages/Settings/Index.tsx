import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { CreditCard, LucideIcon, MessageCircle, Package, Server } from 'lucide-react';
import { PanelTab } from './PanelTab';
import { PaymentsTab } from './PaymentsTab';
import { ServicesTab } from './ServicesTab';
import { WhatsAppTab } from './WhatsAppTab';
import { SettingsPageProps } from './types';

const TABS: Record<string, { label: string; icon: LucideIcon }> = {
    panel: { label: 'Panel', icon: Server },
    services: { label: 'Services', icon: Package },
    whatsapp: { label: 'WhatsApp', icon: MessageCircle },
    payments: { label: 'Payments', icon: CreditCard },
};

/**
 * Everything setup put in place, in one screen a reseller can come back to.
 *
 * Each tab is a URL, not React state — the server builds only the tab being
 * viewed, so the props for the others are simply absent.
 *
 * Tabs whose setup is not satisfied are marked in the nav rather than
 * blocking the page. Someone already running a shop should never be dropped
 * back into the wizard because one thing came undone; they are told which
 * thing, and left to fix it here.
 */
export default function SettingsIndex(props: SettingsPageProps) {
    const { tab, tabs, incomplete } = props;

    return (
        <AuthenticatedLayout>
            <Head title="Settings" />

            <div className="mx-auto max-w-3xl space-y-4 sm:space-y-6">
                <header>
                    <h1 className="font-heading text-xl font-extrabold tracking-tight sm:text-2xl">
                        Settings
                    </h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Your panel, services, WhatsApp numbers and payments.
                    </p>
                </header>

                {/* Four equal segments on a phone, so nothing scrolls sideways
                    and every section is one tap away. */}
                <nav
                    className="grid grid-cols-4 gap-1 rounded-2xl border border-border bg-muted/50 p-1"
                    aria-label="Settings sections"
                >
                    {tabs.map((name) => {
                        const isCurrent = name === tab;
                        const needsAttention = incomplete[name] === true;
                        const meta = TABS[name] ?? { label: name, icon: Package };
                        const Icon = meta.icon;

                        return (
                            <Link
                                key={name}
                                href={route('settings', name)}
                                className={[
                                    'relative flex flex-col items-center gap-1 rounded-xl px-1 py-2 text-xs font-medium transition-colors sm:flex-row sm:justify-center sm:gap-2 sm:py-2.5 sm:text-sm',
                                    isCurrent
                                        ? 'bg-card text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                ].join(' ')}
                                aria-current={isCurrent ? 'page' : undefined}
                            >
                                <Icon className="size-4 shrink-0" />
                                <span className="truncate">{meta.label}</span>

                                {/* The dot is reinforcement; the label carries the
                                    meaning for anyone who cannot see colour. */}
                                {needsAttention && (
                                    <span
                                        className="absolute right-2 top-1.5 size-1.5 rounded-full bg-[oklch(0.77_0.16_70)]"
                                        aria-label="needs attention"
                                    />
                                )}
                            </Link>
                        );
                    })}
                </nav>

                {tab === 'panel' && props.panels && <PanelTab panels={props.panels} />}

                {tab === 'services' && (
                    <ServicesTab
                        panel={props.panel ?? null}
                        services={props.services ?? []}
                        catalogueError={props.catalogueError ?? null}
                        importedCount={props.importedCount ?? 0}
                    />
                )}

                {tab === 'whatsapp' && (
                    <WhatsAppTab
                        webhookUrl={props.webhookUrl ?? ''}
                        verifyToken={props.verifyToken ?? ''}
                        numbers={props.numbers ?? []}
                        rentable={props.rentable ?? []}
                        rentals={props.rentals ?? []}
                    />
                )}

                {tab === 'payments' && (
                    <PaymentsTab
                        gateways={props.gateways ?? []}
                        connected={props.connected ?? []}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}
