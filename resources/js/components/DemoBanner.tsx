import { usePage } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';

/**
 * Shown across the top of every screen while signed in as the public demo.
 *
 * Un-dismissable, for a different reason than the impersonation bar above it:
 * the credentials are published, so the person reading this did not set the
 * account up and has no other way to know that the numbers are a fixture or
 * that their changes will be refused. Without it, a locked button reads as a
 * broken product rather than as a demo working correctly.
 *
 * It says read-only because that is enforced server-side by LockDemoAccount —
 * this is the announcement, not the mechanism.
 */
export default function DemoBanner() {
    const { demo } = usePage().props;

    if (!demo) {
        return null;
    }

    return (
        <div className="sticky top-0 z-[60] flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-sky-500/40 bg-sky-500/15 px-4 py-2.5 text-sm text-sky-900 backdrop-blur dark:text-sky-100">
            <FlaskConical className="size-4 shrink-0" aria-hidden />

            <p className="min-w-0 flex-1">
                <span className="font-semibold">Demo account.</span>
                <span className="ml-1 font-semibold uppercase tracking-wide">
                    Read-only
                </span>
                <span className="ml-1">
                    — have a look around; changes are switched off.
                </span>
                <span className="ml-1 hidden sm:inline">
                    Everything here is sample data, rebuilt every{' '}
                    {demo.resetMinutes} minutes.
                </span>
            </p>
        </div>
    );
}
