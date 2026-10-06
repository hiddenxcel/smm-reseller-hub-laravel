/**
 * The room a text has in a WhatsApp message.
 *
 * WhatsApp is strict about length: past a limit it either cuts the end off or
 * refuses the whole message. These numbers are the same ones the server holds
 * every message to (see App\Services\Bots\WhatsAppLimits), so what a reseller is
 * told here is what actually happens to their customers.
 */
export const WHATSAPP = {
    /** A plain message. We stop at 4,000 to stay clear of Meta's 4,096. */
    text: 4000,
    /** The name of a service as a customer taps it in the bot's list. */
    listTitle: 24,
    /** The short description shown on a service's card. */
    description: 500,
    /** Link help, which shares one picture caption with the greeting and an example. */
    linkHelp: 600,
} as const;

type Level = 'ok' | 'near' | 'over';

function levelOf(length: number, limit: number): Level {
    if (length > limit) {
        return 'over';
    }

    return length >= limit * 0.85 ? 'near' : 'ok';
}

const COLOUR: Record<Level, string> = {
    ok: 'text-muted-foreground',
    near: 'text-[#9a6700] dark:text-[#e3b341]',
    over: 'text-destructive',
};

/**
 * "123 / 600", turning amber near the limit and red past it, with one line
 * saying what that means for the customer.
 *
 * @param over   what happens to text past the limit
 * @param near   what to know when close to it (optional)
 */
export function CharCount({
    value,
    limit,
    over,
    near,
}: {
    value: string;
    limit: number;
    over: string;
    near?: string;
}) {
    const length = value.length;
    const level = levelOf(length, limit);

    return (
        <p className={`mt-1 flex items-start justify-between gap-3 text-xs ${COLOUR[level]}`}>
            <span aria-live="polite">
                {level === 'over' ? over : level === 'near' ? (near ?? '') : ''}
            </span>
            <span className="shrink-0 [font-variant-numeric:tabular-nums]">
                {length.toLocaleString('en-US')} / {limit.toLocaleString('en-US')}
            </span>
        </p>
    );
}

/**
 * What a name will look like in a list row once WhatsApp has its say: cut at
 * the last whole word that fits, with an ellipsis. The same rule as the server.
 */
export function fitToLimit(text: string, limit: number): string {
    const trimmed = text.trim();

    if (trimmed.length <= limit) {
        return trimmed;
    }

    const room = limit - 1;
    let head = trimmed.slice(0, room);
    const space = Math.max(head.lastIndexOf(' '), head.lastIndexOf('\n'));

    if (space >= Math.floor(room * 0.4)) {
        head = head.slice(0, space);
    }

    return `${head.replace(/[\s,.;:\-—|/]+$/, '')}…`;
}
