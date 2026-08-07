import { PropsWithChildren } from 'react';
import Reveal from './Reveal';

type SectionProps = PropsWithChildren<{
    id?: string;
    /** Tints the band so adjacent sections separate without a hard rule. */
    muted?: boolean;
    className?: string;
}>;

export function Section({ id, muted, className = '', children }: SectionProps) {
    return (
        <section
            id={id}
            className={[
                'px-4 py-16 sm:py-20',
                muted ? 'bg-muted/40' : '',
                className,
            ].join(' ')}
        >
            <div className="mx-auto max-w-6xl">{children}</div>
        </section>
    );
}

export function SectionHeading({
    eyebrow,
    title,
    subtitle,
}: {
    eyebrow?: string;
    title: string;
    subtitle?: string;
}) {
    // Every heading reveals on scroll, so no caller has to remember to wrap
    // one — and none of them can drift out of step with the rest.
    return (
        <Reveal className="mx-auto mb-12 max-w-2xl text-center">
            {eyebrow && (
                <p className="mb-3 text-sm font-semibold tracking-wide text-primary uppercase">
                    {eyebrow}
                </p>
            )}
            <h2 className="text-3xl font-extrabold tracking-tight text-balance sm:text-4xl">
                {title}
            </h2>
            {subtitle && (
                <p className="mt-4 text-lg text-pretty text-muted-foreground">{subtitle}</p>
            )}
        </Reveal>
    );
}
