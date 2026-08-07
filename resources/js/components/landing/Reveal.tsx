import { PropsWithChildren, useEffect, useRef, useState } from 'react';

/**
 * Reveals its children as they scroll into view.
 *
 * Two mechanisms, chosen at runtime:
 *
 *   - Where `animation-timeline: view()` is supported (Chromium today), the
 *     browser drives it off the scroll position. The motion tracks the wheel,
 *     so scrolling back up rewinds it, and nothing runs on the main thread.
 *
 *   - Everywhere else, an IntersectionObserver flips a class once. Same end
 *     state, no scrubbing.
 *
 * The support test runs once per mount rather than at module load: server-side
 * rendering has no CSS object, and reading it at import time would throw
 * before the page ever reached a browser.
 *
 * Honours prefers-reduced-motion in both paths — the CSS via a media query,
 * the JS by rendering the finished state immediately.
 */
export default function Reveal({
    children,
    delay = 0,
    /**
     * Position in a row, staggering the group so it arrives as a sequence.
     *
     * Takes the loop's own index and clamps it here rather than at every call
     * site: only four stagger classes exist, and a fifth card should join the
     * last wave rather than each caller remembering to cap it.
     */
    index,
    /**
     * Settle sooner, for something read as a single unit.
     *
     * A card is taken in whole rather than line by line, so it wants to be
     * still by the time it is properly on screen. The default range runs
     * longer, which on a tall card leaves the bottom rising while the top is
     * already being read.
     */
    card = false,
    className = '',
}: PropsWithChildren<{
    delay?: number;
    index?: number;
    card?: boolean;
    className?: string;
}>) {
    const step = index === undefined ? 0 : Math.min(index + 1, 4);
    const ref = useRef<HTMLDivElement>(null);
    const [scrollDriven, setScrollDriven] = useState(false);
    const [shown, setShown] = useState(false);

    useEffect(() => {
        const supported =
            typeof CSS !== 'undefined' && CSS.supports?.('animation-timeline: view()');

        if (supported) {
            setScrollDriven(true);

            return;
        }

        const node = ref.current;

        if (! node) {
            return;
        }

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            setShown(true);

            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setShown(true);
                    // One-way: re-animating on the way back up is distracting
                    // when someone is scrolling to re-read something.
                    observer.disconnect();
                }
            },
            { rootMargin: '0px 0px -80px 0px', threshold: 0.1 },
        );

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    if (scrollDriven) {
        return (
            <div
                ref={ref}
                className={[
                    'reveal-scroll',
                    step > 1 ? `reveal-scroll-${step}` : '',
                    card ? 'reveal-card' : '',
                    className,
                ]
                    .filter(Boolean)
                    .join(' ')}
            >
                {children}
            </div>
        );
    }

    return (
        <div
            ref={ref}
            className={[
                'transition-[opacity,transform] duration-500 ease-out motion-reduce:transition-none',
                shown ? 'translate-y-0 opacity-100' : 'translate-y-3 opacity-0',
                className,
            ].join(' ')}
            style={shown && delay ? undefined : { transitionDelay: `${delay}ms` }}
        >
            {children}
        </div>
    );
}
