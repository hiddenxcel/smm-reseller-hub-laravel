import PhoneFrame from '@/components/PhoneFrame';

type Line = {
    from: 'bot' | 'customer';
    text: string;
};

/**
 * A short, still WhatsApp exchange.
 *
 * Not the animated demo — that one lives further down the page and asks to be
 * played with. This is evidence sitting beside a claim, so it holds still and
 * says what the bot actually replies. A blank "interface preview" panel, which
 * is what the reference layout puts here, shows a reader nothing they could
 * not have guessed.
 */
export default function ChatPreview({
    title,
    lines,
}: {
    title: string;
    lines: Line[];
}) {
    return (
        <PhoneFrame className="max-w-[19.5rem]" footerBg="#0b141a">
            <>
                <div className="flex items-center gap-2.5 bg-[#1f2c34] px-3.5 py-2.5">
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm text-primary-foreground">
                        ●
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-semibold text-white">
                            {title}
                        </span>
                        <span className="block text-xs text-brand-100">online</span>
                    </span>
                </div>

                <div className="min-h-[19rem] space-y-2 px-3 py-4">
                    {lines.map((line, index) => (
                        <div
                            key={index}
                            className={line.from === 'bot' ? 'flex justify-start' : 'flex justify-end'}
                        >
                            <p
                                className={[
                                    'max-w-[85%] rounded-2xl px-3 py-2 text-[13px] leading-snug whitespace-pre-line',
                                    line.from === 'bot'
                                        ? 'rounded-tl-sm bg-[#1f2c34] text-white/90'
                                        : 'rounded-tr-sm bg-[#005c4b] text-white',
                                ].join(' ')}
                            >
                                {line.text}
                            </p>
                        </div>
                    ))}
                </div>
            </>
        </PhoneFrame>
    );
}
