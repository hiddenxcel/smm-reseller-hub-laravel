import Reveal from '@/components/landing/Reveal';
import { Section, SectionHeading } from '@/components/landing/Section';
import PublicLayout from '@/Layouts/PublicLayout';
import { Head } from '@inertiajs/react';
import Seo from '@/components/Seo';
import { AlertTriangle, Check, Copy } from 'lucide-react';
import { useState } from 'react';

type Props = {
    baseUrl: string;
    demoNumber: string | null;
    assistantEnabled: boolean;
};

/**
 * The reseller API, documented from what it actually accepts.
 *
 * Every action, parameter and error string here is read off the Action
 * classes rather than invented — documentation that drifts from the code is
 * worse than none, because it is trusted.
 */

const ACTIONS = [
    {
        action: 'services',
        summary: 'List what you can order',
        params: [],
        example: `{
  "key": "YOUR_API_KEY",
  "action": "services"
}`,
        response: `[
  {
    "service": 12,
    "name": "Instagram Followers",
    "rate": "2.00",
    "min": 100,
    "max": 100000
  }
]`,
    },
    {
        action: 'add',
        summary: 'Place an order',
        params: [
            { name: 'service', type: 'int', note: 'The service id from `services`' },
            { name: 'link', type: 'string', note: 'What the order is for' },
            { name: 'quantity', type: 'int', note: 'Within the service min and max' },
        ],
        example: `{
  "key": "YOUR_API_KEY",
  "action": "add",
  "service": 12,
  "link": "https://instagram.com/yourshop",
  "quantity": 500
}`,
        response: `{
  "order": 48220
}`,
    },
    {
        action: 'status',
        summary: 'Check one order',
        params: [{ name: 'order', type: 'int', note: 'The order id' }],
        example: `{
  "key": "YOUR_API_KEY",
  "action": "status",
  "order": 48220
}`,
        response: `{
  "charge": "1.00",
  "start_count": "1200",
  "status": "In progress",
  "remains": "120",
  "currency": "USD"
}`,
    },
    {
        action: 'orders',
        summary: 'Check several at once',
        params: [{ name: 'orders', type: 'string', note: 'Comma-separated ids' }],
        example: `{
  "key": "YOUR_API_KEY",
  "action": "orders",
  "orders": "48220,48221"
}`,
        response: `{
  "48220": { "status": "Completed", "remains": "0" },
  "48221": { "status": "In progress", "remains": "300" }
}`,
    },
    {
        action: 'refill',
        summary: 'Ask for a refill',
        params: [{ name: 'order', type: 'int', note: 'Must be inside your guarantee window' }],
        example: `{
  "key": "YOUR_API_KEY",
  "action": "refill",
  "order": 48220
}`,
        response: `{
  "refill": 991
}`,
    },
    {
        action: 'balance',
        summary: 'Read the wallet',
        params: [],
        example: `{
  "key": "YOUR_API_KEY",
  "action": "balance"
}`,
        response: `{
  "balance": "9.00",
  "currency": "USD"
}`,
    },
];

function CodeBlock({ code, label }: { code: string; label?: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        await navigator.clipboard.writeText(code);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1600);
    };

    return (
        // min-w-0 on both: a grid or flex child defaults to min-content width,
        // so without it the widest code line sets the column width and the
        // page scrolls sideways instead of the block doing so.
        <div className="relative min-w-0">
            {label && (
                <p className="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>
            )}

            <div className="relative min-w-0 overflow-hidden rounded-xl border border-border bg-[#0b141a]">
                <button
                    type="button"
                    onClick={copy}
                    className="absolute top-2 right-2 flex items-center gap-1.5 rounded-lg bg-white/10 px-2 py-1 text-xs text-white/80 transition-colors hover:bg-white/20"
                    aria-label="Copy to clipboard"
                >
                    {copied ? <Check className="size-3" /> : <Copy className="size-3" />}
                    {copied ? 'Copied' : 'Copy'}
                </button>

                <pre className="scroll-slim overflow-x-auto p-4 text-[13px] leading-relaxed text-white/90">
                    <code>{code}</code>
                </pre>
            </div>
        </div>
    );
}

