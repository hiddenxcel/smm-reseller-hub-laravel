import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Pencil, Plus, Power } from 'lucide-react';
import { useState } from 'react';
import { Empty, money } from '../bits';
import { PlanRow } from '../types';

type Props = {
    plans: PlanRow[];
    serviceKeys: string[];
    canManage: boolean;
};

const SERVICE_LABELS: Record<string, string> = {
    order_bot: 'Order Bot',
    support_bot: 'Support Bot',
    ai_tickets: 'AI Tickets',
    ai_chat: 'AI Chat',
    number_rental: 'Number Rental',
};

export default function PlansIndex({ plans, serviceKeys, canManage }: Props) {
    const [editing, setEditing] = useState<PlanRow | null>(null);
    const [creating, setCreating] = useState(false);

    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-extrabold">Plans</h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            What each service costs. Changing a price affects the next
                            renewal, never a subscription already paid for.
                        </p>
                    </div>

                    {canManage && (
                        <button
                            type="button"
                            onClick={() => {
                                setEditing(null);
                                setCreating(true);
                            }}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                        >
                            <Plus className="size-4" />
                            New plan
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Plans — Control" />

            {plans.length === 0 ? (
                <Empty>No plans yet. Nothing is on sale.</Empty>
            ) : (
                <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    {plans.map((plan) => (
                        <PlanCard
                            key={plan.id}
                            plan={plan}
                            canManage={canManage}
                            onEdit={() => {
                                setCreating(false);
                                setEditing(plan);
                            }}
                        />
                    ))}
                </div>
            )}

            {(editing || creating) && (
                <PlanDialog
                    plan={editing}
                    serviceKeys={serviceKeys}
                    onClose={() => {
                        setEditing(null);
                        setCreating(false);
                    }}
                />
            )}
        </AdminLayout>
    );
}

function PlanCard({
    plan,
    canManage,
    onEdit,
}: {
    plan: PlanRow;
    canManage: boolean;
    onEdit: () => void;
}) {
    const retired = plan.status !== 'active';

    return (
        <div
            className={[
                'flex flex-col rounded-xl border bg-card p-5',
                retired ? 'border-dashed border-border opacity-70' : 'border-border',
            ].join(' ')}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="font-heading truncate font-bold">{plan.name}</h2>
                    <p className="truncate text-xs text-muted-foreground">
                        {SERVICE_LABELS[plan.service] ?? plan.service} · {plan.code}
                    </p>
                </div>

                {retired && (
                    <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted-foreground">
                        Off sale
                    </span>
                )}
            </div>

            <p className="font-heading mt-3 text-2xl font-extrabold tabular-nums">
                {money(plan.monthly)}
                <span className="ml-1 text-sm font-normal text-muted-foreground">
                    /month
                </span>
            </p>

            {/* A plan can be priced and still not offered: config decides what
                checkout sells, and the mismatch is worth saying out loud. */}
            {!plan.sellable && !retired && (
                <p className="mt-2 flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-300">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                    Priced but not listed in billing config — checkout will not offer
                    it.
                </p>
            )}

            {plan.terms.length > 0 && (
                <dl className="mt-3 space-y-1 text-xs">
                    {plan.terms.map((term) => (
                        <div key={term.months} className="flex justify-between gap-2">
                            <dt className="text-muted-foreground">{term.label}</dt>
                            <dd className="font-medium tabular-nums">
                                {money(term.total)}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}

            <div className="mt-4 border-t border-border pt-3 text-xs text-muted-foreground">
                <p>
                    <span className="font-semibold tabular-nums text-foreground">
                        {plan.liveSubscriptions.toLocaleString()}
                    </span>{' '}
                    live subscription{plan.liveSubscriptions === 1 ? '' : 's'}
                </p>
                <p className="mt-1">
                    {plan.limits.orders.toLocaleString()} orders ·{' '}
                    {plan.limits.messages.toLocaleString()} messages ·{' '}
                    {plan.limits.numbers} number{plan.limits.numbers === 1 ? '' : 's'}
                </p>
            </div>

            {canManage && (
                <div className="mt-4 flex gap-2">
                    <button
                        type="button"
                        onClick={onEdit}
                        className="flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-border px-2 py-1.5 text-xs font-medium transition-colors hover:bg-accent"
                    >
                        <Pencil className="size-3.5" />
                        Edit
                    </button>
                    <RetireButton plan={plan} retired={retired} />
                </div>
            )}
        </div>
    );
}

function RetireButton({ plan, retired }: { plan: PlanRow; retired: boolean }) {
    const [confirming, setConfirming] = useState(false);

    if (retired) {
        return (
            <button
                type="button"
                onClick={() => router.post(route('admin.plans.act', [plan.id, 'restore']))}
                className="flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-border px-2 py-1.5 text-xs font-medium transition-colors hover:bg-accent"
            >
                <Power className="size-3.5 text-[#006300] dark:text-[#0ca30c]" />
                Put on sale
            </button>
        );
    }

    if (!confirming) {
        return (
            <button
                type="button"
                onClick={() => setConfirming(true)}
                className="flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-border px-2 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent"
            >
                <Power className="size-3.5" />
                Retire
            </button>
        );
    }

    return (
        <button
            type="button"
            onClick={() => {
                router.post(route('admin.plans.act', [plan.id, 'retire']));
                setConfirming(false);
            }}
            title={`${plan.liveSubscriptions} live subscriptions keep running`}
            className="flex-1 rounded-lg bg-destructive px-2 py-1.5 text-xs font-semibold text-white transition-opacity hover:opacity-90"
        >
            Confirm retire
        </button>
    );
}

function PlanDialog({
    plan,
    serviceKeys,
    onClose,
}: {
    plan: PlanRow | null;
    serviceKeys: string[];
    onClose: () => void;
}) {
    const isNew = plan === null;

    const { data, setData, post, patch, processing, errors } = useForm({
        code: plan?.code ?? '',
        name: plan?.name ?? '',
        description: plan?.description ?? '',
        service_key: plan?.service ?? serviceKeys[0] ?? 'order_bot',
        price_monthly: String(plan?.monthly ?? ''),
        price_yearly: String(plan?.yearly ?? ''),
        currency: plan?.currency ?? 'USD',
        max_panels: String(plan?.limits.panels ?? 1),
        max_numbers: String(plan?.limits.numbers ?? 1),
        max_orders_monthly: String(plan?.limits.orders ?? 1000),
        max_messages_monthly: String(plan?.limits.messages ?? 5000),
        max_refills_monthly: String(plan?.limits.refills ?? 100),
        status: plan?.status ?? 'active',
        sort_order: String(plan?.sortOrder ?? 0),
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (isNew) {
            post(route('admin.plans.store'), { onSuccess: onClose });
        } else {
            patch(route('admin.plans.update', plan.id), { onSuccess: onClose });
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-foreground/40 p-4">
            <form
                onSubmit={submit}
                className="my-8 w-full max-w-lg rounded-xl border border-border bg-card p-6"
            >
                <h2 className="font-heading text-lg font-extrabold">
                    {isNew ? 'New plan' : `Edit ${plan.name}`}
                </h2>

                {!isNew && plan.liveSubscriptions > 0 && (
                    <p className="mt-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200">
                        {plan.liveSubscriptions} reseller
                        {plan.liveSubscriptions === 1 ? '' : 's'} currently on this
                        plan. They keep their price until they renew.
                    </p>
                )}

                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                    <Field label="Name" error={errors.name} className="sm:col-span-2">
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Code" error={errors.code}>
                        <input
                            type="text"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Service" error={errors.service_key}>
                        <select
                            value={data.service_key}
                            onChange={(e) => setData('service_key', e.target.value)}
                            className={inputClass}
                        >
                            {serviceKeys.map((key) => (
                                <option key={key} value={key}>
                                    {SERVICE_LABELS[key] ?? key}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field
                        label="Description"
                        error={errors.description}
                        className="sm:col-span-2"
                    >
                        <textarea
                            value={data.description ?? ''}
                            onChange={(e) => setData('description', e.target.value)}
                            rows={2}
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Monthly price" error={errors.price_monthly}>
                        <input
                            type="number"
                            step="0.01"
                            value={data.price_monthly}
                            onChange={(e) => setData('price_monthly', e.target.value)}
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Yearly price" error={errors.price_yearly}>
                        <input
                            type="number"
                            step="0.01"
                            value={data.price_yearly}
                            onChange={(e) => setData('price_yearly', e.target.value)}
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Currency" error={errors.currency}>
                        <input
                            type="text"
                            value={data.currency}
                            maxLength={3}
                            onChange={(e) =>
                                setData('currency', e.target.value.toUpperCase())
                            }
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Sort order" error={errors.sort_order}>
                        <input
                            type="number"
                            value={data.sort_order}
                            onChange={(e) => setData('sort_order', e.target.value)}
                            className={inputClass}
                        />
                    </Field>
                </div>

                <h3 className="font-heading mt-5 text-sm font-bold">Limits</h3>
                <div className="mt-2 grid gap-3 sm:grid-cols-2">
                    <Field label="Panels" error={errors.max_panels}>
                        <input
                            type="number"
                            value={data.max_panels}
                            onChange={(e) => setData('max_panels', e.target.value)}
                            className={inputClass}
                        />
                    </Field>
                    <Field label="Numbers" error={errors.max_numbers}>
                        <input
                            type="number"
                            value={data.max_numbers}
                            onChange={(e) => setData('max_numbers', e.target.value)}
                            className={inputClass}
                        />
                    </Field>
                    <Field label="Orders / month" error={errors.max_orders_monthly}>
                        <input
                            type="number"
                            value={data.max_orders_monthly}
                            onChange={(e) =>
                                setData('max_orders_monthly', e.target.value)
                            }
                            className={inputClass}
                        />
                    </Field>
                    <Field label="Messages / month" error={errors.max_messages_monthly}>
                        <input
                            type="number"
                            value={data.max_messages_monthly}
                            onChange={(e) =>
                                setData('max_messages_monthly', e.target.value)
                            }
                            className={inputClass}
                        />
                    </Field>
                    <Field label="Refills / month" error={errors.max_refills_monthly}>
                        <input
                            type="number"
                            value={data.max_refills_monthly}
                            onChange={(e) =>
                                setData('max_refills_monthly', e.target.value)
                            }
                            className={inputClass}
                        />
                    </Field>
                    <Field label="Status" error={errors.status}>
                        <select
                            value={data.status}
                            onChange={(e) => setData('status', e.target.value)}
                            className={inputClass}
                        >
                            <option value="active">On sale</option>
                            <option value="inactive">Off sale</option>
                        </select>
                    </Field>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-border px-3 py-2 text-sm transition-colors hover:bg-accent"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : isNew ? 'Create plan' : 'Save changes'}
                    </button>
                </div>
            </form>
        </div>
    );
}

const inputClass =
    'w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';

function Field({
    label,
    error,
    className,
    children,
}: {
    label: string;
    error?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={className}>
            <label className="mb-1 block text-xs font-medium text-muted-foreground">
                {label}
            </label>
            {children}
            {error && <p className="mt-1 text-xs text-destructive">{error}</p>}
        </div>
    );
}
