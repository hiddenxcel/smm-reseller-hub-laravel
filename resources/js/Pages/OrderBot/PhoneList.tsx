import { Button } from '@/components/ui/button';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import { inputClass } from './bits';

/**
 * A list of phone numbers you add to and remove from, nothing more.
 *
 * Shared by staff numbers, test numbers, and the setup screen — all three are
 * the same shape, and all three sit inside a larger form, which is what the
 * Enter handling below is about.
 */
export function PhoneList({
    numbers,
    onChange,
    empty,
}: {
    numbers: string[];
    onChange: (numbers: string[]) => void;
    empty: string;
}) {
    const [draft, setDraft] = useState('');

    const add = () => {
        const value = draft.trim();

        // Silently ignoring a duplicate is right here — the number is already
        // in the list, which is what the reseller wanted.
        if (value === '' || numbers.includes(value)) {
            setDraft('');

            return;
        }

        onChange([...numbers, value]);
        setDraft('');
    };

    return (
        <div className="space-y-3">
            {numbers.length === 0 ? (
                <p className="text-sm text-muted-foreground">{empty}</p>
            ) : (
                <ul className="flex flex-wrap gap-2">
                    {numbers.map((number) => (
                        <li
                            key={number}
                            className="flex items-center gap-1.5 rounded-lg bg-muted px-2.5 py-1.5 text-sm"
                        >
                            <span className="font-data">{number}</span>
                            <button
                                type="button"
                                onClick={() => onChange(numbers.filter((n) => n !== number))}
                                className="text-muted-foreground transition-colors hover:text-destructive"
                                aria-label={`Remove ${number}`}
                            >
                                <X className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex gap-2">
                <input
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={(event) => {
                        // Without this the key would submit the whole form and
                        // save everything but the number being typed.
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            add();
                        }
                    }}
                    placeholder="255712345678"
                    className={inputClass}
                />
                <Button type="button" variant="outline" onClick={add}>
                    <Plus className="size-4" />
                    Add
                </Button>
            </div>
        </div>
    );
}
