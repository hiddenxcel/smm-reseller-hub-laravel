import AppLogo from '@/components/AppLogo';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ChevronDown, Menu, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * A floating bar rather than a full-width strip.
 *
 * Six top-level links crowded the middle and gave equal weight to "FAQ" and
 * "Pricing", so the four that explain the product are grouped behind one
 * trigger. What stays on the bar is what a visitor is deciding between:
 * see it work, what it costs, and start.
 */

/**
 * Real pages now, not anchors. A link that only works from the landing page
 * is a link that breaks the moment someone follows it from anywhere else.
 *
 * Built inside the component rather than at module scope, because route() is
 * not free to call at import time: under SSR it is bound per render from the
 * route list Laravel sends, and a module-level call runs while Node is still
 * loading the bundle — before there is anything to bind. See resources/js/ssr.tsx.
 */
function links() {
    return {
        resources: [
            { href: route('api-docs'), label: 'API docs', note: 'Sell from your own site' },
            { href: route('blog'), label: 'Blog', note: 'Notes on running a shop' },
            { href: route('contact'), label: 'Contact', note: 'Talk to a person' },
        ],
        direct: [
            { href: route('features'), label: 'Features' },
            { href: route('what-we-do'), label: 'Services' },
            { href: route('pricing'), label: 'Pricing' },
        ],
    };
}

export default function LandingNav() {
    const { resources: RESOURCES, direct: DIRECT } = links();

    const [open, setOpen] = useState(false);
    const [productOpen, setProductOpen] = useState(false);
    const [scrolled, setScrolled] = useState(false);
    const productRef = useRef<HTMLDivElement>(null);

    // The bar tightens once the page moves, so it reads as floating over the
    // content rather than as part of the hero.
    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 12);

        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    // A dropdown that stays open after a click elsewhere is a dropdown people
    // fight with.
    useEffect(() => {
        if (! productOpen) {
            return;
        }

        const onDown = (event: MouseEvent) => {
            if (! productRef.current?.contains(event.target as Node)) {
                setProductOpen(false);
            }
        };

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setProductOpen(false);
            }
        };

        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [productOpen]);

    return (
        <header className="sticky top-0 z-50 px-4 pt-3 sm:pt-4">
            <nav
                className={[
                    // max-w-6xl to match every section below it. At 5xl the bar
                    // sat 64px inside the content on each side, so the logo did
                    // not line up with the headline and the buttons did not line
                    // up with the right-hand edge of anything.
                    'mx-auto flex max-w-6xl items-center justify-between gap-3 rounded-2xl border px-3 py-2.5 transition-all duration-300',
                    // Opaque enough that content scrolling underneath stays
                    // behind it. At 80% the phone demo read straight through
                    // the bar, which looked like a rendering fault.
                    scrolled
                        ? 'border-border/70 bg-background/95 shadow-lg backdrop-blur-xl'
                        : 'border-transparent bg-transparent',
                ].join(' ')}
            >
                <Link
                    href="/"
                    className="font-heading flex shrink-0 items-center gap-2 pl-1 text-lg font-extrabold"
                >
                    <AppLogo className="size-7" />
                    Resellers Hub
                </Link>

                {/* ---- desktop links ---- */}
                <div className="hidden items-center gap-0.5 md:flex">
                    {DIRECT.map((link) => (
                        <Link
                            key={link.href}
                            href={link.href}
                            className="rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {link.label}
                        </Link>
                    ))}

                    <div ref={productRef} className="relative">
                        <button
                            type="button"
                            onClick={() => setProductOpen((value) => ! value)}
                            aria-expanded={productOpen}
                            className="flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            Resources
                            <ChevronDown
                                className={[
                                    'size-4 transition-transform duration-200',
                                    productOpen ? 'rotate-180' : '',
                                ].join(' ')}
                            />
                        </button>

                        {productOpen && (
                            <div className="animate-in fade-in slide-in-from-top-1 absolute top-full right-0 mt-2 w-72 rounded-2xl border border-border bg-popover p-2 shadow-xl duration-200">
                                {RESOURCES.map((link) => (
                                    <Link
                                        key={link.href}
                                        href={link.href}
                                        onClick={() => setProductOpen(false)}
                                        className="block rounded-xl px-3 py-2.5 transition-colors hover:bg-accent"
                                    >
                                        <span className="block text-sm font-semibold">
                                            {link.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {link.note}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {/* ---- desktop actions ---- */}
                <div className="hidden shrink-0 items-center gap-1 md:flex">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={route('login')}>Log in</Link>
                    </Button>
                    <Button size="sm" className="rounded-xl" asChild>
                        <Link href={route('register')}>Start free</Link>
                    </Button>
                </div>

                {/* ---- mobile toggle ---- */}
                <button
                    type="button"
                    onClick={() => setOpen((value) => ! value)}
                    className="rounded-lg p-2 text-muted-foreground transition-colors hover:text-foreground md:hidden"
                    aria-expanded={open}
                    aria-label={open ? 'Close menu' : 'Open menu'}
                >
                    {open ? <X className="size-5" /> : <Menu className="size-5" />}
                </button>
            </nav>

            {/* ---- mobile sheet ---- */}
            {open && (
                <div className="animate-in fade-in slide-in-from-top-2 mx-auto mt-2 max-w-6xl rounded-2xl border border-border bg-background/95 p-3 shadow-xl backdrop-blur-xl duration-200 md:hidden">
                    {[...DIRECT, ...RESOURCES].map((link) => (
                        <Link
                            key={link.href}
                            href={link.href}
                            onClick={() => setOpen(false)}
                            className="block rounded-xl px-3 py-2.5 text-sm font-medium transition-colors hover:bg-accent"
                        >
                            {link.label}
                        </Link>
                    ))}

                    <div className="mt-3 flex gap-2 border-t border-border pt-3">
                        <Button variant="outline" className="flex-1 rounded-xl" asChild>
                            <Link href={route('login')}>Log in</Link>
                        </Button>
                        <Button className="flex-1 rounded-xl" asChild>
                            <Link href={route('register')}>Start free</Link>
                        </Button>
                    </div>
                </div>
            )}
        </header>
    );
}
