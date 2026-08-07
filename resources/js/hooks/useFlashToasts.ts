import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

/**
 * Turns the server's flash messages into toasts.
 *
 * Inertia keeps the same page component mounted across visits, so a flash prop
 * that has not changed would fire again on every partial reload — every
 * keystroke in the search box, for instance. The message is compared against
 * the last one shown to keep that from happening; two identical messages in a
 * row are the rare case, and one toast for them is the right trade.
 */
export function useFlashToasts(): void {
    const { flash } = usePage().props as unknown as {
        flash?: { success?: string | null; error?: string | null };
    };

    const lastShown = useRef<string | null>(null);

    useEffect(() => {
        const message = flash?.error ?? flash?.success;

        if (!message || message === lastShown.current) {
            return;
        }

        lastShown.current = message;

        if (flash?.error) {
            toast.error(flash.error);
        } else {
            toast.success(message);
        }
    }, [flash?.success, flash?.error]);
}
