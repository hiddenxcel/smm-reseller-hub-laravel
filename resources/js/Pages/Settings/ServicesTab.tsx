import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Link, router, useForm } from '@inertiajs/react';
import { Loader2, Search } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';
import { Card, EmptyState, NeedsAttention } from './bits';
import { CatalogueService } from './types';

/**
 * Importing more of the panel's catalogue.
 *
 * Editing prices and retiring services already have their own screen, so this
 * deliberately does not repeat them — it links out. What only lives here is
 * pulling in services the reseller has not imported yet, which is the reason
 * to come back to the catalogue at all.
 */
export function ServicesTab({
    panel,
    services,
    catalogueError,
    importedCount,
}: {
    panel: { id: number; name: string } | null;
    services: CatalogueService[];
    catalogueError: string | null;
    importedCount: number;
}) {
    if (panel === null) {
        return (
            <NeedsAttention>
                Connect a panel first — there is no catalogue to import from until then.{' '}
                <Link href={route('settings', 'panel')} className="underline">
                    Go to Panel
                </Link>
            </NeedsAttention>
        );
    }

    return (
        <div className="space-y-4 sm:space-y-6">
            {importedCount === 0 && (
                <NeedsAttention>
                    You have not imported any services yet, so your bot has nothing to sell.
                </NeedsAttention>
            )}

            {/* Prices and retiring a service live on the Services screen, so
                this is only a count and a way there. */}
            <section className="flex items-center justify-between gap-4 rounded-2xl border border-border bg-card p-4 sm:p-5">
                <div className="min-w-0">
                    <p className="font-heading text-2xl font-extrabold [font-variant-numeric:tabular-nums]">
                        {importedCount}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {importedCount === 1 ? 'service' : 'services'} imported from {panel.name}
                    </p>
                </div>
                <Button variant="outline" asChild className="shrink-0">
                    <Link href={route('services.index')}>Manage prices</Link>
                </Button>
            </section>

            <ImportCard
                panelId={panel.id}
                services={services}
                catalogueError={catalogueError}
            />
        </div>
    );
}

function ImportCard({
    panelId,
    services,
    catalogueError,
}: {
    panelId: number;
    services: CatalogueService[];
    catalogueError: string | null;
}) {
    const [query, setQuery] = useState('');
    const [picked, setPicked] = useState<Record<string, string>>({});

    const { post, processing } = useForm({});

    const visible = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (needle === '') {
            return services.slice(0, 100);
        }

        return services
            .filter(
                (service) =>
                    service.name.toLowerCase().includes(needle) ||
                    service.platform.toLowerCase().includes(needle),
            )
            .slice(0, 100);
    }, [services, query]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const chosen = services
            .filter((service) => picked[service.provider_service_id] !== undefined)
            .map((service) => ({
                provider_service_id: service.provider_service_id,
                name: service.name,
                platform: service.platform,
                category: service.category,
                cost_price: service.cost_price,
                my_price: picked[service.provider_service_id],
                min_quantity: service.min_quantity,
                max_quantity: service.max_quantity,
            }));

        if (chosen.length === 0) {
            return;
        }

        router.post(
            route('onboarding.services.store'),
            { panel_id: panelId, services: chosen },
            { preserveScroll: true, onSuccess: () => setPicked({}) },
        );
    };

    if (catalogueError) {
        return (
            <Card title="Import more services">
                <NeedsAttention>{catalogueError}</NeedsAttention>
            </Card>
        );
    }

    const chosenCount = Object.keys(picked).length;

    return (
        <Card
            title="Import more services"
            description="Tick what you want to sell and set your price. Importing something you already have updates its price."
        >
            {services.length === 0 ? (
                <EmptyState>The panel returned no services.</EmptyState>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search the catalogue…"
                            className="pl-9"
                            aria-label="Search services"
                        />
                    </div>

                    <ul className="scroll-slim max-h-96 divide-y divide-border overflow-y-auto rounded-xl border border-border">
                        {visible.map((service) => {
                            const id = service.provider_service_id;
                            const isPicked = picked[id] !== undefined;

                            return (
                                <li
                                    key={id}
                                    className={`flex flex-wrap items-center gap-3 p-3 ${
                                        isPicked ? 'bg-accent/40' : ''
                                    }`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={isPicked}
                                        onChange={(event) =>
                                            setPicked((current) => {
                                                const next = { ...current };

                                                if (event.target.checked) {
                                                    next[id] = String(service.cost_price ?? '');
                                                } else {
                                                    delete next[id];
                                                }

                                                return next;
                                            })
                                        }
                                        className="size-4 shrink-0 accent-primary"
                                        aria-label={`Import ${service.name}`}
                                    />

                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm">{service.name}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {service.platform}
                                            {service.cost_price !== null && (
                                                <> · costs {service.cost_price}</>
                                            )}
                                        </p>
                                    </div>

                                    {isPicked && (
                                        <Input
                                            value={picked[id]}
                                            onChange={(event) =>
                                                setPicked((current) => ({
                                                    ...current,
                                                    [id]: event.target.value,
                                                }))
                                            }
                                            placeholder="Your price"
                                            className="ml-7 w-full sm:ml-0 sm:w-28"
                                            aria-label={`Your price for ${service.name}`}
                                            required
                                        />
                                    )}
                                </li>
                            );
                        })}
                    </ul>

                    {visible.length === 100 && (
                        <p className="text-xs text-muted-foreground">
                            Showing the first 100 — search to narrow it down.
                        </p>
                    )}

                    <Button
                        type="submit"
                        className="w-full sm:w-auto"
                        disabled={processing || chosenCount === 0}
                    >
                        {processing && <Loader2 className="size-4 animate-spin" />}
                        {chosenCount === 0
                            ? 'Select services to import'
                            : `Import ${chosenCount} service${chosenCount === 1 ? '' : 's'}`}
                    </Button>
                </form>
            )}
        </Card>
    );
}
