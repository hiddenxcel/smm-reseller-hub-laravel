import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import Reveal from './Reveal';

type Item = {
    q: string;
    a: string;
};

/**
 * Questions, collapsed until asked.
 *
 * Nine open cards made the reader scan for the one that matched their doubt;
 * the questions alone fit on a screen, so the list can be read in one pass
 * and only the relevant answer opened.
 *
 * Built on <details> rather than state so it works before hydration and reads
 * correctly to a screen reader without any aria bookkeeping of our own — the
 * element already carries the semantics.
 */
export default function Faq({ items }: { items: Item[] }) {
    // Only for the chevron; <details> handles the open/closed state itself.
    const [open, setOpen] = useState<string | null>(null);

    return (
        <div className="mx-auto max-w-3xl space-y-3">
            {items.map((item, index) => (
                <Reveal key={item.q} delay={index * 60} index={index} card>
                    <details
                        className="group rounded-2xl border border-border bg-card transition-colors duration-200 open:border-primary/40"
                        onToggle={(event) =>
                            setOpen(event.currentTarget.open ? item.q : null)
                        }
                    >
                        <summary className="flex cursor-pointer items-center justify-between gap-4 p-5 font-semibold [&::-webkit-details-marker]:hidden">
                            <span className="font-heading text-pretty">{item.q}</span>

                            <ChevronDown
                                className={[
                                    'size-5 shrink-0 text-muted-foreground transition-transform duration-200',
                                    open === item.q ? 'rotate-180 text-primary' : '',
                                ].join(' ')}
                            />
                        </summary>

                        <p className="px-5 pb-5 text-sm leading-relaxed text-pretty text-muted-foreground">
                            {item.a}
                        </p>
                    </details>
                </Reveal>
            ))}
        </div>
    );
}
