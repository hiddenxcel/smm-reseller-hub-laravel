import { Toaster } from '@/components/ui/sonner';
import { useFlashToasts } from '@/hooks/useFlashToasts';
import { useTheme } from '@/hooks/useTheme';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Bot,
    CreditCard,
    DatabaseBackup,
    FileClock,
    LayoutDashboard,
    LifeBuoy,
    LogOut,
    LucideIcon,
    Megaphone,
    Menu,
    Moon,
    Package,
    Palette,
    Plug,
    Settings,
    Shield,
    ShieldCheck,
    Sun,
    Tags,
    Ticket,
    TrendingUp,
    Users,
    Wallet,
    X,
} from 'lucide-react';
import { PropsWithChildren, ReactNode, useState } from 'react';

type NavItem = {
    label: string;
    icon: LucideIcon;
    routeName?: string;
    /**
     * For rows sharing a route — both bots live under `admin.bots/{bot}`, so
     * each row needs its parameter or they would both highlight at once.
     */
    routeParams?: string;
    /** Roles that may see the row at all. Omitted means everyone. */
    roles?: string[];
};

type NavSection = {
    /** Omitted for the lead item — Dashboard sits above the first divider. */
    label?: string;
    items: NavItem[];
};

/**
 * One flat list of sections, not the tenant sidebar's drill-in panels.
 *
 * The reseller side hides each bot behind a panel because a bot is a whole
 * product a reseller works inside for an hour at a time. An admin does the
 * opposite: glances at many areas in quick succession, mostly to answer one
 * question about one account. Sections that are all visible at once suit that;
 * a panel you have to back out of does not.
 *
 * Rows without a routeName are listed but inert. Marketplace is the honest case
 * — templates and plugins are products that do not exist yet, and a link that
 * goes nowhere is worse than a row that says so.
 */
const SECTIONS: NavSection[] = [
    {
        items: [
            { label: 'Dashboard', icon: LayoutDashboard, routeName: 'admin.dashboard' },
        ],
    },
    {
        label: 'Management',
        items: [
            { label: 'Tenants', icon: Users, routeName: 'admin.tenants.index' },
            {
                label: 'Subscriptions',
                icon: CreditCard,
                routeName: 'admin.subscriptions.index',
            },
            { label: 'Payments', icon: Wallet, routeName: 'admin.payments.index' },
            // The price list sits with what it prices, not under System: it is
            // changed for commercial reasons, not administrative ones.
            { label: 'Plans', icon: Tags, routeName: 'admin.plans.index' },
            // Two ticket queues that must never be confused. "Customer" is a
            // reseller's own WhatsApp conversations, which we only read;
            // "Help desk" is resellers writing to us, which we answer.
            {
                label: 'Customer tickets',
                icon: Ticket,
                routeName: 'admin.tickets.index',
            },
            {
                label: 'Help desk',
                icon: LifeBuoy,
                routeName: 'admin.support.index',
            },
        ],
    },
    {
        label: 'Products',
        items: [
            // Both bots share a route; the params distinguish them, so each row
            // needs its own or they would both highlight at once.
            { label: 'Order Bot', icon: Bot, routeName: 'admin.bots', routeParams: 'order' },
            {
                label: 'Support Bot',
                icon: LifeBuoy,
                routeName: 'admin.bots',
                routeParams: 'support',
            },
            { label: 'Services', icon: Package, routeName: 'admin.catalogue' },
        ],
    },
    {
        label: 'Marketplace',
        items: [
            { label: 'Templates', icon: Palette },
            { label: 'Plugins', icon: Plug },
        ],
    },
    {
        label: 'Analytics',
        items: [
            { label: 'Reports', icon: TrendingUp, routeName: 'admin.reports' },
            {
                label: 'Announcements',
                icon: Megaphone,
                routeName: 'admin.announcements.index',
            },
        ],
    },
    {
        label: 'System',
        items: [
            {
                label: 'Settings',
                icon: Settings,
                routeName: 'admin.settings',
                roles: ['owner'],
            },
            {
                label: 'Admin users',
                icon: ShieldCheck,
                routeName: 'admin.admins.index',
                roles: ['owner'],
            },
            {
                label: 'Security',
                icon: Shield,
                routeName: 'admin.security',
                roles: ['owner'],
            },
            {
                label: 'Audit logs',
                icon: FileClock,
                routeName: 'admin.audit',
                roles: ['owner', 'admin'],
            },
            {
                label: 'Backups',
                icon: DatabaseBackup,
                routeName: 'admin.backups',
                roles: ['owner'],
            },
        ],
    },
];

