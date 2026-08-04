import { Link, router } from '@inertiajs/react';
import { Check, SkipForward } from 'lucide-react';
import { PropsWithChildren } from 'react';

export type WizardStep = {
    key: string;
    title: string;
    description: string;
    complete: boolean;
    required: boolean;
    /** Put off for later. Not the same as done — nothing treats it as done. */
    skipped: boolean;
};

type Props = PropsWithChildren<{
    step: string;
    steps: WizardStep[];
    completed: number;
    /** False once this step is done — there is nothing left to put off. */
    canSkip?: boolean;
}>;

/**
 * The frame around every wizard step: where you are, what is left, and a way
 * back to a step you already finished.
 */
export default function OnboardingLayout({ step, steps, completed, canSkip = true, children }: Props) {
    const total = steps.length;
    const percent = Math.round((completed / total) * 100);

    return (
        <div className="min-h-dvh bg-background">
            <header className="border-b border-border/60">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                    <Link href="/" className="font-heading flex items-center gap-2 font-extrabold">
                        <span
                            className="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                            aria-hidden
                        >
                            ⚡
                        </span>
                        Resellers Hub
                    </Link>

                    <p className="text-sm text-muted-foreground">
                        {completed} of {total} done
                    </p>
                </div>

                <div className="h-1 w-full bg-muted">
                    <div
                        className="h-full bg-primary transition-all duration-500"
                        style={{ width: `${percent}%` }}
                        role="progressbar"
                        aria-valuenow={completed}
                        aria-valuemin={0}
                        aria-valuemax={total}
                        aria-label="Setup progress"
                    />
                </div>
            </header>

            <div className="mx-auto grid max-w-5xl gap-10 px-4 py-10 lg:grid-cols-[220px_1fr]">
                <nav aria-label="Setup steps">
                    <ol className="space-y-1">
                        {steps.map((item, index) => {
                            const isCurrent = item.key === step;

                            return (
                                <li key={item.key}>
                                    <StepLink
                                        item={item}
                                        index={index}
                                        isCurrent={isCurrent}
                                    />
                                </li>
                            );
                        })}
                    </ol>
                </nav>

                <main>
                    {children}

                    {canSkip && <SkipControl step={step} />}
                </main>
            </div>
        </div>
    );
}

function StepLink({
    item,
    index,
    isCurrent,
}: {
    item: WizardStep;
    index: number;
    isCurrent: boolean;
}) {
    const classes = [
        'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm transition-colors',
        isCurrent ? 'bg-accent font-semibold text-accent-foreground' : 'text-muted-foreground',
    ].join(' ');

    const marker = (
        <span
            className={[
                'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                item.complete
                    ? 'bg-primary text-primary-foreground'
                    : isCurrent
                      ? 'bg-primary/15 text-primary'
                      : 'bg-muted text-muted-foreground',
            ].join(' ')}
        >
            {item.complete ? <Check className="size-3.5" /> : item.skipped ? '\u2013' : index + 1}
        </span>
    );

    const label = (
        <span className="min-w-0">
            <span className="block truncate">{item.title}</span>
            {item.skipped && ! item.complete ? (
                <span className="block text-xs font-normal opacity-70">Skipped</span>
            ) : (
                ! item.required && (
                    <span className="block text-xs font-normal opacity-70">Optional</span>
                )
            )}
        </span>
    );

    // Finished steps are safe to jump back to; the rest depend on what comes
    // before them. Skipped ones are reachable too — without that, a reseller
    // who skipped everything would have no way back into the wizard at all.
    if ((item.complete || item.skipped) && ! isCurrent) {
        return (
            <Link href={route('onboarding.step', item.key)} className={`${classes} hover:text-foreground`}>
                {marker}
                {label}
            </Link>
        );
    }

    return (
        <span className={classes} aria-current={isCurrent ? 'step' : undefined}>
            {marker}
            {label}
        </span>
    );
}


/**
 * A way past a step the reseller cannot finish today.
 *
 * Without one, someone who signed up before getting their panel key has no
 * route forward at all, and a wizard with no way past its first screen is a
 * wizard people abandon.
 *
 * It records a decision, not progress: the step stays incomplete, the
 * dashboard keeps asking for it, and going live still refuses without it.
 * Which is why the wording promises "later" and names where later lives.
 */
function SkipControl({ step }: { step: string }) {
    const isTestStep = step === 'test';

    return (
        <div className="mt-10 border-t border-border pt-6">
            <button
                type="button"
                onClick={() => router.post(route('onboarding.skip', step))}
                className="inline-flex items-center gap-2 text-sm text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
            >
                <SkipForward className="size-4" aria-hidden />
                {isTestStep
                    ? 'I’ll test it later'
                    : 'Skip for now — I’ll come back to this'}
            </button>

            <p className="mt-2 max-w-md text-xs text-muted-foreground">
                {isTestStep
                    ? 'Your bot stays in sandbox until you have tested it, so only your own test numbers get replies.'
                    : 'Nothing is lost — your shop just is not finished yet. You can pick this up in Settings whenever you are ready.'}
            </p>
        </div>
    );
}
