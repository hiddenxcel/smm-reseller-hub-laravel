import OnboardingLayout, { WizardStep } from '@/Layouts/OnboardingLayout';
import WhatsAppSimulator, { SimulatorConfig } from '@/components/simulator/WhatsAppSimulator';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowDownLeft,
    ArrowUpRight,
    Check,
    Circle,
    Loader2,
    RefreshCw,
    Rocket,
    Trash2,
} from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type BotNumber = {
    id: number;
    display_number: string | null;
    phone_number_id: string;
};

type LoggedMessage = {
    id: number;
    direction: string;
    message: string | null;
    phone: string | null;
    at: string | null;
};

type Props = {
    step: string;
    steps: WizardStep[];
    completed: number;
    testNumbers: string[];
    messageReceived: boolean;
    botReplied: boolean;
    orderPlaced: boolean;
    recentMessages: LoggedMessage[];
    botNumbers: BotNumber[];
    canSkip: boolean;
    simulator: SimulatorConfig;
    simTested: boolean;
};

export default function TestBot({
    step,
    steps,
    completed,
    testNumbers,
    messageReceived,
    botReplied,
    orderPlaced,
    recentMessages,
    botNumbers,
    canSkip,
    simulator,
    simTested,
}: Props) {
    const numberForm = useForm({ phone: '' });
    // Going live sends no fields, so its rejection arrives on the page's
    // shared errors rather than on a form of its own.
    const goLiveForm = useForm({});
    const goLiveError = usePage().props.errors?.go_live;

    const addNumber: FormEventHandler = (event) => {
        event.preventDefault();
        numberForm.post(route('onboarding.test.number.store'), {
            onSuccess: () => numberForm.reset('phone'),
        });
    };

    const removeNumber = (phone: string) => {
        router.delete(route('onboarding.test.number.destroy', phone), { preserveScroll: true });
    };

    const goLive: FormEventHandler = (event) => {
        event.preventDefault();
        goLiveForm.post(route('onboarding.test.golive'));
    };

    const botNumber = botNumbers[0];

    // Either way of seeing the bot work is enough to open the shop. The
    // simulator sets simTested on the server; this just keeps the button in
    // step with it without a reload.
    const [triedHere, setTriedHere] = useState(simTested);
    const canGoLive = botReplied || triedHere;

    return (
        <OnboardingLayout step={step} steps={steps} completed={completed} canSkip={canSkip}>
            <Head title="Test your bot" />

            <div className="grid gap-10 lg:grid-cols-[1fr_22rem] lg:items-start xl:gap-14">
            <div className="order-2 max-w-xl lg:order-1">
                <h1 className="font-heading text-3xl font-extrabold">Test your bot</h1>
                <p className="mt-2 text-muted-foreground">
                    Place an order the way your customer would, using your own services and prices.
                    The chat beside this is the real bot, in practice mode &mdash; nothing is
                    charged and nothing reaches your panel.
                </p>

                <details className="group mt-8 rounded-2xl border border-border p-5 open:bg-muted/30">
                <summary className="cursor-pointer list-none font-heading font-bold marker:hidden">
                    Prefer to test on real WhatsApp? <span className="text-sm font-normal text-muted-foreground">(optional)</span>
                </summary>

                <section className="mt-6">
                    <h2 className="font-heading font-bold">1. Add the number you&rsquo;ll test from</h2>
                    <p className="mt-1.5 text-sm text-muted-foreground">
                        Your bot only answers registered testers until you go live, so a stranger
                        cannot order from a shop you are still setting up.
                    </p>

                    {testNumbers.length > 0 && (
                        <ul className="mt-4 space-y-1.5 text-sm">
                            {testNumbers.map((phone) => (
                                <li key={phone} className="flex items-center gap-2">
                                    <Check className="size-4 shrink-0 text-primary" />
                                    <span className="min-w-0 flex-1 truncate">{phone}</span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => removeNumber(phone)}
                                        aria-label={`Remove ${phone}`}
                                    >
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}

                    <form onSubmit={addNumber} className="mt-4 flex items-end gap-2">
                        <div className="flex-1">
                            <Label htmlFor="phone">Your WhatsApp number</Label>
                            <Input
                                id="phone"
                                value={numberForm.data.phone}
                                onChange={(event) => numberForm.setData('phone', event.target.value)}
                                placeholder="255700000000"
                                className="mt-1.5"
                                required
                            />
                        </div>
                        <Button type="submit" variant="outline" disabled={numberForm.processing}>
                            {numberForm.processing && <Loader2 className="size-4 animate-spin" />}
                            Add
                        </Button>
                    </form>

                    <FieldError message={numberForm.errors.phone} />
                </section>

                <section className="mt-10">
                    <h2 className="font-heading font-bold">2. Message your bot</h2>
                    {botNumber ? (
                        <p className="mt-1.5 text-sm text-muted-foreground">
                            Send <strong>hi</strong> to{' '}
                            <strong>{botNumber.display_number ?? 'your connected number'}</strong>{' '}
                            from a number above, then refresh this page.
                        </p>
                    ) : (
                        <p className="mt-1.5 text-sm text-destructive">
                            No WhatsApp number is connected yet &mdash; go back to that step first.
                        </p>
                    )}

                    <ul className="mt-4 space-y-2.5">
                        <Checkpoint done={messageReceived} label="Your message reached the bot" />
                        <Checkpoint done={botReplied} label="The bot answered" />
                        <Checkpoint done={orderPlaced} label="A test order was placed (optional)" />
                    </ul>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="mt-4"
                        onClick={() => router.reload({ only: ['messageReceived', 'botReplied', 'orderPlaced', 'recentMessages'] })}
                    >
                        <RefreshCw className="size-3.5" />
                        Check again
                    </Button>

                    {recentMessages.length > 0 && (
                        <div className="mt-6 rounded-xl border border-border p-4">
                            <p className="mb-3 text-sm font-semibold">Latest messages</p>
                            <ul className="space-y-2.5">
                                {recentMessages.map((message) => (
                                    <li key={message.id} className="flex items-start gap-2 text-sm">
                                        {message.direction === 'in' ? (
                                            <ArrowDownLeft className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                        ) : (
                                            <ArrowUpRight className="mt-0.5 size-4 shrink-0 text-primary" />
                                        )}
                                        <span className="min-w-0">
                                            <span className="block break-words">
                                                {message.message ?? <em>(no text)</em>}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {message.phone}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>

                </details>

                <section className="mt-10 border-t border-border pt-6">
                    <h2 className="font-heading font-bold">Go live</h2>
                    <p className="mt-1.5 text-sm text-muted-foreground">
                        Once your bot has answered, opening the shop means anyone who messages it
                        can order.
                    </p>

                    <form onSubmit={goLive} className="mt-4">
                        <Button
                            type="submit"
                            size="lg"
                            disabled={goLiveForm.processing || ! canGoLive}
                            className="w-full"
                        >
                            {goLiveForm.processing ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Rocket className="size-4" />
                            )}
                            Go live
                        </Button>
                    </form>

                    {! canGoLive && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Available once you have tried your bot &mdash; place a test order in the chat.
                        </p>
                    )}

                    <FieldError message={goLiveError} />
                </section>
            </div>

            <div className="order-1 lg:sticky lg:top-6 lg:order-2">
                <WhatsAppSimulator config={simulator} onUsed={() => setTriedHere(true)} />
            </div>
            </div>
        </OnboardingLayout>
    );
}

function Checkpoint({ done, label }: { done: boolean; label: string }) {
    return (
        <li className="flex items-center gap-2.5 text-sm">
            {done ? (
                <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                    <Check className="size-3" />
                </span>
            ) : (
                <Circle className="size-5 shrink-0 text-muted-foreground/40" />
            )}
            <span className={done ? 'font-medium' : 'text-muted-foreground'}>{label}</span>
        </li>
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
