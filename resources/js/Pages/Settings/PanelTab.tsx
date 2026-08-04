import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { Card, EmptyState, FieldError, NeedsAttention } from './bits';
import { PanelRow } from './types';

/**
 * The panels a reseller sells from.
 *
 * Adding and re-connecting are the same action: the store is keyed on
 * tenant + api_url, so submitting an address that already exists rotates its
 * key rather than making a second row. That is what "reconnect" means here.
 */
export function PanelTab({ panels }: { panels: PanelRow[] }) {
    const active = panels.filter((panel) => panel.status === 'active');

    return (
        <div className="space-y-6">
            {active.length === 0 && (
                <NeedsAttention>
                    No panel is connected, so your bot has nothing to sell. Connect one below.
                </NeedsAttention>
            )}

            <Card
                title="Connected panels"
                description="Where your orders are actually placed."
            >
                {panels.length === 0 ? (
                    <EmptyState>Nothing connected yet.</EmptyState>
                ) : (
                    <ul className="divide-y divide-border">
                        {panels.map((panel) => (
                            <PanelItem key={panel.id} panel={panel} />
                        ))}
                    </ul>
                )}
            </Card>

            <ConnectPanelCard hasPanel={panels.length > 0} />
        </div>
    );
}

function PanelItem({ panel }: { panel: PanelRow }) {
    const isActive = panel.status === 'active';

    return (
        <li className="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
            <div className="min-w-0">
                <p className="font-medium">{panel.name}</p>
                <p className="font-data truncate text-xs text-muted-foreground">
                    {panel.api_url}
                </p>

                <p className="mt-1 text-xs text-muted-foreground">
                    {panel.servicesCount !== null && (
                        <>{panel.servicesCount} services in its catalogue</>
                    )}
                    {panel.balance !== null && (
                        <>
                            {panel.servicesCount !== null && ' · '}
                            balance {panel.currency ?? ''} {panel.balance}
                        </>
                    )}
                </p>
            </div>

            <span
                className={[
                    'rounded-full px-2.5 py-1 text-xs font-medium',
                    isActive
                        ? 'bg-primary/10 text-primary'
                        : 'bg-muted text-muted-foreground',
                ].join(' ')}
            >
                {isActive ? 'Active' : panel.status}
            </span>
        </li>
    );
}

/**
 * The wizard's own form, reachable without the wizard.
 *
 * It posts to the same route, which redirects `back()` — so submitting from
 * here returns here, and submitting from the wizard returns there.
 */
function ConnectPanelCard({ hasPanel }: { hasPanel: boolean }) {
    const [open, setOpen] = useState(! hasPanel);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        api_url: '',
        api_key: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('onboarding.panel.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    if (! open) {
        return (
            <Button variant="outline" onClick={() => setOpen(true)}>
                Connect another panel
            </Button>
        );
    }

    return (
        <Card
            title={hasPanel ? 'Connect another panel' : 'Connect your panel'}
            description="Entering an address you already use will rotate its key instead of adding a duplicate."
        >
            <form onSubmit={submit} className="max-w-lg space-y-5">
                <div>
                    <Label htmlFor="name">Panel name</Label>
                    <Input
                        id="name"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        placeholder="My main panel"
                        className="mt-1.5"
                        required
                    />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        Just for you — it is how this panel appears in your dashboard.
                    </p>
                    <FieldError message={errors.name} />
                </div>

                <div>
                    <Label htmlFor="api_url">Panel URL</Label>
                    <Input
                        id="api_url"
                        value={data.api_url}
                        onChange={(event) => setData('api_url', event.target.value)}
                        placeholder="yourpanel.com"
                        className="mt-1.5"
                        required
                    />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        The address on its own is enough — no need for /api/v2.
                    </p>
                    <FieldError message={errors.api_url} />
                </div>

                <div>
                    <Label htmlFor="api_key">Admin API key</Label>
                    <Input
                        id="api_key"
                        type="password"
                        value={data.api_key}
                        onChange={(event) => setData('api_key', event.target.value)}
                        className="mt-1.5"
                        required
                    />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        Find it in your panel under API settings. It is encrypted before we
                        store it.
                    </p>
                    <FieldError message={errors.api_key} />
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="size-4 animate-spin" />}
                        {processing ? 'Checking the connection…' : 'Save panel'}
                    </Button>

                    {hasPanel && (
                        <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                    )}
                </div>
            </form>
        </Card>
    );
}
