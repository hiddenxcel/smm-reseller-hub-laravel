import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Lock, Plug, Plus } from 'lucide-react';
import { useState } from 'react';
import { AddProviderForm } from './AddProviderForm';
import { ProviderCard } from './ProviderCard';
import { Card } from './bits';
import { PanelLimit, Provider } from './types';

/**
 * The panels a reseller buys from.
 *
 * What is connected comes first — that is nearly always what someone opening
 * this page wants to check. Adding another is one tap away, in the form that
 * opens in place; a reseller with none connected sees it already open, since
 * connecting one is the only thing to do here.
 */
export default function OrderBotProviders({
    providers,
    limit,
}: {
    providers: Provider[];
    limit: PanelLimit;
}) {
    const [adding, setAdding] = useState(providers.length === 0);

    return (
        <AuthenticatedLayout>
            <Head title="Providers — Order Bot" />

            <div className="mx-auto max-w-3xl space-y-4 sm:space-y-6">
                <header className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="font-heading text-xl font-extrabold tracking-tight sm:text-2xl">
                            Providers
                        </h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            The panels your bot buys from.
                        </p>
                    </div>

                    <span className="font-data shrink-0 rounded-full bg-muted px-3 py-1.5 text-sm text-muted-foreground">
                        {limit.used} of {limit.max}
                    </span>
                </header>

                {providers.length > 0 && (
                    <div className="space-y-3 sm:space-y-4">
                        {providers.map((provider) => (
                            <ProviderCard key={provider.id} provider={provider} />
                        ))}
                    </div>
                )}

                {limit.reached ? (
                    <LimitReached limit={limit} />
                ) : adding ? (
                    <AddProviderForm
                        onCancel={providers.length > 0 ? () => setAdding(false) : undefined}
                    />
                ) : (
                    <Button
                        variant="outline"
                        className="w-full sm:w-auto"
                        onClick={() => setAdding(true)}
                    >
                        <Plus className="size-4" />
                        Add a provider
                    </Button>
                )}
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
                        Your plan allows {limit.max} {limit.max === 1 ? 'panel' : 'panels'}, and{' '}
                        {limit.used} {limit.used === 1 ? 'is' : 'are'} connected. Remove one, or
                        move to a plan with room for more.
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
