import AppLogo from '@/components/AppLogo';
import ImpersonationBanner from '@/components/ImpersonationBanner';
import { Toaster } from '@/components/ui/sonner';
import { useFlashToasts } from '@/hooks/useFlashToasts';
import { useTheme } from '@/hooks/useTheme';
import { Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bot,
    ChevronLeft,
    ChevronRight,
    CreditCard,
    HelpCircle,
    Inbox,
    LayoutDashboard,
    LifeBuoy,
    LogOut,
    LucideIcon,
    Menu,
    MessageSquare,
    Moon,
    Package,
    Plug,
    Settings,
    ShieldCheck,
    ShoppingBag,
    Sun,
    Ticket,
    Users,
    UsersRound,
    Wallet,
    X,
} from 'lucide-react';
import { PropsWithChildren, ReactNode, useState } from 'react';
import { Tenant } from '@/types';

type NavItem = {
    label: string;
    icon: LucideIcon;
    routeName?: string;
    /**
     * For rows that point at a tab of a page rather than a page of their own —
     * the support bot's rules and templates live under `/support-bot/{tab}`,
     * so the row needs the tab as well as the route name to link anywhere.
     */
    routeParams?: string;
};

/** A row that opens a sub-panel instead of navigating. */
type NavDrill = {
    label: string;
    icon: LucideIcon;
    hint: string;
    panel: Exclude<PanelKey, 'main'>;
};

type NavSection = {
    /** Omitted for the lead item — Dashboard sits above the first divider. */
    label?: string;
    items?: NavItem[];
    drills?: NavDrill[];
};

type PanelKey = 'main' | 'orderbot' | 'supportbot';

/**
 * Two levels, not one.
 *
 * A bot is a whole product — its own number, subscription, inbox and settings —
 * so it gets a panel of its own rather than a row among fifteen. The top level
 * stays short enough to read at a glance; everything that belongs to a bot is
 * one click in, behind a back button.
 *
 * Services, Orders and Users sit inside the order bot because that is where a
 * reseller works on them: the catalogue is what the bot sells, the orders are
 * what it took. The tables themselves carry no bot column — one `bot_orders`
 * serves both — so the support panel deliberately does not repeat them.
 *
 * Rows that exist are links; the rest are listed but inert, because a link
 * that goes nowhere is worse than no link.
 */
const MAIN: NavSection[] = [
    {
        label: 'Overview',
        items: [
            { label: 'Dashboard', icon: LayoutDashboard, routeName: 'dashboard' },
            { label: 'Analytics', icon: BarChart3 },
        ],
    },
    {
        label: 'Services',
        drills: [
            {
                label: 'Order Bot',
                icon: Bot,
                hint: 'Sells in chat',
                panel: 'orderbot',
            },
            {
                label: 'Support Bot',
                icon: LifeBuoy,
                hint: 'Answers customers',
                panel: 'supportbot',
            },
        ],
    },
    {
        label: 'Platform',
        items: [
            { label: 'Setup', icon: Settings, routeName: 'settings' },
            { label: 'Billing', icon: CreditCard, routeName: 'billing' },
            { label: 'Team', icon: UsersRound },
        ],
    },
    {
        label: 'Help',
        items: [
            { label: 'Support Center', icon: HelpCircle, routeName: 'help.support' },
        ],
    },
];

const ORDER_BOT: NavItem[] = [
    { label: 'Bot setup', icon: Settings, routeName: 'order-bot' },
    { label: 'Users', icon: Users, routeName: 'customers.index' },
    { label: 'Services', icon: Package, routeName: 'services.index' },
    { label: 'Orders', icon: ShoppingBag, routeName: 'orders.index' },
    { label: 'Inbox', icon: Inbox, routeName: 'order-bot.inbox' },
    { label: 'Providers', icon: Plug, routeName: 'order-bot.providers' },
    { label: 'Gateways', icon: Wallet, routeName: 'order-bot.gateways' },
    { label: 'Payments', icon: CreditCard },
];

const SUPPORT_BOT: NavItem[] = [
    { label: 'Overview', icon: LayoutDashboard, routeName: 'support-bot', routeParams: 'overview' },
    { label: 'Inbox', icon: Inbox, routeName: 'support-bot.inbox' },
    { label: 'Tickets', icon: Ticket, routeName: 'support-bot.tickets' },
    { label: 'Guarantee rules', icon: ShieldCheck, routeName: 'support-bot', routeParams: 'rules' },
    { label: 'Templates', icon: MessageSquare, routeName: 'support-bot', routeParams: 'templates' },
    { label: 'Settings', icon: Settings, routeName: 'support-bot', routeParams: 'settings' },
];

const PANELS: Record<Exclude<PanelKey, 'main'>, { title: string; icon: LucideIcon; items: NavItem[] }> = {
    orderbot: { title: 'Order Bot', icon: Bot, items: ORDER_BOT },
    supportbot: { title: 'Support Bot', icon: LifeBuoy, items: SUPPORT_BOT },
};

