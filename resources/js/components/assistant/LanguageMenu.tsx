import { cn } from '@/lib/utils';
import { Check, Globe } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { LANGUAGES, t } from './strings';
import type { Locale } from './useAssistant';

/**
 * Pick a language, or leave it to the assistant.
 *
 * Auto-detect is the default and usually right, but it is a guess, and a
 * visitor writing short messages in a language the guess misses has no way to
 * correct it except by explaining in words. A list of languages by their own
 * names fixes that in one tap — and is also the clearest possible answer to
 * "does this speak my language?"
 */
export default function LanguageMenu({
    choice,
    locale,
    onChange,
}: {
    /** 'auto', or the code the visitor picked. */
    choice: string;
    /** What the conversation is actually in right now. */
    locale: Locale;
    onChange: (code: string) => void;
}) {
    const copy = t(locale);
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const close = (event: MouseEvent) => {
            if (!root.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    const current = LANGUAGES.find((language) => language.code === locale);

    return (
        <div ref={root} className="relative">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-label={copy.language}
                className="flex h-8 items-center gap-1.5 rounded-lg px-2 text-xs font-semibold text-white/80 transition-colors hover:bg-white/15 hover:text-white"
            >
                <Globe className="size-4" />
                <span className="uppercase">{choice === 'auto' ? 'Auto' : (current?.code ?? locale)}</span>
            </button>

            {open && (
                <div
                    role="listbox"
                    aria-label={copy.language}
                    className="absolute end-0 top-full z-20 mt-2 max-h-[min(18rem,60dvh)] w-52 max-w-[calc(100vw-2rem)] animate-in overflow-y-auto rounded-xl border border-border bg-popover p-1 text-popover-foreground shadow-xl fade-in zoom-in-95 duration-150"
                >
                    {[{ code: 'auto', name: copy.autoDetect }, ...LANGUAGES].map((language) => {
                        const selected = choice === language.code;

                        return (
                            <button
                                key={language.code}
                                type="button"
                                role="option"
                                aria-selected={selected}
                                onClick={() => {
                                    onChange(language.code);
                                    setOpen(false);
                                }}
                                className={cn(
                                    'flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-start text-sm transition-colors hover:bg-muted',
                                    selected && 'bg-accent/60 font-semibold',
                                )}
                            >
                                {language.name}
                                {selected && <Check className="size-4 text-primary" />}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
