import { Link } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';

export type SegmentedTab = {
    key: string;
    label: string;
    icon: LucideIcon;
    href: string;
    /** A dot on the tab: something in it needs attention. */
    attention?: boolean;
};

/**
 * Page sections as equal segments, so a phone shows them all at once and
 * nothing scrolls sideways. Each segment is a real link — a section is a URL
 * a reseller can send to support.
 */
export default function SegmentedTabs({
    tabs,
    current,
    label,
}: {
    tabs: SegmentedTab[];
    current: string;
    label: string;
}) {
    return (
        <nav
            className="grid gap-1 rounded-2xl border border-border bg-muted/50 p-1"
            style={{ gridTemplateColumns: `repeat(${tabs.length}, minmax(0, 1fr))` }}
            aria-label={label}
        >
            {tabs.map((tab) => {
                const isCurrent = tab.key === current;
                const Icon = tab.icon;

                return (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        className={[
                            'relative flex flex-col items-center gap-1 rounded-xl px-1 py-2 text-xs font-medium transition-colors sm:flex-row sm:justify-center sm:gap-2 sm:py-2.5 sm:text-sm',
                            isCurrent
                                ? 'bg-card text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        ].join(' ')}
                        aria-current={isCurrent ? 'page' : undefined}
                    >
                        <Icon className="size-4 shrink-0" />
                        <span className="truncate">{tab.label}</span>

                        {tab.attention && (
                            <span
                                className="absolute right-2 top-1.5 size-1.5 rounded-full bg-[oklch(0.77_0.16_70)]"
                                aria-label="needs attention"
                            />
                        )}
                    </Link>
                );
            })}
        </nav>
    );
}
