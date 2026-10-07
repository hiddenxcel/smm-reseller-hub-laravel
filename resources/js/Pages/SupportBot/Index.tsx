import SegmentedTabs from '@/components/SegmentedTabs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { LayoutGrid, MessageSquareText, Phone, Settings2, ShieldCheck } from 'lucide-react';
import { NumberTab } from '../OrderBot/NumberTab';
import { StatusPill } from '../OrderBot/bits';
import { OverviewTab } from './OverviewTab';
import { RulesTab } from './RulesTab';
import { SettingsTab } from './SettingsTab';
import { TemplatesTab } from './TemplatesTab';
import { SupportBotPageProps } from './types';

const TABS = {
    overview: { label: 'Overview', icon: LayoutGrid },
    number: { label: 'Number', icon: Phone },
    rules: { label: 'Rules', icon: ShieldCheck },
    templates: { label: 'Wording', icon: MessageSquareText },
    settings: { label: 'Settings', icon: Settings2 },
} as const;

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
        <AuthenticatedLayout>
            <Head title="Support Bot" />

            <div className="mx-auto max-w-3xl space-y-4 sm:space-y-6">
                <header className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="font-heading text-xl font-extrabold tracking-tight sm:text-2xl">
                            Support Bot
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
                    label="Support bot sections"
                    current={tab}
                    tabs={tabs.map((name) => {
                        const meta = TABS[name as keyof typeof TABS] ?? TABS.overview;

                        return {
                            key: name,
                            label: meta.label,
                            icon: meta.icon,
                            href: route('support-bot', name),
                        };
                    })}
                />

                {tab === 'overview' && props.overview && (
                    <OverviewTab data={props.overview} status={status} />
                )}
                {tab === 'number' && props.numbers && <NumberTab data={props.numbers} />}
                {tab === 'rules' && props.rules && (
                    <RulesTab rules={props.rules} panels={props.panels ?? []} />
                )}
                {tab === 'templates' && props.templates && (
                    <TemplatesTab data={props.templates} languages={props.languages ?? []} />
                )}
                {tab === 'settings' && props.settings && (
                    <SettingsTab settings={props.settings} languages={props.languages ?? []} />
                )}
            </div>
        </AuthenticatedLayout>
    );
}
