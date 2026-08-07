import OnboardingLayout, { WizardStep } from '@/Layouts/OnboardingLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertCircle, Loader2, Search } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

type PanelService = {
    provider_service_id: string;
    name: string;
    platform: string;
    category: string | null;
    cost_price: string;
    suggested_price: string;
    min_quantity: number;
    max_quantity: number;
    imported: boolean;
};

type Props = {
    step: string;
    steps: WizardStep[];
    completed: number;
    panel: { id: number; name: string };
    services: PanelService[];
    catalogueError: string | null;
    canSkip: boolean;
};

export default function ImportServices({
    step,
    steps,
    completed,
    panel,
    services,
    catalogueError,
    canSkip,
}: Props) {
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    // The payload is assembled at submit time from the selection, so there is
    // no form state to keep in step — errors come from the shared props.
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        if (! term) {
            return services;
        }

        return services.filter((service) =>
            `${service.platform} ${service.name}`.toLowerCase().includes(term),
        );
    }, [search, services]);

    const toggle = (service: PanelService) => {
        setSelected((current) => {
            const next = { ...current };

            if (service.provider_service_id in next) {
                delete next[service.provider_service_id];
            } else {
                // Prefill with our suggestion; the reseller can overwrite it.
                next[service.provider_service_id] = service.suggested_price;
            }

            return next;
        });
    };

    const setPrice = (id: string, price: string) => {
        setSelected((current) => ({ ...current, [id]: price }));
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const chosen = services
            .filter((service) => service.provider_service_id in selected)
            .map((service) => ({
                provider_service_id: service.provider_service_id,
                name: service.name,
                platform: service.platform,
                category: service.category,
                cost_price: service.cost_price,
                my_price: selected[service.provider_service_id],
                min_quantity: service.min_quantity,
                max_quantity: service.max_quantity,
            }));

        router.post(
            route('onboarding.services.store'),
            { panel_id: panel.id, services: chosen },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const chosenCount = Object.keys(selected).length;

    return (
        <OnboardingLayout step={step} steps={steps} completed={completed} canSkip={canSkip}>
            <Head title="Import your services" />

            <div>
                <h1 className="font-heading text-2xl font-extrabold">Import your services</h1>
                <p className="mt-2 text-muted-foreground">
                    These come from <strong>{panel.name}</strong>. Pick what you want to sell and
                    set your own price — the suggestion is cost plus 30%, and you can change it
                    any time.
                </p>

                {catalogueError && (
                    <p className="mt-6 flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
                        <AlertCircle className="mt-0.5 size-4 shrink-0" />
                        <span>{catalogueError}</span>
                    </p>
                )}

                {! catalogueError && services.length === 0 && (
                    <p className="mt-6 rounded-lg border border-dashed border-border p-6 text-sm text-muted-foreground">
                        That panel returned no services. Check that your catalogue is set up on the
                        panel itself, then reload.
                    </p>
                )}

                {services.length > 0 && (
                    <form onSubmit={submit} className="mt-6">
                        <div className="relative mb-4">
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Search services…"
                                className="pl-9"
                                aria-label="Search services"
                            />
                        </div>

                        <div className="max-h-[420px] divide-y divide-border overflow-y-auto rounded-xl border border-border">
                            {visible.map((service) => (
                                <ServiceRow
                                    key={service.provider_service_id}
                                    service={service}
                                    price={selected[service.provider_service_id]}
                                    onToggle={() => toggle(service)}
                                    onPriceChange={(price) =>
                                        setPrice(service.provider_service_id, price)
                                    }
                                />
                            ))}

                            {visible.length === 0 && (
                                <p className="p-6 text-center text-sm text-muted-foreground">
                                    Nothing matches “{search}”.
                                </p>
                            )}
                        </div>

                        {errors.services && (
                            <p className="mt-3 flex items-start gap-1.5 text-sm text-destructive">
                                <AlertCircle className="mt-0.5 size-4 shrink-0" />
                                <span>{errors.services}</span>
                            </p>
                        )}

                        <div className="mt-6 flex items-center gap-4">
                            <Button type="submit" size="lg" disabled={processing || chosenCount === 0}>
                                {processing && <Loader2 className="size-4 animate-spin" />}
                                {chosenCount === 0
                                    ? 'Pick at least one service'
                                    : `Import ${chosenCount} service${chosenCount === 1 ? '' : 's'}`}
                            </Button>

                            <p className="text-sm text-muted-foreground">
                                You can import more later.
                            </p>
                        </div>
                    </form>
                )}
            </div>
        </OnboardingLayout>
    );
}

function ServiceRow({
    service,
    price,
    onToggle,
    onPriceChange,
}: {
    service: PanelService;
    price?: string;
    onToggle: () => void;
    onPriceChange: (price: string) => void;
}) {
    const checked = price !== undefined;
    const inputId = `service-${service.provider_service_id}`;

    return (
        <div className={checked ? 'bg-accent/40 p-3' : 'p-3'}>
            <div className="flex items-start gap-3">
                <input
                    id={inputId}
                    type="checkbox"
                    checked={checked}
                    onChange={onToggle}
                    className="mt-1 size-4 shrink-0 accent-primary"
                />

                <label htmlFor={inputId} className="min-w-0 flex-1 cursor-pointer">
                    <span className="block text-sm font-medium">{service.name}</span>
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        {service.platform}
                        {service.category && ` · ${service.category}`}
                        {' · '}
                        costs {service.cost_price} per 1,000
                        {' · '}
                        {service.min_quantity.toLocaleString()}–
                        {service.max_quantity.toLocaleString()}
                    </span>
                </label>

                {service.imported && ! checked && (
                    <span className="shrink-0 rounded-md bg-muted px-2 py-1 text-xs text-muted-foreground">
                        Already imported
                    </span>
                )}

                {checked && (
                    <div className="shrink-0">
                        <label
                            htmlFor={`${inputId}-price`}
                            className="mb-1 block text-xs text-muted-foreground"
                        >
                            Your price /1k
                        </label>
                        <Input
                            id={`${inputId}-price`}
                            value={price}
                            onChange={(event) => onPriceChange(event.target.value)}
                            inputMode="decimal"
                            className="h-8 w-28 text-sm"
                        />
                    </div>
                )}
            </div>
        </div>
    );
}
