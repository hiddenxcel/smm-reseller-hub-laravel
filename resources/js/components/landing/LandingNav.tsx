import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useState } from 'react';

const LINKS = [
    { href: '#services', label: 'Services' },
    { href: '#how', label: 'How it works' },
    { href: '#pricing', label: 'Pricing' },
    { href: '#faq', label: 'FAQ' },
];

export default function LandingNav() {
    const [open, setOpen] = useState(false);

    return (
        <header className="sticky top-0 z-50 border-b border-border/60 bg-background/85 backdrop-blur">
            <nav className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
                <Link href="/" className="flex items-center gap-2 font-heading text-lg font-extrabold">
                    <span
                        className="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                        aria-hidden
                    >
                        ⚡
                    </span>
                    Resellers Hub
                </Link>

                <div className="hidden items-center gap-1 md:flex">
                    {LINKS.map((link) => (
                        <a
                            key={link.href}
                            href={link.href}
                            className="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {link.label}
                        </a>
                    ))}
                </div>

                <div className="hidden items-center gap-2 md:flex">
                    <Button variant="ghost" asChild>
                        <Link href={route('login')}>Log in</Link>
                    </Button>
                    <Button asChild>
                        <Link href={route('register')}>Start free</Link>
                    </Button>
                </div>

                <button
                    type="button"
                    onClick={() => setOpen((value) => !value)}
                    className="rounded-md p-2 text-muted-foreground md:hidden"
                    aria-expanded={open}
                    aria-label={open ? 'Close menu' : 'Open menu'}
                >
                    {open ? <X className="size-5" /> : <Menu className="size-5" />}
                </button>
            </nav>

            {open && (
                <div className="border-t border-border/60 px-4 py-3 md:hidden">
                    <div className="flex flex-col gap-1">
                        {LINKS.map((link) => (
                            <a
                                key={link.href}
                                href={link.href}
                                onClick={() => setOpen(false)}
                                className="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground"
                            >
                                {link.label}
                            </a>
                        ))}
                        <div className="mt-2 flex gap-2">
                            <Button variant="outline" className="flex-1" asChild>
                                <Link href={route('login')}>Log in</Link>
                            </Button>
                            <Button className="flex-1" asChild>
                                <Link href={route('register')}>Start free</Link>
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </header>
    );
}
