import SegmentedTabs from '@/components/SegmentedTabs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { Bot, ScrollText, Settings2, SlidersHorizontal } from 'lucide-react';
import { CommandsTab } from './CommandsTab';
import { LogsTab } from './LogsTab';
import { SettingsTab } from './SettingsTab';
import { SetupTab } from './SetupTab';
import { StatusPill } from './bits';
import { OrderBotPageProps } from './types';

const TABS = {
    setup: { label: 'Setup', icon: Bot },
    commands: { label: 'Commands', icon: SlidersHorizontal },
    logs: { label: 'Logs', icon: ScrollText },
    settings: { label: 'Settings', icon: Settings2 },
} as const;

/**
 * The order bot's console.
 *
 * Each tab is a URL, not React state — a reseller sending "look at my logs" to
 * support should be able to send the link. The server builds only the tab
 * being viewed, so the props for the others are simply absent.
 */
export default function OrderBotIndex(props: OrderBotPageProps) {
    const { tab, tabs, status } = props;

    return (
        <AuthenticatedLayout>
            <Head title="Order Bot" />

            <div className="mx-auto max-w-3xl space-y-4 sm:space-y-6">
                <header className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="font-heading text-xl font-extrabold tracking-tight sm:text-2xl">
                            Order Bot
                        </h1>
                        {status.number && (
                            <p className="font-data mt-0.5 text-sm text-muted-foreground">
                                {status.number}
                            </p>
                        )}
                    </div>

                    <StatusPill status={status} />
                </header>

                <SegmentedTabs
                    label="Order bot sections"
                    current={tab}
                    tabs={tabs.map((name) => {
                        const meta = TABS[name as keyof typeof TABS] ?? TABS.setup;

                        return {
                            key: name,
                            label: meta.label,
                            icon: meta.icon,
                            href: route('order-bot', name),
                        };
                    })}
                />

                {tab === 'setup' && props.setup && (
                    <SetupTab data={props.setup} status={status} />
                )}
                {tab === 'commands' && props.commands && props.spam && (
                    <CommandsTab commands={props.commands} spam={props.spam} />
                )}
                {tab === 'logs' && props.logs && <LogsTab data={props.logs} />}
                {tab === 'settings' && props.settings && (
                    <SettingsTab
                        settings={props.settings}
                        languages={props.languages ?? []}
                        currencies={props.currencies ?? []}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}
