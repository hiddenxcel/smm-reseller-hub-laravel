import OnboardingLayout, { WizardStep } from '@/Layouts/OnboardingLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/react';
import { AlertCircle, Loader2 } from 'lucide-react';
import { FormEventHandler } from 'react';

type Props = {
    step: string;
    steps: WizardStep[];
    completed: number;
    canSkip: boolean;
};

export default function ConnectPanel({ step, steps, completed, canSkip }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        api_url: '',
        api_key: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('onboarding.panel.store'));
    };

    return (
        <OnboardingLayout step={step} steps={steps} completed={completed} canSkip={canSkip}>
            <Head title="Connect your panel" />

            <div className="max-w-lg">
                <h1 className="font-heading text-2xl font-extrabold">Connect your panel</h1>
                <p className="mt-2 text-muted-foreground">
                    Paste your panel address and admin API key. We will work out the rest —
                    which API version it speaks, and how it wants the key sent.
                </p>

                <form onSubmit={submit} className="mt-8 space-y-5">
                    <div>
                        <Label htmlFor="name">Panel name</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            placeholder="My main panel"
                            className="mt-1.5"
                            autoFocus
                            required
                        />
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            Just for you — it is how this panel appears in your dashboard.
                        </p>
                        <FieldError message={errors.name} />
                    </div>

                    <div>
                        <Label htmlFor="api_url">Panel URL</Label>
                        <Input
                            id="api_url"
                            value={data.api_url}
                            onChange={(event) => setData('api_url', event.target.value)}
                            placeholder="yourpanel.com"
                            className="mt-1.5"
                            required
                        />
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            The address on its own is enough — no need for /api/v2.
                        </p>
                        <FieldError message={errors.api_url} />
                    </div>

                    <div>
                        <Label htmlFor="api_key">Admin API key</Label>
                        <Input
                            id="api_key"
                            type="password"
                            value={data.api_key}
                            onChange={(event) => setData('api_key', event.target.value)}
                            className="mt-1.5"
                            required
                        />
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            Find it in your panel under API settings. It is encrypted before we
                            store it.
                        </p>
                        <FieldError message={errors.api_key} />
                    </div>

                    <Button type="submit" disabled={processing} size="lg" className="w-full">
                        {processing && <Loader2 className="size-4 animate-spin" />}
                        {processing ? 'Checking the connection…' : 'Connect panel'}
                    </Button>
                </form>
            </div>
        </OnboardingLayout>
    );
}

function FieldError({ message }: { message?: string }) {
    if (! message) {
        return null;
    }

    return (
        <p className="mt-2 flex items-start gap-1.5 text-sm text-destructive">
            <AlertCircle className="mt-0.5 size-4 shrink-0" />
            <span>{message}</span>
        </p>
    );
}
