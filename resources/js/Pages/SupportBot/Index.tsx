import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { StatusPill } from '../OrderBot/bits';
import { OverviewTab } from './OverviewTab';
import { RulesTab } from './RulesTab';
import { SettingsTab } from './SettingsTab';
import { TemplatesTab } from './TemplatesTab';
import { SupportBotPageProps } from './types';

const TAB_LABELS: Record<string, string> = {
    overview: 'Overview',
    rules: 'Guarantee rules',
    templates: 'Templates',
    settings: 'Settings',
};

/**
 * The support bot's console.
 *
 * Same shape as the order bot's: each tab is a URL rather than React state, so
 * a reseller can link somebody straight to their guarantee rules. Inbox and
 * Tickets are big enough to be pages of their own and sit in the sidebar
 * beside this one.
 */
export default function SupportBotIndex(props: SupportBotPageProps) {
    const { tab, tabs, status } = props;

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-bold">Support Bot</h1>
                        <p className="text-sm text-muted-foreground">
                            After-sales — refills, order status, and handing over to a person.
                        </p>
                    </div>

                    <StatusPill status={status} />
                </div>
            }
        >
            <Head title="Support Bot" />

            <nav
                className="scroll-slim -mx-1 mb-6 flex gap-1 overflow-x-auto border-b border-border px-1"
                aria-label="Support bot sections"
            >
                {tabs.map((name) => {
                    const isCurrent = name === tab;

                    return (
                        <Link
                            key={name}
                            href={route('support-bot', name)}
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

            {tab === 'overview' && props.overview && (
                <OverviewTab data={props.overview} status={status} />
            )}
            {tab === 'rules' && props.rules && (
                <RulesTab rules={props.rules} panels={props.panels ?? []} />
            )}
            {tab === 'templates' && props.templates && (
                <TemplatesTab data={props.templates} languages={props.languages ?? []} />
            )}
            {tab === 'settings' && props.settings && (
                <SettingsTab settings={props.settings} languages={props.languages ?? []} />
            )}
        </AuthenticatedLayout>
    );
}
