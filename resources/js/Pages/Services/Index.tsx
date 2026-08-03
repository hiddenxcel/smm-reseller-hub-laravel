import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    Download,
    Eye,
    EyeOff,
    Loader2,
    Package,
    PauseCircle,
    Plus,
    RefreshCw,
    Star,
    Tags,
    Trash2,
    Wand2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    compact,
    FeaturedStar,
    MarginCell,
    price,
    relativeTime,
    StatusBadge,
} from './bits';
import BulkPricingDialog from './BulkPricingDialog';
import FilterBar from './FilterBar';
import ImportDialog from './ImportDialog';
import KpiRow from './KpiRow';
import PlatformSidebar from './PlatformSidebar';
import RulesDialog from './RulesDialog';
import ServiceDrawer from './ServiceDrawer';
import ServiceForm from './ServiceForm';
import { ServiceRow, ServicesPageProps, SortKey } from './types';
import { useServiceFilters } from './useServiceFilters';

const COLUMNS: Array<{
    key: SortKey | null;
    label: string;
    align?: 'right';
    className?: string;
    hideBelow?: 'lg' | 'xl';
}> = [
    { key: 'name', label: 'Service' },
    { key: null, label: 'Provider', className: 'w-[9rem]', hideBelow: 'xl' },
    { key: 'cost_price', label: 'Cost', align: 'right', className: 'w-[6.5rem]' },
    { key: 'my_price', label: 'Price', align: 'right', className: 'w-[6.5rem]' },
    { key: 'profit', label: 'Profit', align: 'right', className: 'w-[6.5rem]', hideBelow: 'lg' },
    { key: 'margin', label: 'Margin', align: 'right', className: 'w-[6rem]' },
    { key: null, label: 'Orders', align: 'right', className: 'w-[5.5rem]', hideBelow: 'xl' },
    { key: null, label: 'Status', className: 'w-[7.5rem]' },
    { key: 'updated_at', label: 'Updated', align: 'right', className: 'w-[7rem]', hideBelow: 'xl' },
];

