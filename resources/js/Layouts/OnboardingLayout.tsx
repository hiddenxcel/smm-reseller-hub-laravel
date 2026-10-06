import AppLogo from '@/components/AppLogo';
import AssistantWidget from '@/components/assistant/AssistantWidget';
import { Button } from '@/components/ui/button';
import { Link, router, usePage } from '@inertiajs/react';
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
    const { assistantEnabled } = usePage().props;

    return (
        <div className="min-h-dvh bg-background">
            <header className="border-b border-border/60">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
                    <Link href="/" className="font-heading flex items-center gap-2 font-extrabold">
                        <AppLogo />
                        Auto Resellers Hub
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

            <div className="mx-auto grid max-w-6xl grid-cols-1 gap-10 px-4 py-10 lg:grid-cols-[220px_minmax(0,1fr)]">
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

                <main className="min-w-0">
                    {children}

                    {canSkip && (
                        <SkipControl
                            step={step}
                            required={steps.find((item) => item.key === step)?.required ?? true}
                        />
                    )}
                </main>
            </div>

            {/* Setup is where people stall, so the answers are one tap away. */}
            {assistantEnabled && <AssistantWidget inApp />}
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
function SkipControl({ step, required }: { step: string; required: boolean }) {
    const isTestStep = step === 'test';
    const isTryStep = step === 'try';

    const heading = isTryStep
      ? 'Want to jump straight to setup?'
      : isTestStep
        ? 'Not ready to test?'
        : required
          ? 'Cannot do this right now?'
          : 'This step is optional';

    const body = isTryStep
      ? 'No problem — the practice chat is always here for you to come back to.'
      : isTestStep
        ? 'Your bot stays in sandbox until you have tested it, so only your own test numbers get replies.'
        : required
          ? 'Nothing is lost — your shop just is not finished yet. You can pick this up in Settings whenever you are ready.'
          : 'Your bot works either way — you would just credit customer wallets by hand. You can set this up in Settings later.';

    return (
        <div className="mt-10 flex flex-col gap-4 rounded-xl border border-dashed border-border bg-muted/40 p-5 sm:flex-row sm:items-center sm:justify-between">
            {/* Dashed and muted on purpose: this is the secondary way out of the
                step, and it should never compete with the primary action above
                it for a reseller who can finish today. */}
            <div className="min-w-0">
                <p className="text-sm font-medium">{heading}</p>
                <p className="mt-1 max-w-md text-xs text-muted-foreground">{body}</p>
            </div>

            <Button
                type="button"
                variant="outline"
                size="sm"
                className="shrink-0 sm:self-center"
                onClick={() => router.post(route('onboarding.skip', step))}
            >
                <SkipForward className="size-4" aria-hidden />
                {isTryStep ? 'Skip the demo' : isTestStep ? 'Test it later' : 'Skip for now'}
            </Button>
        </div>
    );
}
