import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { GatewayCard } from './GatewayCard';
import { GatewayOption } from './types';

/**
 * The gateways a reseller's own customers pay through.
 *
 * Not billing — that is how the reseller pays us. Money here goes customer ->
 * reseller, through the reseller's own merchant account, which is why every
 * credential on this page is theirs and is stored encrypted.
 *
 * Ready gateways come first. The rest are still listed and still save their
 * keys, because a reseller who has an account somewhere should be able to put
 * it in before we finish wiring it — but they are labelled, since a gateway
 * that looks connected and silently never takes a payment is worse than one
 * that is honestly marked unfinished.
 */
export default function OrderBotGateways({ gateways }: { gateways: GatewayOption[] }) {
    const ready = gateways.filter((gateway) => gateway.ready);
    const pending = gateways.filter((gateway) => !gateway.ready);

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h1 className="font-heading text-xl font-bold">Gateways</h1>
                    <p className="text-sm text-muted-foreground">
                        How your customers top up their wallet. Keys are encrypted and
                        never shown again.
                    </p>
                </div>
            }
        >
            <Head title="Gateways — Order Bot" />

            <div className="space-y-6">
                <div className="space-y-4">
                    {ready.map((gateway) => (
                        <GatewayCard key={gateway.code} gateway={gateway} />
                    ))}
                </div>

                {pending.length > 0 && (
                    <div className="space-y-4 border-t border-border pt-6">
                        <div>
                            <h2 className="font-heading font-bold">Not wired up yet</h2>
                            <p className="text-sm text-muted-foreground">
                                You can save your keys now, but these cannot take a payment
                                until we finish connecting them.
                            </p>
                        </div>

                        {pending.map((gateway) => (
                            <GatewayCard key={gateway.code} gateway={gateway} />
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
