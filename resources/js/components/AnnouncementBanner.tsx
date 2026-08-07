import { usePage } from '@inertiajs/react';
import { AlertTriangle, Info, X } from 'lucide-react';
import { useState } from 'react';

/**
 * Platform notices, at the top of the reseller's dashboard.
 *
 * Dismissal is remembered in localStorage rather than on the server: a notice
 * is the same text for everybody, and a table of who has read what would cost a
 * write on every page load to save nobody anything. The consequence is that
 * dismissing is per-browser, which is the right trade for a banner.
 *
 * A `critical` notice is not dismissible at the author's discretion — that flag
 * is set when writing it, and the button simply is not drawn.
 */
export default function AnnouncementBanner() {
    const { announcements } = usePage().props;

    const [dismissed, setDismissed] = useState<number[]>(() => {
        try {
            return JSON.parse(localStorage.getItem('dismissed-announcements') ?? '[]');
        } catch {
            return [];
        }
    });

    const visible = (announcements ?? []).filter(
        (announcement) => !dismissed.includes(announcement.id),
    );

    if (visible.length === 0) {
        return null;
    }

    const dismiss = (id: number) => {
        const next = [...dismissed, id];

        setDismissed(next);

        try {
            localStorage.setItem('dismissed-announcements', JSON.stringify(next));
        } catch {
            // A browser with storage disabled just gets the banner again.
        }
    };

    return (
        <div className="space-y-2">
            {visible.map((announcement) => {
                const critical = announcement.level === 'critical';
                const warning = announcement.level === 'warning';

                return (
                    <div
                        key={announcement.id}
                        className={[
                            'flex items-start gap-3 rounded-xl border px-4 py-3 text-sm',
                            critical
                                ? 'border-destructive/40 bg-destructive/10 text-destructive'
                                : warning
                                  ? 'border-amber-500/40 bg-amber-500/10 text-amber-900 dark:text-amber-100'
                                  : 'border-border bg-card',
                        ].join(' ')}
                        role={critical ? 'alert' : undefined}
                    >
                        {critical || warning ? (
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                        ) : (
                            <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
                        )}

                        <div className="min-w-0 flex-1">
                            <p className="font-semibold">{announcement.title}</p>
                            <p
                                className={
                                    critical || warning
                                        ? 'mt-0.5 whitespace-pre-line'
                                        : 'mt-0.5 whitespace-pre-line text-muted-foreground'
                                }
                            >
                                {announcement.body}
                            </p>
                        </div>

                        {announcement.dismissible && (
                            <button
                                type="button"
                                onClick={() => dismiss(announcement.id)}
                                className="shrink-0 rounded-lg p-1 opacity-70 transition-opacity hover:opacity-100"
                                aria-label="Dismiss"
                            >
                                <X className="size-4" />
                            </button>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
