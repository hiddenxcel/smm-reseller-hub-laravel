import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { CommandsTab } from './CommandsTab';
import { LogsTab } from './LogsTab';
import { SettingsTab } from './SettingsTab';
import { SetupTab } from './SetupTab';
import { StatusPill } from './bits';
import { OrderBotPageProps } from './types';

const TAB_LABELS: Record<string, string> = {
    setup: 'Bot setup',
    commands: 'Commands',
    logs: 'Logs',
    settings: 'Settings',
};

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
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-bold">Order Bot</h1>
                        <p className="text-sm text-muted-foreground">
                            The bot that sells — customers order and pay in chat.
                        </p>
                    </div>

                    <StatusPill status={status} />
                </div>
            }
        >
            <Head title="Order Bot" />

            <nav
                className="scroll-slim -mx-1 mb-6 flex gap-1 overflow-x-auto border-b border-border px-1"
                aria-label="Order bot sections"
            >
                {tabs.map((name) => {
                    const isCurrent = name === tab;

                    return (
                        <Link
                            key={name}
                            href={route('order-bot', name)}
                            className={[
                                'whitespace-nowrap border-b-2 px-3 py-2 text-sm transition-colors',
                                isCurrent
                                    ? 'border-primary font-semibold text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ].join(' ')}
                            aria-current={isCurrent ? 'page' : undefined}
                        >
                            {TAB_LABELS[name] ?? name}
                        </Link>
                    );
                })}
            </nav>

            {tab === 'setup' && props.setup && (
                <SetupTab
                    data={props.setup}
                    status={status}
                    languages={props.languages ?? []}
                />
            )}
            {tab === 'commands' && props.commands && props.spam && (
                <CommandsTab commands={props.commands} spam={props.spam} />
            )}
            {tab === 'logs' && props.logs && <LogsTab data={props.logs} />}
            {tab === 'settings' && props.settings && (
                <SettingsTab settings={props.settings} languages={props.languages ?? []} />
            )}
        </AuthenticatedLayout>
    );
}
