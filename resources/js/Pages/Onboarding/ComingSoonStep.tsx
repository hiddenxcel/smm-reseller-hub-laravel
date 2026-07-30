import OnboardingLayout, { WizardStep } from '@/Layouts/OnboardingLayout';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import { Hammer } from 'lucide-react';

type Props = {
    step: string;
    steps: WizardStep[];
    completed: number;
    title: string;
    description: string;
};

/**
 * A step whose backend is not built yet. It says so plainly rather than
 * showing a form that quietly does nothing — a reseller who fills in a fake
 * form and finds nothing happened trusts the rest of it less.
 */
export default function ComingSoonStep({ step, steps, completed, title, description }: Props) {
    return (
        <OnboardingLayout step={step} steps={steps} completed={completed}>
            <Head title={title} />

            <div className="max-w-lg">
                <h1 className="font-heading text-2xl font-extrabold">{title}</h1>
                <p className="mt-2 text-muted-foreground">{description}</p>

                <div className="mt-8 rounded-xl border border-dashed border-border bg-muted/40 p-6">
                    <span className="mb-3 flex size-10 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                        <Hammer className="size-5" />
                    </span>
                    <h2 className="font-heading font-bold">This step is still being built</h2>
                    <p className="mt-1.5 text-sm text-muted-foreground">
                        The bot, wallet and payment engine behind it already work — this is the
                        screen that drives them, and it is next.
                    </p>
                </div>

                <Button variant="outline" className="mt-6" asChild>
                    <Link href={route('onboarding')}>Back to setup</Link>
                </Button>
            </div>
        </OnboardingLayout>
    );
}
