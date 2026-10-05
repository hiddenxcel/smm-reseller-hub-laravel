import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { Card, EmptyState, FieldError, NeedsAttention, StatusBadge } from './bits';
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
        <div className="space-y-4 sm:space-y-6">
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
        <li className="py-4 first:pt-0 last:pb-0">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-medium">{panel.name}</p>
                    <p className="font-data truncate text-xs text-muted-foreground">
                        {panel.api_url}
                    </p>
                </div>

                <StatusBadge tone={isActive ? 'good' : 'muted'}>
                    {isActive ? 'Active' : panel.status}
                </StatusBadge>
            </div>

            {/* The two facts worth a glance, side by side rather than a
                sentence to parse. */}
            <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div className="rounded-xl bg-muted/50 px-3 py-2">
                    <dt className="text-xs text-muted-foreground">Services</dt>
                    <dd className="font-medium [font-variant-numeric:tabular-nums]">
                        {panel.servicesCount ?? '—'}
                    </dd>
                </div>
                <div className="rounded-xl bg-muted/50 px-3 py-2">
                    <dt className="text-xs text-muted-foreground">Balance</dt>
                    <dd className="font-medium [font-variant-numeric:tabular-nums]">
                        {panel.balance !== null
                            ? `${panel.currency ?? ''} ${panel.balance}`.trim()
                            : '—'}
                    </dd>
                </div>
            </dl>

            <LowBalanceField panel={panel} />
        </li>
    );
}

/**
 * When to warn that this panel is running out of money.
 *
 * Per panel rather than one figure for the account: the same reseller can run
 * a main panel carrying the volume and a small one topped up for a single
 * service, and a threshold that suits one is noise on the other.
 *
 * An empty field is not "never warn" — it restores the default, and the
 * placeholder says so. Turning the warning off is a different decision, and
 * one this form deliberately does not offer.
 */
function LowBalanceField({ panel }: { panel: PanelRow }) {
    const { data, setData, patch, processing, errors, isDirty } = useForm({
        low_balance_threshold: panel.lowBalanceThreshold ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        patch(route('settings.panels.update', panel.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-3">
            <div className="flex items-center justify-between gap-3">
                <Label htmlFor={`threshold-${panel.id}`} className="text-sm">
                    Warn me below
                </Label>

                <div className="flex items-center gap-2">
                    {isDirty && (
                        <Button type="submit" size="sm" variant="outline" disabled={processing}>
                            {processing && <Loader2 className="size-3.5 animate-spin" />}
                            Save
                        </Button>
                    )}
                    <Input
                        id={`threshold-${panel.id}`}
                        type="number"
                        step="0.01"
                        min="0.01"
                        inputMode="decimal"
                        value={data.low_balance_threshold}
                        onChange={(event) =>
                            setData('low_balance_threshold', event.target.value)
                        }
                        placeholder={String(panel.defaultLowBalance)}
                        className="h-9 w-28 text-right"
                    />
                </div>
            </div>

            <p className="mt-1.5 text-xs text-muted-foreground">
                {panel.lowOnFunds
                    ? 'This panel is below its threshold now — top it up with your provider.'
                    : `Leave empty to use the default of ${panel.defaultLowBalance}.`}
            </p>

            <FieldError message={errors.low_balance_threshold} />
        </form>
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
            <Button variant="outline" className="w-full sm:w-auto" onClick={() => setOpen(true)}>
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
                    <Label htmlFor="api_key">API key</Label>
                    <Input
                        id="api_key"
                        type="password"
                        value={data.api_key}
                        onChange={(event) => setData('api_key', event.target.value)}
                        className="mt-1.5"
                        required
                    />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        Your panel&rsquo;s API key &mdash; usually under Account &rarr; API. It is
                        encrypted before we store it.
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
