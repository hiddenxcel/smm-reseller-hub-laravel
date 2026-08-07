import { MessageCircle, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * A way to meet the bot before signing up.
 *
 * We are selling a WhatsApp bot, so the strongest argument is the thing
 * itself answering. Where it answers depends on what is actually live:
 *
 *   - with a demo number configured, straight into the visitor's WhatsApp
 *   - without one, down to the interactive demo on this page
 *
 * It never points at WhatsApp on hope. A chat that stays silent does not read
 * as "the demo is not ready" — it reads as the product being broken, which is
 * the opposite of what a landing page is for.
 */
export default function TryOnWhatsApp({ number }: { number?: string | null }) {
    const [dismissed, setDismissed] = useState(false);
    const [shown, setShown] = useState(false);

    // Held back until the reader has left the first screen. On a phone the
    // hero and the phone demo fill the viewport, and a button floating over
    // them covers the very thing it is inviting people to look at.
    useEffect(() => {
        const check = () => setShown(window.scrollY > window.innerHeight * 0.6);

        check();
        window.addEventListener('scroll', check, { passive: true });

        return () => window.removeEventListener('scroll', check);
    }, []);

    if (dismissed) {
        return null;
    }

    const live = Boolean(number);
    const digits = (number ?? '').replace(/\D/g, '');
    const href = live
        ? `https://wa.me/${digits}?text=${encodeURIComponent('hi')}`
        : '#demo';

    return (
        <div
            className={[
                'fixed right-4 bottom-4 z-50 flex items-end gap-2 transition-all duration-500 sm:right-6 sm:bottom-6',
                shown ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-4 opacity-0',
            ].join(' ')}
        >
            <a
                href={href}
                {...(live ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
                className="group flex items-center gap-3 rounded-full bg-[#25D366] py-3 pr-5 pl-4 text-white shadow-lg transition-transform hover:scale-[1.03] focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
            >
                <span className="relative flex size-6 items-center justify-center">
                    {/* A quiet pulse: it is a live thing waiting, not an advert. */}
                    <span className="absolute inline-flex size-full animate-ping rounded-full bg-white/40" />
                    <MessageCircle className="relative size-6" />
                </span>

                <span className="text-left">
                    <span className="block text-sm leading-tight font-bold">
                        Try the bot now
                    </span>
                    <span className="block text-xs leading-tight text-white/85">
                        {live ? 'On your own WhatsApp' : 'Right here, no signup'}
                    </span>
                </span>
            </a>

            <button
                type="button"
                onClick={() => setDismissed(true)}
                aria-label="Hide"
                className="mb-1 flex size-6 items-center justify-center rounded-full bg-foreground/15 text-foreground/70 transition-colors hover:bg-foreground/25"
            >
                <X className="size-3.5" />
            </button>
        </div>
    );
}
