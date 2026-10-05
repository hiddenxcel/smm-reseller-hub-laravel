import { PropsWithChildren, useEffect, useState } from 'react';

/**
 * A modern iPhone, drawn in CSS: titanium band, Dynamic Island, side buttons,
 * a live status bar and the home indicator.
 *
 * It is a frame, not a screenshot. Everything inside the screen is real markup,
 * so the chat can scroll, take taps and wrap at any width — which a picture of
 * a phone with a rectangle cut out of it cannot do. What it borrows from the
 * real thing is the proportions: the bezel, the corner radii, and the island,
 * which together are what make a flat panel read as a device.
 */
export default function PhoneFrame({
    children,
    className = '',
    screenClassName = '',
    headerBg = '#1f2c34',
    footerBg = '#1f2c34',
}: PropsWithChildren<{
    /** Sizes the whole phone. Give it a width; the screen follows. */
    className?: string;
    /** Sizes the screen — typically a height. */
    screenClassName?: string;
    /** Colour behind the status bar, so it melts into the app's own header. */
    headerBg?: string;
    /** Colour behind the home indicator, for the same reason. */
    footerBg?: string;
}>) {
    // The time the visitor's own phone would show. Set after mount so the
    // server-rendered HTML and the first client render agree.
    const [time, setTime] = useState('9:41');

    useEffect(() => {
        const tick = () => {
            const now = new Date();
            setTime(`${now.getHours() % 12 || 12}:${String(now.getMinutes()).padStart(2, '0')}`);
        };

        tick();
        const timer = window.setInterval(tick, 20_000);

        return () => window.clearInterval(timer);
    }, []);

    return (
        <div className={`relative mx-auto ${className}`}>
            {/* ---- side buttons: action + volume on the left, power on the right ---- */}
            <span aria-hidden className="absolute top-[5.5rem] -left-[3px] h-7 w-[3px] rounded-l-sm bg-gradient-to-b from-zinc-500 to-zinc-700" />
            <span aria-hidden className="absolute top-[8.25rem] -left-[3px] h-12 w-[3px] rounded-l-sm bg-gradient-to-b from-zinc-500 to-zinc-700" />
            <span aria-hidden className="absolute top-[11.5rem] -left-[3px] h-12 w-[3px] rounded-l-sm bg-gradient-to-b from-zinc-500 to-zinc-700" />
            <span aria-hidden className="absolute top-[9rem] -right-[3px] h-[4.5rem] w-[3px] rounded-r-sm bg-gradient-to-b from-zinc-500 to-zinc-700" />

            {/* ---- the titanium band ---- */}
            <div
                className="rounded-[3.1rem] p-[3px] shadow-[0_30px_60px_-20px_rgb(0_0_0/0.45),0_12px_24px_-12px_rgb(0_0_0/0.35)]"
                style={{
                    background:
                        'linear-gradient(145deg, #8a8d93 0%, #3a3c41 22%, #1b1c1f 50%, #3a3c41 78%, #7d8086 100%)',
                }}
            >
                {/* ---- the black bezel around the glass ---- */}
                <div className="rounded-[2.95rem] bg-black p-[9px]">
                    {/* ---- the screen ---- */}
                    <div
                        className={`relative flex flex-col overflow-hidden rounded-[2.4rem] bg-[#0b141a] ${screenClassName}`}
                    >
                        {/* status bar, drawn over the app's own header colour */}
                        <div
                            className="relative z-20 flex h-[3.1rem] shrink-0 items-end justify-between px-7 pb-1.5 text-[13px] font-semibold text-white"
                            style={{ background: headerBg }}
                        >
                            <span className="tabular-nums">{time}</span>

                            <span className="flex items-center gap-1.5" aria-hidden>
                                {/* signal */}
                                <svg width="17" height="11" viewBox="0 0 17 11" fill="currentColor">
                                    <rect x="0" y="7" width="3" height="4" rx="0.8" />
                                    <rect x="4.6" y="5" width="3" height="6" rx="0.8" />
                                    <rect x="9.2" y="2.5" width="3" height="8.5" rx="0.8" />
                                    <rect x="13.8" y="0" width="3" height="11" rx="0.8" />
                                </svg>
                                {/* wifi */}
                                <svg width="16" height="11" viewBox="0 0 16 11" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round">
                                    <path d="M1.2 3.9a9.6 9.6 0 0 1 13.6 0" />
                                    <path d="M3.7 6.4a6.1 6.1 0 0 1 8.6 0" />
                                    <circle cx="8" cy="9" r="1" fill="currentColor" stroke="none" />
                                </svg>
                                {/* battery */}
                                <svg width="26" height="12" viewBox="0 0 26 12" fill="none">
                                    <rect x="0.6" y="0.6" width="21.8" height="10.8" rx="3.2" stroke="currentColor" strokeOpacity="0.45" strokeWidth="1" />
                                    <rect x="2" y="2" width="17" height="8" rx="2" fill="currentColor" />
                                    <rect x="23.4" y="3.8" width="1.6" height="4.4" rx="0.8" fill="currentColor" fillOpacity="0.5" />
                                </svg>
                            </span>
                        </div>

                        {/* Dynamic Island */}
                        <div
                            aria-hidden
                            className="absolute top-[0.7rem] left-1/2 z-30 flex h-[1.65rem] w-[5.6rem] -translate-x-1/2 items-center justify-end rounded-full bg-black pr-2.5"
                        >
                            <span className="size-2.5 rounded-full bg-[radial-gradient(circle_at_35%_35%,#2c3350,#0a0c14_70%)] ring-1 ring-white/5" />
                        </div>

                        {children}

                        {/* home indicator */}
                        <div
                            aria-hidden
                            className="relative z-20 flex h-5 shrink-0 items-center justify-center"
                            style={{ background: footerBg }}
                        >
                            <span className="h-[5px] w-[8.2rem] rounded-full bg-white/80" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
