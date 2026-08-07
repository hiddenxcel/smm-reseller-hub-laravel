import { PropsWithChildren, useEffect, useRef, useState } from 'react';

/**
 * Fades its children up as they scroll into view.
 *
 * Built on IntersectionObserver rather than a scroll listener so the browser
 * decides when to tell us, and on a CSS transition rather than an animation
 * library — the whole thing is a few lines and costs no bundle.
 *
 * Honours prefers-reduced-motion by rendering the finished state immediately.
 * Motion sickness is a real condition, and a page that ignores the setting is
 * unusable for the people who set it.
 */
export default function Reveal({
    children,
    delay = 0,
    className = '',
}: PropsWithChildren<{ delay?: number; className?: string }>) {
    const ref = useRef<HTMLDivElement>(null);
    const [shown, setShown] = useState(false);

    useEffect(() => {
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
            // Fires a little before the element arrives, so the motion has
            // finished by the time it is properly in view.
            { rootMargin: '0px 0px -80px 0px', threshold: 0.1 },
        );

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    return (
        <div
            ref={ref}
            className={[
                'transition-all duration-700 ease-out motion-reduce:transition-none',
                shown ? 'translate-y-0 opacity-100' : 'translate-y-6 opacity-0',
                className,
            ].join(' ')}
            style={shown && delay ? undefined : { transitionDelay: `${delay}ms` }}
        >
            {children}
        </div>
    );
}
