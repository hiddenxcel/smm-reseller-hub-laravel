import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/react';
import { Loader2, X } from 'lucide-react';
import { Dialog } from 'radix-ui';
import { FormEvent, useEffect } from 'react';
import { price } from './bits';
import { ServiceRow } from './types';

/**
 * Add a service, or edit one.
 *
 * The panel and its service id are only settable on create. Together they are
 * what ties a row to the panel and to its orders — changing either afterwards
 * would point the service at something else while keeping its price history,
 * which is a lie the history cannot undo.
 */
export default function ServiceForm({
    open,
    service,
    panels,
    onClose,
}: {
    open: boolean;
    /** null means "add"; a row means "edit that row". */
    service: ServiceRow | null;
    panels: Array<{ id: number; name: string }>;
    onClose: () => void;
}) {
    const isEdit = service !== null;

    const form = useForm({
        name: '',
        platform: '',
        category: '',
        description: '',
        provider_service_id: '',
        panel_id: '' as string | number,
        unit_label: 'Followers',
        cost_price: '',
        my_price: '',
        min_quantity: 100,
        max_quantity: 100000,
        link_instructions: '',
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        form.setDefaults({
            name: service?.name ?? '',
            platform: service?.platform ?? '',
            category: service?.category ?? '',
            description: service?.description ?? '',
            provider_service_id: service?.providerServiceId ?? '',
            panel_id: service?.panelId ?? '',
            unit_label: service?.unitLabel ?? 'Followers',
            cost_price: service?.cost != null ? String(service.cost) : '',
            my_price: service ? String(service.price) : '',
            min_quantity: service?.minQuantity ?? 100,
            max_quantity: service?.maxQuantity ?? 100000,
            link_instructions: '',
        });
        form.reset();
        form.clearErrors();
        // The form object is new every render; depending on it would loop.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, service?.id]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (isEdit) {
            form.patch(route('services.update', service.id), options);

            return;
        }

        // An unselected panel posts as an empty string, which is not an
        // integer as far as validation is concerned.
        form.transform((data) => ({
            ...data,
            panel_id: data.panel_id === '' ? null : Number(data.panel_id),
        }));

        form.post(route('services.store'), options);
    };

    // The margin this price would give, shown while they type it.
    const cost = Number.parseFloat(form.data.cost_price);
    const sell = Number.parseFloat(form.data.my_price);
    const profit = Number.isFinite(cost) && Number.isFinite(sell) ? sell - cost : null;
    const margin = profit !== null && sell > 0 ? (profit / sell) * 100 : null;

    return (
        <Dialog.Root open={open} onOpenChange={(next) => !next && onClose()}>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-[60] bg-foreground/25 backdrop-blur-[1px] data-[state=open]:animate-in data-[state=open]:fade-in" />

                <Dialog.Content
                    className="fixed left-1/2 top-1/2 z-[60] flex max-h-[85dvh] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 flex-col rounded-xl border border-border bg-background shadow-2xl data-[state=open]:animate-in data-[state=open]:fade-in data-[state=open]:zoom-in-95"
                    aria-describedby={undefined}
                >
                    <div className="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
                        <Dialog.Title className="font-heading text-lg font-extrabold tracking-tight">
                            {isEdit ? 'Edit service' : 'Add a service'}
                        </Dialog.Title>
                        <Dialog.Close asChild>
                            <button
                                type="button"
                                className="rounded-lg p-1 text-muted-foreground transition-colors hover:bg-accent"
                                aria-label="Close"
                            >
                                <X className="size-4" />
                            </button>
                        </Dialog.Close>
                    </div>

                    <form
                        onSubmit={submit}
                        className="min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-4"
                        id="service-form"
                    >
                        <Field label="Name" error={form.errors.name} required>
                            <input
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                placeholder="Instagram Followers — 30 day refill"
                                className={inputClass}
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Platform" error={form.errors.platform} required>
                                <input
                                    value={form.data.platform}
                                    onChange={(event) =>
                                        form.setData('platform', event.target.value)
                                    }
                                    placeholder="Instagram"
                                    className={inputClass}
                                />
                            </Field>

                            <Field label="Category" error={form.errors.category}>
                                <input
                                    value={form.data.category}
                                    onChange={(event) =>
                                        form.setData('category', event.target.value)
                                    }
                                    placeholder="Followers"
                                    className={inputClass}
                                />
                            </Field>
                        </div>

                        {!isEdit && (
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="Panel" error={form.errors.panel_id}>
                                    <select
                                        value={form.data.panel_id}
                                        onChange={(event) =>
                                            form.setData('panel_id', event.target.value)
                                        }
                                        className={inputClass}
                                    >
                                        <option value="">None (catalogue only)</option>
                                        {panels.map((panel) => (
                                            <option key={panel.id} value={panel.id}>
                                                {panel.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>

                                <Field
                                    label="Panel service ID"
                                    error={form.errors.provider_service_id}
                                    required
                                >
                                    <input
                                        value={form.data.provider_service_id}
                                        onChange={(event) =>
                                            form.setData('provider_service_id', event.target.value)
                                        }
                                        placeholder="1234"
                                        className={`${inputClass} font-data`}
                                    />
                                </Field>
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Panel cost / 1,000" error={form.errors.cost_price}>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.0001"
                                    value={form.data.cost_price}
                                    onChange={(event) =>
                                        form.setData('cost_price', event.target.value)
                                    }
                                    placeholder="—"
                                    className={`${inputClass} font-data tabular-nums`}
                                />
                            </Field>

                            <Field label="Your price / 1,000" error={form.errors.my_price} required>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.0001"
                                    value={form.data.my_price}
                                    onChange={(event) =>
                                        form.setData('my_price', event.target.value)
                                    }
                                    className={`${inputClass} font-data tabular-nums`}
                                />
                            </Field>
                        </div>

                        {profit !== null && (
                            <p
                                className={`text-xs ${profit < 0 ? 'text-destructive' : 'text-muted-foreground'}`}
                            >
                                {profit < 0 ? 'Losing ' : 'Earning '}
                                {price(Math.abs(profit))} per 1,000
                                {margin !== null && ` · ${margin.toFixed(1)}% margin`}
                            </p>
                        )}

                        <div className="grid grid-cols-3 gap-3">
                            <Field label="Minimum" error={form.errors.min_quantity} required>
                                <input
                                    type="number"
                                    min="1"
                                    value={form.data.min_quantity}
                                    onChange={(event) =>
                                        form.setData('min_quantity', Number(event.target.value))
                                    }
                                    className={`${inputClass} font-data tabular-nums`}
                                />
                            </Field>

                            <Field label="Maximum" error={form.errors.max_quantity} required>
                                <input
                                    type="number"
                                    min="1"
                                    value={form.data.max_quantity}
                                    onChange={(event) =>
                                        form.setData('max_quantity', Number(event.target.value))
                                    }
                                    className={`${inputClass} font-data tabular-nums`}
                                />
                            </Field>

                            <Field label="Unit" error={form.errors.unit_label}>
                                <input
                                    value={form.data.unit_label}
                                    onChange={(event) =>
                                        form.setData('unit_label', event.target.value)
                                    }
                                    className={inputClass}
                                />
                            </Field>
                        </div>

                        <Field label="Description" error={form.errors.description}>
                            <textarea
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                                rows={2}
                                placeholder="What the customer gets"
                                className={`${inputClass} h-auto py-2`}
                            />
                        </Field>

                        <Field label="Link help" error={form.errors.link_instructions}>
                            <input
                                value={form.data.link_instructions}
                                onChange={(event) =>
                                    form.setData('link_instructions', event.target.value)
                                }
                                placeholder="Send your profile link, not a post link"
                                className={inputClass}
                            />
                        </Field>
                    </form>

                    <div className="flex justify-end gap-2 border-t border-border px-5 py-3">
                        <Button type="button" variant="ghost" size="sm" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="service-form"
                            size="sm"
                            disabled={form.processing}
                        >
                            {form.processing && <Loader2 className="size-3.5 animate-spin" />}
                            {isEdit ? 'Save' : 'Add service'}
                        </Button>
                    </div>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

const inputClass =
    'h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30';

function Field({
    label,
    error,
    required = false,
    children,
}: {
    label: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-muted-foreground">
                {label}
                {required && <span className="text-destructive"> *</span>}
            </span>
            {children}
            {error && <span className="mt-1 block text-xs text-destructive">{error}</span>}
        </label>
    );
}
