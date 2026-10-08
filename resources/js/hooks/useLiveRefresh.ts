import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/** A visit of the page's own, tagged so it can be told from the user's. */
const POLL_HEADER = 'X-Live-Poll';

/** A visit that has run this long is not holding the poll back any more. */
const STUCK_AFTER_MS = 15_000;

/**
 * Keep a page current without a refresh: ask the server again for just these
 * props every few seconds, while the tab is in front.
 *
 * Two things it is careful about. It never starts while something the person
 * did (sending a reply, opening a conversation) is still in flight, because a
 * new visit cancels the one before it and a reply must never be left
 * half-finished on the screen. And it pauses on a hidden tab, then catches up
 * the moment the tab is looked at again.
 *
 * Messages are read from the page's own data, so there is nothing to wire up
 * on the server: the same partial reload Inertia already does.
 */
export function useLiveRefresh(only: string[], intervalMs = 5000): void {
    const keys = only.join(',');
    const busySince = useRef<number | null>(null);
    const busyCount = useRef(0);
    const polling = useRef(false);

    useEffect(() => {
        const isPoll = (visit: { headers?: Record<string, string> } | undefined) =>
            visit?.headers?.[POLL_HEADER] === '1';

        const offStart = router.on('start', (event) => {
            if (!isPoll(event.detail.visit)) {
                busyCount.current += 1;
                busySince.current = Date.now();
            }
        });

        const offFinish = router.on('finish', (event) => {
            if (!isPoll(event.detail.visit)) {
                busyCount.current = Math.max(0, busyCount.current - 1);
            }
        });

        const userIsBusy = () =>
            busyCount.current > 0 &&
            busySince.current !== null &&
            Date.now() - busySince.current < STUCK_AFTER_MS;

        const poll = () => {
            if (document.hidden || polling.current || userIsBusy()) {
                return;
            }

            polling.current = true;

            router.reload({
                only: keys.split(','),
                headers: { [POLL_HEADER]: '1' },
                onFinish: () => {
                    polling.current = false;
                },
            });
        };

        const timer = window.setInterval(poll, intervalMs);

        const onVisible = () => {
            if (!document.hidden) {
                poll();
            }
        };

        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', onVisible);
            offStart();
            offFinish();
        };
    }, [keys, intervalMs]);
}
