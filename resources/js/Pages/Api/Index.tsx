import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { DocsTab } from './DocsTab';
import { KeysTab } from './KeysTab';
import { LogsTab } from './LogsTab';
import { ApiPageProps } from './types';

const TAB_LABELS: Record<string, string> = {
    keys: 'Keys',
    docs: 'Documentation',
    logs: 'Logs',
};

/**
 * Selling without WhatsApp.
 *
 * A reseller's customer with a shop of their own, or a script, can order
 * straight from code. This screen is where that access is handed out, written
 * down, and watched.
 *
 * Each tab is a URL rather than React state, as elsewhere in the app: the
 * server builds only the tab being viewed, and the logs query has no business
 * running because someone opened the documentation.
 */
export default function ApiIndex(props: ApiPageProps) {
    const { tab, tabs, endpoint } = props;

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-bold">API</h1>
                    <p className="text-sm text-muted-foreground">
                        Let your customers order from their own site or code, not just WhatsApp.
                    </p>
                </div>
            }
        >
            <Head title="API" />

            <nav
                className="scroll-slim -mx-1 mb-6 flex gap-1 overflow-x-auto border-b border-border px-1"
                aria-label="API sections"
            >
                {tabs.map((name) => {
                    const isCurrent = name === tab;

                    return (
                        <Link
                            key={name}
                            href={route('api-access', name)}
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

            {tab === 'keys' && (
                <KeysTab
                    keys={props.keys ?? []}
                    customers={props.customers ?? []}
                    endpoint={endpoint}
                />
            )}

            {tab === 'docs' && <DocsTab actions={props.actions ?? []} endpoint={endpoint} />}

            {tab === 'logs' && props.logs && (
                <LogsTab
                    logs={props.logs}
                    filters={props.filters ?? { key: '', failed: false }}
                    keys={props.keys ?? []}
                />
            )}
        </AuthenticatedLayout>
    );
}