export default function ApiDocs({ baseUrl, demoNumber, assistantEnabled }: Props) {
    return (
        <PublicLayout
            eyebrow="API"
            title="Sell from your own site, not just WhatsApp"
            description="One endpoint, the standard SMM API v2 — so anything already written against a panel works here unchanged."
            demoNumber={demoNumber}
            assistantEnabled={assistantEnabled}
        >
            <Seo
                title="API docs"
                description="The Resellers Hub API: one endpoint speaking the standard SMM API v2, so anything already written against a panel works unchanged. Place orders, check status and request refills from your own site."
            />

            {/* ---- getting started ---- */}
            <Section className="pt-0">
                <div className="mx-auto max-w-3xl space-y-10">
                    <Reveal>
                        <h2 className="font-heading mb-3 text-xl font-bold">The endpoint</h2>
                        <p className="mb-4 text-muted-foreground">
                            Every call is a <code className="font-data text-sm">POST</code> to
                            the same URL. What it does is decided by the{' '}
                            <code className="font-data text-sm">action</code> field in the
                            body, not by the path.
                        </p>

                        <CodeBlock code={`POST ${baseUrl}`} />
                    </Reveal>

                    <Reveal>
                        <h2 className="font-heading mb-3 text-xl font-bold">Your key</h2>
                        <p className="mb-4 text-muted-foreground">
                            Create one in your dashboard under API access. It is shown once,
                            at creation, and cannot be recovered — if you lose it, issue a
                            new one and revoke the old.
                        </p>

                        <div className="flex items-start gap-3 rounded-xl border border-destructive/25 bg-destructive/5 p-4">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-destructive" />
                            <p className="text-sm text-muted-foreground">
                                The key identifies a customer of yours, and orders placed with
                                it debit that customer's wallet. Treat it as you would a
                                password: never put it in front-end code a visitor can read.
                            </p>
                        </div>
                    </Reveal>

                    <Reveal>
                        <h2 className="font-heading mb-3 text-xl font-bold">A first call</h2>

                        <CodeBlock
                            label="curl"
                            code={`curl -X POST ${baseUrl} \\
  -H "Content-Type: application/json" \\
  -d '{
    "key": "YOUR_API_KEY",
    "action": "balance"
  }'`}
                        />
                    </Reveal>
                </div>
            </Section>

            {/* ---- actions ---- */}
            <Section muted>
                <SectionHeading
                    eyebrow="Reference"
                    title="Actions"
                    subtitle="Six of them. Each is the same POST with a different action field."
                />

                <div className="mx-auto max-w-3xl space-y-6">
                    {ACTIONS.map((item, index) => (
                        <Reveal key={item.action} delay={index * 60}>
                            <div className="rounded-2xl border border-border bg-card p-6">
                                <div className="mb-4 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <code className="font-data rounded-lg bg-primary/10 px-2.5 py-1 text-sm font-bold text-primary">
                                        {item.action}
                                    </code>
                                    <span className="text-sm text-muted-foreground">
                                        {item.summary}
                                    </span>
                                </div>

                                {item.params.length > 0 && (
                                    <div className="mb-4">
                                        <p className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Parameters
                                        </p>

                                        <ul className="space-y-1.5">
                                            {item.params.map((param) => (
                                                <li
                                                    key={param.name}
                                                    className="flex flex-wrap items-baseline gap-x-2 text-sm"
                                                >
                                                    <code className="font-data font-semibold">
                                                        {param.name}
                                                    </code>
                                                    <span className="text-xs text-muted-foreground">
                                                        {param.type}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        — {param.note}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                <div className="grid min-w-0 gap-4 lg:grid-cols-2">
                                    <CodeBlock label="Request" code={item.example} />
                                    <CodeBlock label="Response" code={item.response} />
                                </div>
                            </div>
                        </Reveal>
                    ))}
                </div>
            </Section>

            {/* ---- errors ---- */}
            <Section>
                <SectionHeading
                    eyebrow="When it fails"
                    title="Errors"
                    subtitle="Always HTTP 200 with an error field, the way every SMM API client expects."
                />

                <div className="mx-auto max-w-3xl">
                    <Reveal>
                        <CodeBlock
                            code={`{
  "error": "Invalid service"
}`}
                        />
                    </Reveal>

                    <Reveal delay={100}>
                        <p className="mt-6 text-sm text-muted-foreground">
                            A failed call is not an HTTP failure. The SMM API v2 signals
                            problems in the body rather than the status code, and every
                            client library written against a panel expects exactly that —
                            so checking the status alone will make a rejected order look
                            like a successful one.
                        </p>
                    </Reveal>
                </div>
            </Section>
        </PublicLayout>
    );
}
