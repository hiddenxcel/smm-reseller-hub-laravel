import AppLogo from '@/components/AppLogo';
import { Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bot,
    LayoutDashboard,
    LifeBuoy,
    LogOut,
    LucideIcon,
    Menu,
    Package,
    Settings,
    ShoppingBag,
    Users,
    X,
} from 'lucide-react';
import { PropsWithChildren, ReactNode, useState } from 'react';
import { Tenant } from '@/types';

type NavItem = {
    label: string;
    icon: LucideIcon;
    routeName?: string;
};

/**
 * Sections that exist are links; the rest are listed but inert.
 *
 * Showing what is coming is worth the space — a reseller can see the shape of
 * the product — but a link that goes nowhere is worse than no link, so the
 * unbuilt ones are visibly disabled rather than quietly broken.
 */
const NAV: NavItem[] = [
    { label: 'Dashboard', icon: LayoutDashboard, routeName: 'dashboard' },
    { label: 'Orders', icon: ShoppingBag },
    { label: 'Customers', icon: Users },
    { label: 'Services', icon: Package },
    { label: 'Support', icon: LifeBuoy },
    { label: 'Bot settings', icon: Bot },
    { label: 'Reports', icon: BarChart3 },
    { label: 'Setup', icon: Settings, routeName: 'onboarding' },
];

export default function AuthenticatedLayout({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const tenant = usePage().props.auth.user;

    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <div className="min-h-dvh bg-background">
            <div className="lg:grid lg:grid-cols-[248px_1fr]">
                <Sidebar
                    tenant={tenant}
                    mobileOpen={mobileOpen}
                    onClose={() => setMobileOpen(false)}
                />

                <div className="min-w-0">
                    {/* Mobile bar — the sidebar collapses behind it. */}
                    <div className="flex items-center gap-3 border-b border-border px-4 py-3 lg:hidden">
                        <button
                            type="button"
                            onClick={() => setMobileOpen(true)}
                            className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-accent"
                            aria-label="Open menu"
                        >
                            <Menu className="size-5" />
                        </button>
                        <AppLogo className="size-7" />
                        <span className="font-heading font-extrabold">Resellers Hub</span>
                    </div>

                    {header && (
                        <header className="border-b border-border px-4 py-5 sm:px-8">
                            {header}
                        </header>
                    )}

                    <main className="px-4 py-6 sm:px-8 sm:py-8">{children}</main>
                </div>
            </div>
        </div>
    );
}

function Sidebar({
    tenant,
    mobileOpen,
    onClose,
}: {
    tenant: Tenant;
    mobileOpen: boolean;
    onClose: () => void;
}) {
    return (
        <>
            {mobileOpen && (
                <div
                    className="fixed inset-0 z-40 bg-foreground/20 lg:hidden"
                    onClick={onClose}
                    aria-hidden
                />
            )}

            <aside
                className={[
                    'fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-border bg-sidebar transition-transform lg:sticky lg:top-0 lg:z-auto lg:h-dvh lg:w-auto lg:translate-x-0',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                ].join(' ')}
            >
                <div className="flex items-center justify-between gap-2 px-5 py-5">
                    <Link href={route('dashboard')} className="flex min-w-0 items-center gap-2.5">
                        <AppLogo className="size-8 shrink-0" />
                        <span className="font-heading truncate font-extrabold">
                            Resellers Hub
                        </span>
                    </Link>

                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-1 text-muted-foreground lg:hidden"
                        aria-label="Close menu"
                    >
                        <X className="size-5" />
                    </button>
                </div>

                <nav className="flex-1 overflow-y-auto px-3" aria-label="Main">
                    <ul className="space-y-0.5">
                        {NAV.map((item) => (
                            <li key={item.label}>
                                <NavRow item={item} onNavigate={onClose} />
                            </li>
                        ))}
                    </ul>
                </nav>

                <div className="border-t border-border p-3">
                    <div className="px-2 py-1.5">
                        <p className="truncate text-sm font-semibold">{tenant.business_name}</p>
                        <p className="truncate text-xs text-muted-foreground">{tenant.email}</p>
                    </div>

                    <div className="mt-1 space-y-0.5">
                        <Link
                            href={route('profile.edit')}
                            className="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        >
                            <Settings className="size-4" />
                            Profile
                        </Link>
                        <button
                            type="button"
                            onClick={() => router.post(route('logout'))}
                            className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        >
                            <LogOut className="size-4" />
                            Log out
                        </button>
                    </div>
                </div>
            </aside>
        </>
    );
}

function NavRow({ item, onNavigate }: { item: NavItem; onNavigate: () => void }) {
    const Icon = item.icon;

    const classes =
        'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors';

    if (!item.routeName) {
        return (
            <span
                className={`${classes} cursor-not-allowed text-muted-foreground/60`}
                title="Not built yet"
            >
                <Icon className="size-4 shrink-0" />
                <span className="flex-1">{item.label}</span>
                <span className="text-[10px] uppercase tracking-wide">Soon</span>
            </span>
        );
    }

    const isCurrent = route().current(item.routeName);

    return (
        <Link
            href={route(item.routeName)}
            onClick={onNavigate}
            className={[
                classes,
                isCurrent
                    ? 'bg-sidebar-accent font-semibold text-sidebar-accent-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
            ].join(' ')}
            aria-current={isCurrent ? 'page' : undefined}
        >
            <Icon className="size-4 shrink-0" />
            {item.label}
        </Link>
    );
}
