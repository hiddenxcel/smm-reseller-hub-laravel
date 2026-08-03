import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Lock, Plug } from 'lucide-react';
import { AddProviderForm } from './AddProviderForm';
import { ProviderCard } from './ProviderCard';
import { Card } from './bits';
import { PanelLimit, Provider } from './types';

/**
 * The panels a reseller buys from.
 *
 * Two columns: adding on the left, what is connected on the right. A reseller
 * either has none and is here to connect one, or has some and is here to check
 * on them — and both are in view at once.
 */
export default function OrderBotProviders({
    providers,
    limit,
}: {
    providers: Provider[];
    limit: PanelLimit;
}) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-bold">Providers</h1>
                        <p className="text-sm text-muted-foreground">
                            The panels your bot buys from.
                        </p>
                    </div>

                    <span className="font-data rounded-full bg-muted px-3 py-1.5 text-sm text-muted-foreground">
                        {limit.used} of {limit.max}
                    </span>
                </div>
            }
        >
            <Head title="Providers — Order Bot" />

            <div className="grid gap-6 lg:grid-cols-[380px_1fr]">
                <div>
                    {limit.reached ? (
                        <LimitReached limit={limit} />
                    ) : (
                        <AddProviderForm />
                    )}
                </div>

                <div className="space-y-4">
                    {providers.length === 0 ? (
                        <p className="rounded-xl border border-border bg-card p-8 text-center text-sm text-muted-foreground">
                            No panels connected yet. Add one to give your bot something to
                            sell.
                        </p>
                    ) : (
                        providers.map((provider) => (
                            <ProviderCard key={provider.id} provider={provider} />
                        ))
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

/**
 * The form is replaced rather than disabled: filling in a URL and a key, then
 * waiting on a detection call, only to be told no, wastes the one thing the
 * reseller cannot get back.
 */
function LimitReached({ limit }: { limit: PanelLimit }) {
    return (
        <Card title="Panel limit reached">
            <div className="flex items-start gap-3">
                <Lock className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
                <div className="min-w-0 space-y-3">
                    <p className="text-sm text-muted-foreground">
                        Your plan allows {limit.max}{' '}
                        {limit.max === 1 ? 'panel' : 'panels'}, and {limit.used}{' '}
                        {limit.used === 1 ? 'is' : 'are'} connected. Remove one, or move to
                        a plan with room for more.
                    </p>

                    <Link
                        href={route('onboarding')}
                        className="inline-flex items-center gap-1.5 text-sm font-medium text-primary"
                    >
                        <Plug className="size-4" aria-hidden />
                        See plans
                    </Link>
                </div>
            </div>
        </Card>
    );
}