export default function Services({
    services,
    filters,
    isFiltered,
    kpis,
    tabCounts,
    platforms,
    categories,
    panels,
    rules,
    pageSizes,
    bulkLimits,
}: ServicesPageProps) {
    const { apply, sortBy, reset, busy } = useServiceFilters(filters);

    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [selectedBeyondPage, setSelectedBeyondPage] = useState(false);
    const [openId, setOpenId] = useState<number | null>(null);
    const [editing, setEditing] = useState<ServiceRow | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [pricingOpen, setPricingOpen] = useState(false);
    const [rulesOpen, setRulesOpen] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [acting, setActing] = useState(false);

    const rows = services.data;
    const meta = services.meta;

    // A selection only means anything against the rows it was made on.
    useEffect(() => {
        setSelected(new Set());
        setSelectedBeyondPage(false);
    }, [filters, meta.currentPage]);

    const openService = useMemo(
        () => rows.find((row) => row.id === openId) ?? null,
        [rows, openId],
    );

    const pageIds = useMemo(() => rows.map((row) => row.id), [rows]);
    const allOnPageSelected = pageIds.length > 0 && pageIds.every((id) => selected.has(id));

    const toggleRow = useCallback((id: number) => {
        setSelected((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);

            return next;
        });
    }, []);

    const toggleAllOnPage = useCallback(() => {
        setSelectedBeyondPage(false);
        setSelected((current) =>
            pageIds.every((id) => current.has(id)) ? new Set() : new Set(pageIds),
        );
    }, [pageIds]);

    const selectAllMatching = useCallback(async () => {
        const params = new URLSearchParams(window.location.search);
        params.delete('page');

        const response = await fetch(
            `${route('services.matching-ids')}?${params.toString()}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            return;
        }

        const body: { ids: number[] } = await response.json();

        setSelected(new Set(body.ids));
        setSelectedBeyondPage(true);
    }, []);

    const runAction = useCallback(
        (
            service: ServiceRow,
            action: 'status' | 'flag' | 'duplicate' | 'price',
            payload: Record<string, unknown> = {},
        ) => {
            setActing(true);

            router.post(
                route('services.act', service.id),
                { action, ...payload },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () => setActing(false),
                },
            );
        },
        [],
    );

    const runBulk = useCallback(
        (action: string) => {
            setActing(true);

            router.post(
                route('services.bulk'),
                { action, ids: Array.from(selected) },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        setSelected(new Set());
                        setSelectedBeyondPage(false);
                    },
                    onFinish: () => setActing(false),
                },
            );
        },
        [selected],
    );

    const deleteService = useCallback((service: ServiceRow) => {
        setActing(true);

        router.delete(route('services.destroy', service.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setOpenId(null),
            onFinish: () => setActing(false),
        });
    }, []);

    const syncPanel = useCallback(() => {
        if (panels.length === 0) {
            return;
        }

        setActing(true);

        router.post(
            route('services.sync'),
            { panel_id: filters.panel ?? panels[0].id },
            { preserveScroll: true, onFinish: () => setActing(false) },
        );
    }, [panels, filters.panel]);

    return (
        <AuthenticatedLayout bleed>
            <Head title="Services" />

            <div className="sticky top-0 z-30 border-b border-border bg-background/95 px-4 pb-3 pt-5 backdrop-blur sm:px-8">
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-2xl font-extrabold tracking-tight">
                            Services
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            What your bot sells, and what you earn on it.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {busy && (
                            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Loader2 className="size-3.5 animate-spin" aria-hidden />
                                Updating
                            </span>
                        )}

                        <Button
                            size="sm"
                            variant="outline"
                            className="h-9"
                            onClick={() => setRulesOpen(true)}
                        >
                            <Wand2 className="size-3.5" />
                            Rules
                            {rules.filter((rule) => rule.active).length > 0 && (
                                <span className="font-data ms-1 rounded-full bg-primary/10 px-1.5 text-[0.7rem] text-primary tabular-nums">
                                    {rules.filter((rule) => rule.active).length}
                                </span>
                            )}
                        </Button>

                        <Button
                            size="sm"
                            variant="outline"
                            className="h-9"
                            disabled={acting || panels.length === 0}
                            title={panels.length === 0 ? 'Connect a panel first' : undefined}
                            onClick={syncPanel}
                        >
                            <RefreshCw className="size-3.5" />
                            Sync
                        </Button>

                        <a
                            href={route('services.export', currentQuery())}
                            className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm transition-colors hover:bg-accent"
                        >
                            <Download className="size-3.5" aria-hidden />
                            Export
                        </a>

                        <Button size="sm" variant="outline" className="h-9" onClick={() => setImportOpen(true)}>
                            Import
                        </Button>

                        <Button
                            size="sm"
                            className="h-9"
                            onClick={() => {
                                setEditing(null);
                                setFormOpen(true);
                            }}
                        >
                            <Plus className="size-3.5" />
                            Add service
                        </Button>
                    </div>
                </div>

                <KpiRow
                    kpis={kpis}
                    onFilterUnderwater={() => apply({ margin: 'loss', page: 1 })}
                />

                <div className="mt-4">
                    <FilterBar
                        filters={filters}
                        tabCounts={tabCounts}
                        panels={panels}
                        isFiltered={isFiltered}
                        onApply={apply}
                        onReset={reset}
                    />
                </div>
            </div>

            <div className="px-4 pb-24 pt-4 sm:px-8">
                <div className="lg:grid lg:grid-cols-[13rem_1fr] lg:gap-6">
                    {/* The sidebar is the fastest way into a catalogue of
                        thousands, so it holds its own column on wide screens
                        and folds above the table on narrow ones. */}
                    <aside className="mb-4 lg:mb-0">
                        <PlatformSidebar
                            platforms={platforms}
                            categories={categories}
                            selectedPlatform={filters.platform}
                            selectedCategory={filters.category}
                            total={kpis?.total ?? meta.total}
                            onSelect={(platform, category) =>
                                apply({ platform, category: category ?? null, page: 1 })
                            }
                        />
                    </aside>

                    <div className="min-w-0">
                        {rows.length === 0 ? (
                            <EmptyState
                                isFiltered={isFiltered}
                                hasPanels={panels.length > 0}
                                onReset={reset}
                                onImport={() => setImportOpen(true)}
                            />
                        ) : (
                            <>
                                <div className="space-y-2 md:hidden">
                                    {rows.map((service) => (
                                        <MobileCard
                                            key={service.id}
                                            service={service}
                                            selected={selected.has(service.id)}
                                            onToggle={toggleRow}
                                            onOpen={setOpenId}
                                        />
                                    ))}
                                </div>

                                <div className="hidden overflow-x-auto rounded-xl border border-border md:block">
                                    <table className="w-full min-w-[48rem] border-collapse text-sm">
                                        <thead>
                                            <tr className="border-b border-border bg-muted/40 text-left">
                                                <th scope="col" className="w-10 px-3 py-2.5">
                                                    <input
                                                        type="checkbox"
                                                        checked={allOnPageSelected}
                                                        onChange={toggleAllOnPage}
                                                        aria-label="Select every service on this page"
                                                        className="size-4 cursor-pointer rounded border-border accent-primary"
                                                    />
                                                </th>

                                                {COLUMNS.map((column) => (
                                                    <th
                                                        key={column.label}
                                                        scope="col"
                                                        className={[
                                                            'px-3 py-2.5 text-xs font-semibold text-muted-foreground',
                                                            column.align === 'right'
                                                                ? 'text-right'
                                                                : '',
                                                            column.hideBelow === 'lg'
                                                                ? 'hidden lg:table-cell'
                                                                : '',
                                                            column.hideBelow === 'xl'
                                                                ? 'hidden xl:table-cell'
                                                                : '',
                                                            column.className ?? '',
                                                        ].join(' ')}
                                                        aria-sort={
                                                            column.key && filters.sort === column.key
                                                                ? filters.dir === 'asc'
                                                                    ? 'ascending'
                                                                    : 'descending'
                                                                : undefined
                                                        }
                                                    >
                                                        {column.key === null ? (
                                                            column.label
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                onClick={() => sortBy(column.key!)}
                                                                className={[
                                                                    'inline-flex items-center gap-1 transition-colors hover:text-foreground',
                                                                    column.align === 'right'
                                                                        ? 'flex-row-reverse'
                                                                        : '',
                                                                ].join(' ')}
                                                            >
                                                                {column.label}
                                                                <SortIcon
                                                                    active={
                                                                        filters.sort === column.key
                                                                    }
                                                                    dir={filters.dir}
                                                                />
                                                            </button>
                                                        )}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>

                                        <tbody>
                                            {rows.map((service) => (
                                                <Row
                                                    key={service.id}
                                                    service={service}
                                                    selected={selected.has(service.id)}
                                                    onToggle={toggleRow}
                                                    onOpen={setOpenId}
                                                />
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <Pagination
                                    meta={meta}
                                    pageSizes={pageSizes}
                                    onPage={(page) => apply({ page })}
                                    onPerPage={(perPage) => apply({ perPage, page: 1 })}
                                />
                            </>
                        )}
                    </div>
                </div>
            </div>

            {selected.size > 0 && (
                <BulkBar
                    count={selected.size}
                    totalMatching={meta.total}
                    allSelected={selectedBeyondPage}
                    limits={bulkLimits}
                    pending={acting}
                    onAction={runBulk}
                    onPricing={() => setPricingOpen(true)}
                    onSelectAllMatching={selectAllMatching}
                    onClear={() => {
                        setSelected(new Set());
                        setSelectedBeyondPage(false);
                    }}
                />
            )}

            <ServiceDrawer
                service={openService}
                pending={acting}
                onClose={() => setOpenId(null)}
                onAction={runAction}
                onEdit={(service) => {
                    setEditing(service);
                    setFormOpen(true);
                }}
                onDelete={deleteService}
            />

            <ServiceForm
                open={formOpen}
                service={editing}
                panels={panels}
                onClose={() => setFormOpen(false)}
            />

            <BulkPricingDialog
                open={pricingOpen}
                ids={Array.from(selected)}
                limit={bulkLimits.pricing}
                onClose={() => setPricingOpen(false)}
                onApplied={() => {
                    setSelected(new Set());
                    setSelectedBeyondPage(false);
                }}
            />

            <RulesDialog
                open={rulesOpen}
                rules={rules}
                panels={panels}
                onClose={() => setRulesOpen(false)}
            />

            <ImportDialog
                open={importOpen}
                panels={panels}
                rules={rules}
                onClose={() => setImportOpen(false)}
            />
        </AuthenticatedLayout>
    );
}

function Row({
    service,
    selected,
    onToggle,
    onOpen,
}: {
    service: ServiceRow;
    selected: boolean;
    onToggle: (id: number) => void;
    onOpen: (id: number) => void;
}) {
    return (
        <tr
            onClick={() => onOpen(service.id)}
            className={[
                'cursor-pointer border-b border-border transition-colors last:border-0',
                selected ? 'bg-primary/5' : 'hover:bg-muted/40',
                service.status === 'hidden' ? 'opacity-60' : '',
            ].join(' ')}
        >
            <td className="px-3 py-2.5" onClick={(event) => event.stopPropagation()}>
                <input
                    type="checkbox"
                    checked={selected}
                    onChange={() => onToggle(service.id)}
                    aria-label={`Select ${service.name}`}
                    className="size-4 cursor-pointer rounded border-border accent-primary"
                />
            </td>

            <td className="max-w-0 px-3 py-2.5">
                <div className="flex items-center gap-1.5">
                    <FeaturedStar featured={service.featured} />
                    <span className="truncate font-medium" title={service.name}>
                        {service.name}
                    </span>
                </div>
                <p className="truncate text-xs text-muted-foreground">
                    {service.platform}
                    {service.category && ` · ${service.category}`}
                </p>
            </td>

            <td className="hidden px-3 py-2.5 text-xs text-muted-foreground xl:table-cell">
                <span className="block truncate">{service.panel ?? '—'}</span>
                {service.providerServiceId && (
                    <span className="font-data block truncate text-[0.7rem]">
                        #{service.providerServiceId}
                    </span>
                )}
            </td>

            <td className="font-data px-3 py-2.5 text-right text-muted-foreground tabular-nums">
                {price(service.cost)}
            </td>

            <td className="font-data px-3 py-2.5 text-right font-medium tabular-nums">
                {price(service.price)}
            </td>

            <td className="font-data hidden px-3 py-2.5 text-right tabular-nums lg:table-cell">
                {service.profit === null ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    <span className={service.underwater ? 'text-destructive' : ''}>
                        {price(service.profit)}
                    </span>
                )}
            </td>

            <td className="font-data px-3 py-2.5 text-right tabular-nums">
                <MarginCell margin={service.margin} underwater={service.underwater} />
            </td>

            <td className="font-data hidden px-3 py-2.5 text-right tabular-nums xl:table-cell">
                {compact(service.orders)}
            </td>

            <td className="px-3 py-2.5">
                <StatusBadge status={service.status} autoPaused={service.autoPaused} />
            </td>

            <td className="hidden px-3 py-2.5 text-right text-xs text-muted-foreground xl:table-cell">
                {relativeTime(service.updatedAt)}
            </td>
        </tr>
    );
}

function MobileCard({
    service,
    selected,
    onToggle,
    onOpen,
}: {
    service: ServiceRow;
    selected: boolean;
    onToggle: (id: number) => void;
    onOpen: (id: number) => void;
}) {
    return (
        <div
            onClick={() => onOpen(service.id)}
            className={[
                'cursor-pointer rounded-xl border p-3 transition-colors',
                selected ? 'border-primary/40 bg-primary/5' : 'border-border',
                service.status === 'hidden' ? 'opacity-60' : '',
            ].join(' ')}
        >
            <div className="flex items-start gap-3">
                <div onClick={(event) => event.stopPropagation()} className="pt-0.5">
                    <input
                        type="checkbox"
                        checked={selected}
                        onChange={() => onToggle(service.id)}
                        aria-label={`Select ${service.name}`}
                        className="size-4 cursor-pointer rounded border-border accent-primary"
                    />
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5">
                        <FeaturedStar featured={service.featured} />
                        <p className="truncate font-medium">{service.name}</p>
                    </div>
                    <p className="truncate text-xs text-muted-foreground">
                        {service.platform}
                        {service.category && ` · ${service.category}`}
                    </p>
                    <div className="mt-2">
                        <StatusBadge status={service.status} autoPaused={service.autoPaused} />
                    </div>
                </div>

                <div className="shrink-0 text-right">
                    <p className="font-data text-sm font-semibold tabular-nums">
                        {price(service.price)}
                    </p>
                    <p className="font-data text-xs text-muted-foreground tabular-nums">
                        cost {price(service.cost)}
                    </p>
                    <p className="font-data mt-1 text-xs tabular-nums">
                        <MarginCell margin={service.margin} underwater={service.underwater} />
                    </p>
                </div>
            </div>
        </div>
    );
}

function BulkBar({
    count,
    totalMatching,
    allSelected,
    limits,
    pending,
    onAction,
    onPricing,
    onSelectAllMatching,
    onClear,
}: {
    count: number;
    totalMatching: number;
    allSelected: boolean;
    limits: { default: number; pricing: number };
    pending: boolean;
    onAction: (action: string) => void;
    onPricing: () => void;
    onSelectAllMatching: () => void;
    onClear: () => void;
}) {
    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-0 z-40 flex justify-center px-4 pb-5">
            <div className="pointer-events-auto flex max-w-full flex-wrap items-center gap-3 rounded-xl border border-border bg-popover px-4 py-3 shadow-2xl">
                <span className="font-data text-sm font-semibold tabular-nums">
                    {count.toLocaleString('en-US')} selected
                </span>

                {!allSelected && totalMatching > count && (
                    <button
                        type="button"
                        onClick={onSelectAllMatching}
                        className="text-sm text-primary underline-offset-4 hover:underline"
                    >
                        Select all{' '}
                        {Math.min(totalMatching, limits.default).toLocaleString('en-US')} matching
                    </button>
                )}

                <div className="h-5 w-px bg-border" aria-hidden />

                <div className="flex flex-wrap items-center gap-1.5">
                    <Button size="sm" disabled={pending} onClick={onPricing}>
                        <Tags className="size-3.5" />
                        Price
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={pending}
                        onClick={() => onAction('activate')}
                    >
                        <Eye className="size-3.5" />
                        Activate
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={pending}
                        onClick={() => onAction('pause')}
                    >
                        <PauseCircle className="size-3.5" />
                        Pause
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={pending}
                        onClick={() => onAction('hide')}
                    >
                        <EyeOff className="size-3.5" />
                        Hide
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={pending}
                        onClick={() => onAction('feature')}
                    >
                        <Star className="size-3.5" />
                        Feature
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        disabled={pending}
                        onClick={() => onAction('delete')}
                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    >
                        <Trash2 className="size-3.5" />
                        Delete
                    </Button>
                </div>

                {pending && (
                    <Loader2 className="size-4 animate-spin text-muted-foreground" aria-hidden />
                )}

                <button
                    type="button"
                    onClick={onClear}
                    className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-accent"
                    aria-label="Clear selection"
                >
                    <X className="size-4" />
                </button>
            </div>
        </div>
    );
}

function SortIcon({ active, dir }: { active: boolean; dir: 'asc' | 'desc' }) {
    if (!active) {
        return <ArrowDown className="size-3 opacity-0" aria-hidden />;
    }

    return dir === 'asc' ? (
        <ArrowUp className="size-3" aria-hidden />
    ) : (
        <ArrowDown className="size-3" aria-hidden />
    );
}

function Pagination({
    meta,
    pageSizes,
    onPage,
    onPerPage,
}: {
    meta: ServicesPageProps['services']['meta'];
    pageSizes: number[];
    onPage: (page: number) => void;
    onPerPage: (perPage: number) => void;
}) {
    return (
        <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
            <p className="font-data text-xs text-muted-foreground tabular-nums">
                {meta.from ?? 0}–{meta.to ?? 0} of {meta.total.toLocaleString('en-US')}
            </p>

            <div className="flex items-center gap-2">
                <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    Rows
                    <select
                        value={meta.perPage}
                        onChange={(event) => onPerPage(Number(event.target.value))}
                        className="h-8 rounded-lg border border-border bg-background px-1.5 text-xs outline-none focus:border-ring"
                    >
                        {pageSizes.map((size) => (
                            <option key={size} value={size}>
                                {size}
                            </option>
                        ))}
                    </select>
                </label>

                <div className="flex items-center gap-1">
                    <PageButton
                        disabled={meta.currentPage <= 1}
                        onClick={() => onPage(meta.currentPage - 1)}
                        label="Previous page"
                    >
                        <ChevronLeft className="size-4" />
                    </PageButton>

                    <span className="font-data px-2 text-xs tabular-nums text-muted-foreground">
                        {meta.currentPage} / {meta.lastPage}
                    </span>

                    <PageButton
                        disabled={meta.currentPage >= meta.lastPage}
                        onClick={() => onPage(meta.currentPage + 1)}
                        label="Next page"
                    >
                        <ChevronRight className="size-4" />
                    </PageButton>
                </div>
            </div>
        </div>
    );
}

function PageButton({
    disabled,
    onClick,
    label,
    children,
}: {
    disabled: boolean;
    onClick: () => void;
    label: string;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            aria-label={label}
            className="rounded-lg border border-border p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
        >
            {children}
        </button>
    );
}

function EmptyState({
    isFiltered,
    hasPanels,
    onReset,
    onImport,
}: {
    isFiltered: boolean;
    hasPanels: boolean;
    onReset: () => void;
    onImport: () => void;
}) {
    return (
        <div className="rounded-xl border border-dashed border-border py-20 text-center">
            <span
                className="mx-auto flex size-14 items-center justify-center rounded-full bg-muted"
                aria-hidden
            >
                <Package className="size-6 text-muted-foreground" />
            </span>

            <p className="mt-4 font-semibold">
                {isFiltered ? 'No services match those filters' : 'No services yet'}
            </p>
            <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                {isFiltered
                    ? 'Try clearing the search or picking another platform.'
                    : 'Import a panel’s catalogue to give your bot something to sell.'}
            </p>

            {isFiltered ? (
                <button
                    type="button"
                    onClick={onReset}
                    className="mt-4 text-sm text-primary underline-offset-4 hover:underline"
                >
                    Clear filters
                </button>
            ) : (
                <div className="mt-4 flex justify-center gap-2">
                    <Button size="sm" onClick={onImport}>
                        Import services
                    </Button>
                    {!hasPanels && (
                        <Button size="sm" variant="outline" asChild>
                            <a href={route('onboarding')}>Connect a panel</a>
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}

function currentQuery(): Record<string, string> {
    const params = new URLSearchParams(window.location.search);
    params.delete('page');

    return Object.fromEntries(params.entries());
}
