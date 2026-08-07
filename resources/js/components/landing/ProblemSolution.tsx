import { AlertTriangle, ShieldCheck } from 'lucide-react';

/**
 * The one objection worth meeting head-on, before anything else is asked.
 *
 * Every reseller who has looked at WhatsApp automation has either had a
 * number banned or knows someone who has, and that memory is what stops them
 * trying another tool. Naming the danger before naming the fix is what makes
 * the fix land — a page that only says "official API" is answering a question
 * the reader has not consciously asked yet.
 *
 * Both sides are factual: QR-scanning tools do drive bans, and the Cloud API
 * is the route Meta built for this.
 */
export default function ProblemSolution() {
    return (
        <div className="grid gap-5 md:grid-cols-2">
            <div className="rounded-2xl border border-destructive/25 bg-destructive/5 p-6">
                <p className="mb-3 flex items-center gap-2 text-sm font-bold text-destructive">
                    <AlertTriangle className="size-4 shrink-0" />
                    The problem: QR-scan bans
                </p>

                <p className="text-sm leading-relaxed text-muted-foreground">
                    Most WhatsApp bots ride on unofficial web scraping and QR-code
                    scanning. Meta detects it and bans the number — taking your
                    customer history and your sales with it, usually without warning.
                </p>
            </div>

            <div className="rounded-2xl border border-primary/25 bg-primary/5 p-6">
                <p className="mb-3 flex items-center gap-2 text-sm font-bold text-primary">
                    <ShieldCheck className="size-4 shrink-0" />
                    The fix: official Cloud API
                </p>

                <p className="text-sm leading-relaxed text-muted-foreground">
                    We connect through the API Meta built for businesses — the same
                    one large companies use. Your number stays yours, and nothing
                    about the setup puts it at risk.
                </p>
            </div>
        </div>
    );
}