export default function AdminLayout({
    header,
    children,
    /** Pages that manage their own padding — wide tables need the full width. */
    bleed = false,
}: PropsWithChildren<{ header?: ReactNode; bleed?: boolean }>) {
    const [mobileOpen, setMobileOpen] = useState(false);

    useFlashToasts();

    return (
        <div className="min-h-dvh bg-background">
            <div className="lg:grid lg:grid-cols-[248px_1fr]">
                <Sidebar mobileOpen={mobileOpen} onClose={() => setMobileOpen(false)} />

                <div className="min-w-0">
                    <div className="flex items-center gap-3 border-b border-border px-4 py-3 lg:hidden">
                        <button
                            type="button"
                            onClick={() => setMobileOpen(true)}
                            className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-accent"
                            aria-label="Open menu"
                        >
                            <Menu className="size-5" />
                        </button>
                        <Shield className="size-6 text-primary" />
                        <span className="font-heading font-extrabold">Control</span>
                    </div>

                    {header && (
                        <header className="border-b border-border px-4 py-5 sm:px-8">
                            {header}
                        </header>
                    )}

                    <main className={bleed ? '' : 'px-4 py-6 sm:px-8 sm:py-8'}>
                        {children}
                    </main>
                </div>
            </div>

            <Toaster position="bottom-right" />
        </div>
    );
}

function Sidebar({
    mobileOpen,
    onClose,
}: {
    mobileOpen: boolean;
    onClose: () => void;
}) {
    const admin = usePage().props.auth.admin;

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
                    // A darker rail than the reseller sidebar, on purpose: the
                    // console and a reseller's dashboard must never be mistaken
                    // for one another at a glance.
                    'fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-border bg-slate-900 text-slate-100 transition-transform lg:sticky lg:top-0 lg:z-auto lg:h-dvh lg:w-auto lg:translate-x-0',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                ].join(' ')}
            >
                <div className="flex items-center justify-between gap-2 px-5 py-5">
                    <Link
                        href={route('admin.dashboard')}
                        className="flex min-w-0 items-center gap-2.5"
                    >
                        <Shield className="size-7 shrink-0 text-amber-400" />
                        <span className="min-w-0">
                            <span className="font-heading block truncate font-extrabold">
                                Control
                            </span>
                            <span className="block text-[11px] text-slate-400">
                                Platform admin
                            </span>
                        </span>
                    </Link>

                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-1 text-slate-400 lg:hidden"
                        aria-label="Close menu"
                    >
                        <X className="size-5" />
                    </button>
                </div>

                <nav className="scroll-slim flex-1 overflow-y-auto px-3 pb-3" aria-label="Admin">
                    {SECTIONS.map((section, index) => {
                        const visible = section.items.filter(
                            (item) => !item.roles || item.roles.includes(admin?.role ?? ''),
                        );

                        if (visible.length === 0) {
                            return null;
                        }

                        return (
                            <div
                                key={section.label ?? 'top'}
                                className={
                                    index === 0
                                        ? ''
                                        : 'mt-4 border-t border-slate-700/60 pt-4'
                                }
                            >
                                {section.label && (
                                    <h2 className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                                        {section.label}
                                    </h2>
                                )}

                                <ul className="space-y-0.5">
                                    {visible.map((item) => (
                                        <li key={item.label}>
                                            <NavRow item={item} onNavigate={onClose} />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        );
                    })}
                </nav>

                <div className="border-t border-slate-700/60 p-3">
                    <div className="px-2 py-1.5">
                        <p className="truncate text-sm font-semibold">
                            {admin?.name ?? 'Admin'}
                        </p>
                        <p className="truncate text-xs capitalize text-slate-400">
                            {admin?.role}
                        </p>
                    </div>

                    <div className="mt-1 space-y-0.5">
                        <ThemeToggle />
                        <button
                            type="button"
                            onClick={() => router.post(route('admin.logout'))}
                            className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-100"
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

function ThemeToggle() {
    const { isDark, toggle } = useTheme();

    return (
        <button
            type="button"
            onClick={toggle}
            className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-100"
        >
            {isDark ? <Sun className="size-4" /> : <Moon className="size-4" />}
            {isDark ? 'Light mode' : 'Dark mode'}
        </button>
    );
}

function NavRow({ item, onNavigate }: { item: NavItem; onNavigate: () => void }) {
    const Icon = item.icon;

    const classes =
        'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors';

    if (!item.routeName) {
        return (
            <span
                className={`${classes} cursor-not-allowed text-slate-600`}
                title="Not built yet"
            >
                <Icon className="size-4 shrink-0" />
                <span className="flex-1">{item.label}</span>
                <span className="text-[10px] uppercase tracking-wide">Soon</span>
            </span>
        );
    }

    const isCurrent = item.routeParams
        ? route().current(item.routeName, { bot: item.routeParams })
        : route().current(item.routeName);

    return (
        <Link
            href={item.routeParams ? route(item.routeName, item.routeParams) : route(item.routeName)}
            onClick={onNavigate}
            className={[
                classes,
                isCurrent
                    ? 'bg-slate-800 font-semibold text-white'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100',
            ].join(' ')}
            aria-current={isCurrent ? 'page' : undefined}
        >
            <Icon className="size-4 shrink-0" />
            {item.label}
        </Link>
    );
}
