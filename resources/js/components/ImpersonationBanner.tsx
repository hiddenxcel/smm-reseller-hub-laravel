import { router, usePage } from '@inertiajs/react';
import { Eye, LogOut } from 'lucide-react';

/**
 * Shown across the top of every reseller screen while an admin is viewing the
 * account.
 *
 * Deliberately loud and un-dismissable. An admin who forgets they are inside
 * someone else's account reads that reseller's numbers as the platform's, and
 * the cost of being wrong about whose data is on screen is high enough to
 * justify a bar that cannot be closed.
 *
 * It says read-only because that is enforced server-side by
 * BlockDuringImpersonation — this is the announcement, not the mechanism.
 */
export default function ImpersonationBanner() {
    const { impersonation } = usePage().props;

    if (!impersonation) {
        return null;
    }

    return (
        <div className="sticky top-0 z-[60] flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-amber-500/40 bg-amber-500/15 px-4 py-2.5 text-sm text-amber-900 backdrop-blur dark:text-amber-100">
            <Eye className="size-4 shrink-0" aria-hidden />

            <p className="min-w-0 flex-1">
                Viewing{' '}
                <span className="font-semibold">
                    {impersonation.tenant ?? 'this reseller'}
                </span>{' '}
                as an admin.
                <span className="ml-1 font-semibold uppercase tracking-wide">
                    Read-only
                </span>
                <span className="ml-1 hidden sm:inline">
                    — changes are blocked.
                </span>
            </p>

            <button
                type="button"
                onClick={() => router.post(route('admin.impersonate.stop'))}
                className="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-amber-700"
            >
                <LogOut className="size-3.5" aria-hidden />
                Stop impersonating
            </button>
        </div>
    );
}