export default function AuthenticatedLayout({
    header,
    children,
    /**
     * Pages that manage their own padding — the orders table needs its header
     * to stick to the top of the viewport, which a padded wrapper prevents.
     */
    bleed = false,
}: PropsWithChildren<{ header?: ReactNode; bleed?: boolean }>) {
    const tenant = usePage().props.auth.user;

    const [mobileOpen, setMobileOpen] = useState(false);

    useFlashToasts();

    return (
        <div className="min-h-dvh bg-background">
            {/* Above the grid, not inside it: while an admin is viewing this
                account the warning has to span the sidebar too. */}
            <ImpersonationBanner />

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
    tenant,
    mobileOpen,
    onClose,
}: {
    tenant: Tenant;
    mobileOpen: boolean;
    onClose: () => void;
}) {
    // Landing on a page that lives inside a bot opens that bot's panel: the
    // sidebar should show where you are, not make you drill back in to it.
    const [panel, setPanel] = useState<PanelKey>(() => currentPanel());

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

                <nav className="scroll-slim flex-1 overflow-y-auto px-3 pb-3" aria-label="Main">
                    {panel === 'main' ? (
                        MAIN.map((section, index) => (
                            <div
                                key={section.label ?? 'top'}
                                /* The rule doubles as the gap: a heading needs
                                   room above it, the lead item does not. */
                                className={
                                    index === 0 ? '' : 'mt-4 border-t border-border pt-4'
                                }
                            >
                                {section.label && (
                                    <h2 className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/70">
                                        {section.label}
                                    </h2>
                                )}

                                <ul className="space-y-0.5">
                                    {section.items?.map((item) => (
                                        <li key={item.label}>
                                            <NavRow item={item} onNavigate={onClose} />
                                        </li>
                                    ))}

                                    {section.drills?.map((drill) => (
                                        <li key={drill.label}>
                                            <DrillRow
                                                drill={drill}
                                                onOpen={() => setPanel(drill.panel)}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))
                    ) : (
                        <SubPanel panel={panel} onBack={() => setPanel('main')} onNavigate={onClose} />
                    )}
                </nav>

                <div className="border-t border-border p-3">
                    <div className="px-2 py-1.5">
                        <p className="truncate text-sm font-semibold">{tenant.business_name}</p>
                        <p className="truncate text-xs text-muted-foreground">{tenant.email}</p>
                    </div>

                    <div className="mt-1 space-y-0.5">
                        <ThemeToggle />
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

/**
 * Which panel the current URL belongs to.
 *
 * Matching on the panel's own rows, not on a name prefix: the order bot owns
 * `customers.index` and `services.index`, which share no prefix with it, and
 * landing on one of those should still open the bot you reached it through.
 *
 * Read once on mount rather than on every render: after that the panel is the
 * reseller's own choice, and recomputing it would slam them back to `main`
 * the moment they drilled in from a page that is not inside a bot.
 */
function currentPanel(): PanelKey {
    for (const [key, { items }] of Object.entries(PANELS)) {
        const owns = items.some(
            (item) => item.routeName && route().current(item.routeName),
        );

        if (owns) {
            return key as Exclude<PanelKey, 'main'>;
        }
    }

    return 'main';
}

function DrillRow({ drill, onOpen }: { drill: NavDrill; onOpen: () => void }) {
    const Icon = drill.icon;

    return (
        <button
            type="button"
            onClick={onOpen}
            className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
            <Icon className="size-4 shrink-0" />
            <span className="min-w-0 flex-1 text-left">
                <span className="block">{drill.label}</span>
                <span className="block text-xs text-muted-foreground/70">{drill.hint}</span>
            </span>
            <ChevronRight className="size-4 shrink-0" aria-hidden />
        </button>
    );
}

/** One bot's pages, with the way back out kept at the top. */
function SubPanel({
    panel,
    onBack,
    onNavigate,
}: {
    panel: Exclude<PanelKey, 'main'>;
    onBack: () => void;
    onNavigate: () => void;
}) {
    const { title, icon: Icon, items } = PANELS[panel];

    return (
        <div>
            <button
                type="button"
                onClick={onBack}
                className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
                <ChevronLeft className="size-4 shrink-0" aria-hidden />
                Back
            </button>

            <h2 className="mt-2 flex items-center gap-2 border-t border-border px-3 pb-1.5 pt-4 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/70">
                <Icon className="size-3.5" aria-hidden />
                {title}
            </h2>

            <ul className="space-y-0.5">
                {items.map((item) => (
                    <li key={item.label}>
                        <NavRow item={item} onNavigate={onNavigate} />
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ThemeToggle() {
    const { isDark, toggle } = useTheme();

    return (
        <button
            type="button"
            onClick={toggle}
            className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
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
                className={`${classes} cursor-not-allowed text-muted-foreground/60`}
                title="Not built yet"
            >
                <Icon className="size-4 shrink-0" />
                <span className="flex-1">{item.label}</span>
                <span className="text-[10px] uppercase tracking-wide">Soon</span>
            </span>
        );
    }

    // A tab row is only current when its own tab is showing — without the
    // parameter check all four `support-bot` rows would highlight at once.
    const isCurrent = item.routeParams
        ? route().current(item.routeName, { tab: item.routeParams })
        : route().current(item.routeName);

    return (
        <Link
            href={item.routeParams ? route(item.routeName, item.routeParams) : route(item.routeName)}
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
