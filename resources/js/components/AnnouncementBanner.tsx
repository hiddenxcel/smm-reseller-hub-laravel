import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { usePage } from '@inertiajs/react';
import { AlertTriangle, Info, Megaphone, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Platform notices.
 *
 * A notice a reseller has not seen yet arrives as a dialog, because a strip
 * at the top of a dashboard is the first thing anyone learns to skip. Once
 * they close it, the same notice stays as a banner — so it is announced once
 * and available afterwards, rather than either shouting every visit or being
 * missed entirely.
 *
 * Both "seen" and "dismissed" live in localStorage rather than on the server:
 * a notice is the same text for everybody, and a table of who read what would
 * cost a write on every page load to save nobody anything. The consequence is
 * that it is per-browser, which is the right trade for a banner.
 *
 * A `critical` notice is never dismissible — the author sets that flag, and
 * the close button simply is not drawn.
 */

const SEEN_KEY = 'seen-announcements';
const DISMISSED_KEY = 'dismissed-announcements';

function read(key: string): number[] {
    try {
        return JSON.parse(localStorage.getItem(key) ?? '[]');
    } catch {
        return [];
    }
}

function write(key: string, ids: number[]): void {
    try {
        localStorage.setItem(key, JSON.stringify(ids));
    } catch {
        // Storage disabled: the notice simply shows again next visit.
    }
}

export default function AnnouncementBanner() {
    const { announcements } = usePage().props;
    const all = announcements ?? [];

    const [seen, setSeen] = useState<number[]>([]);
    const [dismissed, setDismissed] = useState<number[]>(() => read(DISMISSED_KEY));

    // Read after mount rather than during render: the server has no
    // localStorage, and seeding state from it during the first render would
    // make the markup disagree with what the browser then draws.
    useEffect(() => setSeen(read(SEEN_KEY)), []);

    // The newest unseen notice. One at a time — a stack of dialogs is a
    // dialog nobody reads.
    const unseen = all.filter((a) => !seen.includes(a.id) && !dismissed.includes(a.id));
    const popup = unseen[0] ?? null;

    const banners = all.filter(
        (a) => !dismissed.includes(a.id) && (popup === null || a.id !== popup.id),
    );

    const markSeen = (id: number) => {
        const next = [...read(SEEN_KEY), id];

        setSeen(next);
        write(SEEN_KEY, next);
    };

    const dismiss = (id: number) => {
        const next = [...dismissed, id];

        setDismissed(next);
        write(DISMISSED_KEY, next);
    };

    return (
        <>
            {popup && (
                <AnnouncementDialog
                    announcement={popup}
                    onClose={() => markSeen(popup.id)}
                />
            )}

            {banners.length > 0 && (
                <div className="space-y-2">
                    {banners.map((announcement) => (
                        <AnnouncementStrip
                            key={announcement.id}
                            announcement={announcement}
                            onDismiss={() => dismiss(announcement.id)}
                        />
                    ))}
                </div>
            )}
        </>
    );
}

type Announcement = {
    id: number;
    title: string;
    body: string;
    level: string;
    dismissible: boolean;
};

function AnnouncementDialog({
    announcement,
    onClose,
}: {
    announcement: Announcement;
    onClose: () => void;
}) {
    const critical = announcement.level === 'critical';
    const warning = announcement.level === 'warning';

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (! open) {
                    onClose();
                }
            }}
        >
            <DialogContent
                className="sm:max-w-lg"
                // A critical notice cannot be escaped or clicked away: it is
                // the one used when something is on fire, and closing it by
                // accident is how it gets missed.
                onEscapeKeyDown={(event) => critical && event.preventDefault()}
                onPointerDownOutside={(event) => critical && event.preventDefault()}
                showCloseButton={! critical}
            >
                <DialogHeader>
                    <span
                        className={[
                            'mb-3 flex size-11 items-center justify-center rounded-2xl',
                            critical
                                ? 'bg-destructive/10 text-destructive'
                                : warning
                                  ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                  : 'bg-primary/10 text-primary',
                        ].join(' ')}
                    >
                        {critical || warning ? (
                            <AlertTriangle className="size-5" />
                        ) : (
                            <Megaphone className="size-5" />
                        )}
                    </span>

                    <DialogTitle className="font-heading text-xl font-extrabold text-balance">
                        {announcement.title}
                    </DialogTitle>

                    <DialogDescription className="sr-only">
                        A notice from Auto Resellers Hub
                    </DialogDescription>
                </DialogHeader>

                {/* scroll-slim so a long notice stays inside the dialog rather
                    than pushing its buttons off a short screen. */}
                <div className="scroll-slim max-h-[55vh] overflow-y-auto text-sm leading-relaxed whitespace-pre-line text-muted-foreground">
                    {announcement.body}
                </div>

                <Button onClick={onClose} className="mt-2 w-full">
                    Got it
                </Button>
            </DialogContent>
        </Dialog>
    );
}

function AnnouncementStrip({
    announcement,
    onDismiss,
}: {
    announcement: Announcement;
    onDismiss: () => void;
}) {
    const critical = announcement.level === 'critical';
    const warning = announcement.level === 'warning';

    return (
        <div
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
                    onClick={onDismiss}
                    className="shrink-0 rounded-lg p-1 opacity-70 transition-opacity hover:opacity-100"
                    aria-label="Dismiss"
                >
                    <X className="size-4" />
                </button>
            )}
        </div>
    );
}
