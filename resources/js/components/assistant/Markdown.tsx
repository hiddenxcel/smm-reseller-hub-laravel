import { router } from '@inertiajs/react';
import { Fragment, ReactNode } from 'react';

/**
 * The little bit of markdown the assistant writes: **bold**, lists, `code` and
 * links. Nothing else — no headings, tables or images — because the prompt
 * tells the model not to write them, and anything it writes anyway is simply
 * shown as text.
 *
 * Built as React nodes rather than HTML. The model's output is untrusted text
 * that happens to be well-behaved most of the time, and rendering it through
 * dangerouslySetInnerHTML would make "most of the time" the whole security
 * model. Links are the one place it could reach out, so they are limited to
 * this site and https.
 */

const INLINE = /(\*\*[^*\n]+\*\*|`[^`\n]+`|\[[^\]\n]+\]\([^)\s]+\))/g;

function safeHref(href: string): { href: string; internal: boolean } | null {
    if (href.startsWith('/') && !href.startsWith('//')) {
        return { href, internal: true };
    }

    if (/^https:\/\//i.test(href)) {
        return { href, internal: false };
    }

    return null;
}

function inline(text: string, keyBase: string): ReactNode[] {
    return text.split(INLINE).map((part, index) => {
        const key = `${keyBase}-${index}`;

        if (part.startsWith('**') && part.endsWith('**') && part.length > 4) {
            return <strong key={key}>{part.slice(2, -2)}</strong>;
        }

        if (part.startsWith('`') && part.endsWith('`') && part.length > 2) {
            return (
                <code key={key} className="rounded bg-foreground/10 px-1 py-0.5 font-data text-[0.85em]">
                    {part.slice(1, -1)}
                </code>
            );
        }

        const link = /^\[([^\]]+)\]\(([^)\s]+)\)$/.exec(part);

        if (link) {
            const target = safeHref(link[2]);

            if (target === null) {
                return <Fragment key={key}>{link[1]}</Fragment>;
            }

            return target.internal ? (
                <a
                    key={key}
                    href={target.href}
                    onClick={(event) => {
                        event.preventDefault();
                        router.visit(target.href, { preserveState: true });
                    }}
                    className="font-medium text-primary underline underline-offset-2"
                >
                    {link[1]}
                </a>
            ) : (
                <a
                    key={key}
                    href={target.href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-medium text-primary underline underline-offset-2"
                >
                    {link[1]}
                </a>
            );
        }

        return <Fragment key={key}>{part}</Fragment>;
    });
}

/** A half-arrived answer must not show a stray "**" while it is still being written. */
export function closeOpenMarkup(text: string): string {
    const stars = (text.match(/\*\*/g) ?? []).length;
    const ticks = (text.match(/`/g) ?? []).length;

    let closed = text;

    if (stars % 2 === 1) {
        closed += '**';
    }

    if (ticks % 2 === 1) {
        closed += '`';
    }

    return closed;
}

export default function Markdown({ text }: { text: string }) {
    const lines = text.replace(/\r/g, '').split('\n');

    const blocks: ReactNode[] = [];
    let paragraph: string[] = [];
    let list: { ordered: boolean; items: string[] } | null = null;

    const flushParagraph = () => {
        if (paragraph.length > 0) {
            const index = blocks.length;

            blocks.push(
                <p key={`p-${index}`}>
                    {paragraph.map((line, i) => (
                        <Fragment key={i}>
                            {i > 0 && <br />}
                            {inline(line, `p-${index}-${i}`)}
                        </Fragment>
                    ))}
                </p>,
            );
            paragraph = [];
        }
    };

    const flushList = () => {
        if (list) {
            const index = blocks.length;
            const Tag = list.ordered ? 'ol' : 'ul';

            blocks.push(
                <Tag
                    key={`l-${index}`}
                    className={list.ordered ? 'ms-5 list-decimal space-y-1' : 'ms-5 list-disc space-y-1'}
                >
                    {list.items.map((item, i) => (
                        <li key={i}>{inline(item, `l-${index}-${i}`)}</li>
                    ))}
                </Tag>,
            );
            list = null;
        }
    };

    for (const raw of lines) {
        const line = raw.trimEnd();
        const bullet = /^\s*[-*•]\s+(.*)$/.exec(line);
        const number = /^\s*\d+[.)]\s+(.*)$/.exec(line);

        if (bullet || number) {
            flushParagraph();

            const ordered = number !== null && bullet === null;

            if (list && list.ordered !== ordered) {
                flushList();
            }

            list = list ?? { ordered, items: [] };
            list.items.push((bullet ?? number)![1]);

            continue;
        }

        if (line.trim() === '') {
            flushParagraph();
            flushList();

            continue;
        }

        flushList();
        paragraph.push(line);
    }

    flushParagraph();
    flushList();

    return <div className="space-y-2 [&_a]:break-words">{blocks}</div>;
}
