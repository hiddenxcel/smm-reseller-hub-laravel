import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { PanelTab } from './PanelTab';
import { PaymentsTab } from './PaymentsTab';
import { ServicesTab } from './ServicesTab';
import { WhatsAppTab } from './WhatsAppTab';
import { SettingsPageProps } from './types';

const TAB_LABELS: Record<string, string> = {
    panel: 'Panel',
    services: 'Services',
    whatsapp: 'WhatsApp',
    payments: 'Payments',
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
        <AuthenticatedLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-bold">Settings</h1>
                    <p className="text-sm text-muted-foreground">
                        Your panel, services, WhatsApp numbers and payment gateways.
                    </p>
                </div>
            }
        >
            <Head title="Settings" />

            <nav
                className="scroll-slim -mx-1 mb-6 flex gap-1 overflow-x-auto border-b border-border px-1"
                aria-label="Settings sections"
            >
                {tabs.map((name) => {
                    const isCurrent = name === tab;
                    const needsAttention = incomplete[name] === true;

                    return (
                        <Link
                            key={name}
                            href={route('settings', name)}
                            className={[
                                'flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm transition-colors',
                                isCurrent
                                    ? 'border-primary font-semibold text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ].join(' ')}
                            aria-current={isCurrent ? 'page' : undefined}
                        >
                            {TAB_LABELS[name] ?? name}

                            {/* The dot is reinforcement; the label carries the
                                meaning for anyone who cannot see colour. */}
                            {needsAttention && (
                                <span
                                    className="size-1.5 rounded-full bg-[oklch(0.77_0.16_70)]"
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
        </AuthenticatedLayout>
    );
}
