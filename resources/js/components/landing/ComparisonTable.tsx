import { Check, X } from 'lucide-react';

type Row = {
    feature: string;
    manual: string | false;
    hub: string;
};

/**
 * Deliberately compares against doing it by hand, not against a named
 * competitor — that is the situation nearly every reseller is actually in.
 */
const ROWS: Row[] = [
    { feature: 'Replying to customers', manual: 'You, personally', hub: 'Instant, 24/7' },
    { feature: 'Taking orders at 2am', manual: false, hub: 'Automatic' },
    { feature: 'Placing orders on your panel', manual: 'Copy and paste', hub: 'Straight through' },
    { feature: 'Collecting payment', manual: 'Chase and confirm', hub: 'Wallet, verified by the gateway' },
    { feature: 'Refill requests', manual: 'Check the rules yourself', hub: 'Guarantee rules applied for you' },
    { feature: 'Order status questions', manual: 'Look it up each time', hub: 'Answered from the panel' },
    { feature: 'Working while you sleep', manual: false, hub: 'Always on' },
    { feature: 'Number safety', manual: 'QR tools risk a ban', hub: 'Official Meta Cloud API' },
];

export default function ComparisonTable() {
    return (
        <div className="mx-auto max-w-3xl overflow-hidden rounded-2xl border border-border">
            <table className="w-full text-left text-sm">
                <caption className="sr-only">
                    Handling your shop manually compared with Resellers Hub
                </caption>

                <thead>
                    <tr className="border-b border-border bg-muted/50">
                        <th scope="col" className="px-4 py-3 font-semibold sm:px-6">
                            What happens when
                        </th>
                        <th scope="col" className="px-4 py-3 font-semibold text-muted-foreground">
                            By hand
                        </th>
                        <th scope="col" className="bg-primary/5 px-4 py-3 font-semibold text-primary">
                            Resellers Hub
                        </th>
                    </tr>
                </thead>

                <tbody>
                    {ROWS.map((row) => (
                        <tr key={row.feature} className="border-b border-border/60 last:border-0">
                            <th scope="row" className="px-4 py-3.5 font-medium text-pretty sm:px-6">
                                {row.feature}
                            </th>

                            <td className="px-4 py-3.5 text-muted-foreground">
                                <span className="flex items-start gap-2">
                                    <X className="mt-0.5 size-4 shrink-0 text-destructive/70" aria-hidden />
                                    <span>{row.manual === false ? "Doesn't happen" : row.manual}</span>
                                </span>
                            </td>

                            <td className="bg-primary/5 px-4 py-3.5">
                                <span className="flex items-start gap-2">
                                    <Check className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden />
                                    <span>{row.hub}</span>
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
