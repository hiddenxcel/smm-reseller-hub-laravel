import { MessageCircle, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * A way to meet the bot before signing up.
 *
 * We are selling a WhatsApp bot, so the strongest argument is the thing
 * itself answering on the visitor's own phone. Nothing on the page competes
 * with that.
 *
 * Renders nothing without a number configured — a button that opens a chat
 * nobody answers is worse than no button, because the silence reads as the
 * product being broken.
 */
export default function TryOnWhatsApp({ number }: { number?: string | null }) {
    const [dismissed, setDismissed] = useState(false);
    const [shown, setShown] = useState(false);

    // Held back briefly so it arrives after the hero rather than on top of it.
    useEffect(() => {
        const timer = window.setTimeout(() => setShown(true), 1800);

        return () => window.clearTimeout(timer);
    }, []);

    if (! number || dismissed) {
        return null;
    }

    const digits = number.replace(/\D/g, '');
    const href = `https://wa.me/${digits}?text=${encodeURIComponent('hi')}`;

    return (
        <div
            className={[
                'fixed right-4 bottom-4 z-50 flex items-end gap-2 transition-all duration-500 sm:right-6 sm:bottom-6',
                shown ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-4 opacity-0',
            ].join(' ')}
        >
            <a
                href={href}
                target="_blank"
                rel="noopener noreferrer"
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
                        On your own WhatsApp
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
