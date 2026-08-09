import AppLogo from '@/components/AppLogo';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ArrowRight, Globe, MessageCircle, ShieldCheck } from 'lucide-react';
import Reveal from './Reveal';

/**
 * The footer, once there are pages worth linking to.
 *
 * It carries the routes the nav cannot: the API docs someone lands on from a
 * search, the contact page they look for after reading the pricing. And it
 * closes with an invitation, because a reader who has scrolled this far has
 * read the whole argument — asking here costs nothing and catches the person
 * who was never going to scroll back up to the hero.
 */

/**
 * Built per render rather than at module scope: route() is bound per request
 * under SSR, so calling it while the bundle is still being imported finds
 * nothing there. See resources/js/ssr.tsx.
 */
function columns() {
    return [
        {
            heading: 'Product',
            links: [
                { label: 'Features', href: route('features') },
                { label: 'Services', href: route('what-we-do') },
                { label: 'Pricing', href: route('pricing') },
            ],
        },
        {
            heading: 'Resources',
            links: [
                { label: 'API docs', href: route('api-docs') },
                { label: 'Blog', href: route('blog') },
                { label: 'Contact', href: route('contact') },
            ],
        },
        {
            heading: 'Account',
            links: [
                { label: 'Log in', href: route('login') },
                { label: 'Start free', href: route('register') },
            ],
        },
    ];
}

export default function PublicFooter({
    demoNumber,
    /**
     * Off where the page already closes with one.
     *
     * The landing page ends on a filled green panel making the same offer; a
     * second ask directly beneath it reads as nagging rather than as a last
     * chance.
     */
    cta = true,
}: {
    demoNumber?: string | null;
    cta?: boolean;
}) {
    const COLUMNS = columns();

    return (
        <footer className="mt-8 border-t border-border/60 px-4 pt-16 pb-10">
            <div className="mx-auto max-w-6xl">
                {/* ---- last invitation ---- */}
                {cta && (
                <Reveal>
                    <div className="relative mb-16 overflow-hidden rounded-3xl border border-primary/20 bg-primary/5 px-6 py-10 text-center sm:px-12">
                        <div
                            aria-hidden
                            className="pointer-events-none absolute -top-24 left-1/2 size-72 -translate-x-1/2 rounded-full bg-primary/10 blur-3xl"
                        />

                        <div className="relative">
                            <h2 className="font-heading text-2xl font-extrabold text-balance sm:text-3xl">
                                Ready to put your panel on WhatsApp?
                            </h2>

                            <p className="mx-auto mt-3 max-w-md text-pretty text-muted-foreground">
                                Free to start, and everything runs in sandbox until you say
                                otherwise.
                            </p>

                            <div className="mt-7 flex flex-wrap justify-center gap-3">
                                <Button size="lg" asChild>
                                    <Link href={route('register')}>
                                        Start free
                                        <ArrowRight className="size-4" />
                                    </Link>
                                </Button>

                                {demoNumber && (
                                    <Button size="lg" variant="outline" asChild>
                                        <a
                                            href={`https://wa.me/${demoNumber.replace(/\D/g, '')}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <MessageCircle className="size-4" />
                                            Try the bot
                                        </a>
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>
                </Reveal>
                )}

                {/* ---- links ---- */}
                <div className="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.5fr_1fr_1fr_1fr]">
                    <div>
                        <Link
                            href="/"
                            className="font-heading flex items-center gap-2 text-lg font-extrabold"
                        >
                            <AppLogo className="size-7" />
                            Resellers Hub
                        </Link>

                        <p className="mt-3 max-w-xs text-sm text-pretty text-muted-foreground">
                            WhatsApp bots for SMM resellers. Your customers order, pay and
                            get support without you lifting a finger.
                        </p>

                        <p className="mt-5 flex items-center gap-2 text-xs text-muted-foreground">
                            <ShieldCheck className="size-4 shrink-0 text-primary" />
                            Built on Meta's official Cloud API
                        </p>

                        <p className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                            <Globe className="size-4 shrink-0 text-primary" />
                            Serving resellers worldwide
                        </p>
                    </div>

                    {COLUMNS.map((column) => (
                        <div key={column.heading}>
                            <p className="mb-3 text-sm font-semibold">{column.heading}</p>

                            <ul className="space-y-2.5">
                                {column.links.map((link) => (
                                    <li key={link.label}>
                                        <Link
                                            href={link.href}
                                            className="text-sm text-muted-foreground transition-colors hover:text-foreground"
                                        >
                                            {link.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>

                <div className="mt-12 flex flex-col items-center justify-between gap-3 border-t border-border/60 pt-6 text-sm text-muted-foreground sm:flex-row">
                    <p>© {new Date().getFullYear()} Resellers Hub</p>

                    <p className="text-center sm:text-right">
                        Built for SMM resellers
                    </p>
                </div>
            </div>
        </footer>
    );
}
