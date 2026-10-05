import { COUNTRIES, Country, flag, guessCountry, splitNumber } from '@/lib/countries';
import { ChevronDown, Search } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';

type Props = {
    /** The full international number, e.g. "+255704984690", or empty. */
    value: string;
    onChange: (value: string) => void;
    id?: string;
    name?: string;
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
    className?: string;
    /** "digits" drops the "+" — for the places that store numbers as digits only. */
    format?: 'plus' | 'digits';
    /** Called on Enter, which would otherwise submit the form the field sits in. */
    onEnter?: () => void;
};

/**
 * A phone number with a country picker in front of it.
 *
 * The person picks a country and types the rest, so nobody has to know that
 * Tanzania is 255 or write a leading zero the wrong way round. It always hands
 * back one complete international number, which is what WhatsApp wants.
 *
 * Two forgiving behaviours matter more than they look. A number pasted with its
 * own "+34 612…" switches the country to match instead of being doubled up. And
 * a leading 0 on the local part is dropped: "0704 984 690" is how a Tanzanian
 * writes their number at home, and "+255 0704…" is not a number at all.
 */
export default function PhoneInput({
    value,
    onChange,
    id,
    name,
    placeholder = '712 345 678',
    required,
    disabled,
    className = '',
    format = 'plus',
    onEnter,
}: Props) {
    const initial = splitNumber(value);
    const [country, setCountry] = useState<Country>(() => initial?.country ?? guessCountry());
    const [national, setNational] = useState(initial?.national ?? '');
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const root = useRef<HTMLDivElement>(null);
    const listId = useId();

    // Emit the combined value whenever either half changes.
    const emit = (nextCountry: Country, nextNational: string) => {
        const digits = nextNational.replace(/\D/g, '').replace(/^0+/, '');

        if (digits === '') {
            onChange('');

            return;
        }

        onChange(`${format === 'plus' ? '+' : ''}${nextCountry.dial}${digits}`);
    };

    // The parent clearing the field (after "Add") empties the typed half too.
    useEffect(() => {
        if (value === '') {
            setNational('');
        }
    }, [value]);

    useEffect(() => {
        if (! open) {
            return;
        }

        const close = (event: MouseEvent) => {
            if (! root.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    const matches = useMemo(() => {
        const needle = query.trim().toLowerCase().replace(/^\+/, '');

        if (needle === '') {
            return COUNTRIES;
        }

        return COUNTRIES.filter(
            (item) =>
                item.name.toLowerCase().includes(needle)
                || item.iso.toLowerCase() === needle
                || item.dial.startsWith(needle),
        );
    }, [query]);

    const typed = (raw: string) => {
        // A pasted international number carries its own country.
        const split = splitNumber(raw);

        if (split) {
            setCountry(split.country);
            setNational(split.national);
            emit(split.country, split.national);

            return;
        }

        const cleaned = raw.replace(/[^\d\s()-]/g, '');
        setNational(cleaned);
        emit(country, cleaned);
    };

    const choose = (next: Country) => {
        setCountry(next);
        setOpen(false);
        setQuery('');
        emit(next, national);
    };

    return (
        <div ref={root} className={`relative ${className}`}>
            <div className="flex rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-primary/30">
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => setOpen((current) => ! current)}
                    aria-haspopup="listbox"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-label={`Country: ${country.name}, +${country.dial}. Change`}
                    className="flex shrink-0 items-center gap-1.5 rounded-l-lg border-r border-input bg-muted/50 px-3 text-sm transition-colors hover:bg-muted disabled:opacity-50"
                >
                    <span className="text-base leading-none">{flag(country.iso)}</span>
                    <span className="font-data [font-variant-numeric:tabular-nums]">+{country.dial}</span>
                    <ChevronDown className="size-3.5 text-muted-foreground" aria-hidden />
                </button>

                <input
                    id={id}
                    name={name}
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel-national"
                    value={national}
                    onChange={(event) => typed(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && onEnter) {
                            event.preventDefault();
                            onEnter();
                        }
                    }}
                    placeholder={placeholder}
                    required={required}
                    disabled={disabled}
                    // 16px: below that iOS zooms the page when the field is focused.
                    className="min-w-0 flex-1 rounded-r-lg bg-transparent px-3 py-2 text-base focus:outline-none sm:text-sm"
                />
            </div>

            {open && (
                <div className="absolute z-50 mt-1.5 w-full min-w-[17rem] overflow-hidden rounded-xl border border-border bg-popover shadow-lg">
                    <div className="flex items-center gap-2 border-b border-border px-3">
                        <Search className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Escape') {
                                    setOpen(false);
                                }

                                // Enter picks the first match, so a typed "tanz" is two keys.
                                if (event.key === 'Enter') {
                                    event.preventDefault();

                                    if (matches[0]) {
                                        choose(matches[0]);
                                    }
                                }
                            }}
                            placeholder="Search country or code"
                            aria-label="Search countries"
                            className="w-full bg-transparent py-2.5 text-base focus:outline-none sm:text-sm"
                        />
                    </div>

                    <ul id={listId} role="listbox" className="max-h-64 overflow-y-auto py-1">
                        {matches.length === 0 && (
                            <li className="px-3 py-3 text-sm text-muted-foreground">No country found.</li>
                        )}

                        {matches.map((item) => (
                            <li key={`${item.iso}-${item.dial}`} role="option" aria-selected={item.iso === country.iso}>
                                <button
                                    type="button"
                                    onClick={() => choose(item)}
                                    className={[
                                        'flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm transition-colors hover:bg-muted',
                                        item.iso === country.iso ? 'bg-accent/60' : '',
                                    ].join(' ')}
                                >
                                    <span className="w-6 shrink-0 text-base leading-none">{flag(item.iso)}</span>
                                    <span className="min-w-0 flex-1 truncate">{item.name}</span>
                                    <span className="font-data shrink-0 text-muted-foreground [font-variant-numeric:tabular-nums]">
                                        +{item.dial}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
