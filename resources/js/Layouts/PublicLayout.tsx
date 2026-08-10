import AssistantWidget from '@/components/assistant/AssistantWidget';
import LandingNav from '@/components/landing/LandingNav';
import PublicFooter from '@/components/landing/PublicFooter';
import Reveal from '@/components/landing/Reveal';
import { PropsWithChildren, ReactNode } from 'react';

/**
 * The frame every public page outside the landing page sits in.
 *
 * The landing page keeps its own composition — it earns a bespoke hero — but
 * everything else gets the same nav, the same page header and the same
 * footer, so a visitor moving between them is never unsure whether they are
 * still on the same site.
 */
export default function PublicLayout({
    eyebrow,
    title,
    description,
    demoNumber,
    assistantEnabled,
    children,
}: PropsWithChildren<{
    eyebrow?: string;
    title: string;
    description?: ReactNode;
    demoNumber?: string | null;
    assistantEnabled?: boolean;
}>) {
    return (
        <div className="min-h-dvh bg-background">
            <LandingNav />

            <main>
                {/* ---- page header ---- */}
                <section className="relative overflow-hidden px-4 pt-14 pb-12 sm:pt-20 sm:pb-16">
                    <div
                        aria-hidden
                        className="pointer-events-none absolute -top-40 left-1/2 -z-10 size-[36rem] -translate-x-1/2 rounded-full bg-primary/10 blur-3xl"
                    />

                    <Reveal className="mx-auto max-w-3xl text-center">
                        {eyebrow && (
                            <p className="mb-3 text-sm font-semibold tracking-wide text-primary uppercase">
                                {eyebrow}
                            </p>
                        )}

                        <h1 className="font-heading text-4xl leading-[1.1] font-black tracking-[-0.02em] text-balance sm:text-5xl">
                            {title}
                        </h1>

                        {description && (
                            <p className="mt-5 text-lg text-pretty text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </Reveal>
                </section>

                {children}
            </main>

            <PublicFooter demoNumber={demoNumber} />

            {/* Hidden entirely without a key behind it: a chat that cannot
                answer reads as a broken product, not a missing feature. */}
            {assistantEnabled && <AssistantWidget demoNumber={demoNumber} />}
        </div>
    );
}
