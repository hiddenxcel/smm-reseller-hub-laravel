import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { FamilyCard } from './FamilyCard';
import { GatewayCard } from './GatewayCard';
import { FamilyState, GatewayOption } from './types';

/**
 * Where a card sits: the ones working for customers first, then the ones the
 * reseller has paused, then the ones not connected at all. Inside each group
 * the gateway customers are sent to leads, and the rest go by name.
 */
type Placed =
    | { kind: 'family'; family: FamilyState }
    | { kind: 'gateway'; gateway: GatewayOption };

function rank(item: Placed): number {
    if (item.kind === 'family') {
        const markets = item.family.markets;

        if (markets.some((market) => market.on)) {
            return 0;
        }

        return markets.some((market) => market.connected) ? 1 : 2;
    }

    const { gateway } = item;

    if (!gateway.connected) {
        return 2;
    }

    return gateway.status === 'active' ? 0 : 1;
}

function isLead(item: Placed): boolean {
    return item.kind === 'family'
        ? item.family.markets.some((market) => market.isDefault && market.on)
        : item.gateway.isDefault && item.gateway.status === 'active';
}

function nameOf(item: Placed): string {
    return item.kind === 'family' ? item.family.label : item.gateway.label;
}

function ordered(items: Placed[]): Placed[] {
    return [...items].sort(
        (a, b) =>
            rank(a) - rank(b) ||
            Number(isLead(b)) - Number(isLead(a)) ||
            nameOf(a).localeCompare(nameOf(b)),
    );
}

/**
 * The gateways a reseller's own customers pay through.
 *
 * Not billing — that is how the reseller pays us. Money here goes customer ->
 * reseller, through the reseller's own merchant account, which is why every
 * credential on this page is theirs and is stored encrypted.
 *
 * Ready gateways come first, and among them the connected ones lead, with the
 * one customers are sent to on top. The rest are still listed and still save their
 * keys, because a reseller who has an account somewhere should be able to put
 * it in before we finish wiring it — but they are labelled, since a gateway
 * that looks connected and silently never takes a payment is worse than one
 * that is honestly marked unfinished.
 */
export default function OrderBotGateways({
    gateways,
    families,
}: {
    gateways: GatewayOption[];
    families: FamilyState[];
}) {
    const placed = ordered([
        ...families.map((family): Placed => ({ kind: 'family', family })),
        ...gateways
            .filter((gateway) => gateway.ready)
            .map((gateway): Placed => ({ kind: 'gateway', gateway })),
    ]);
    const pending = ordered(
        gateways
            .filter((gateway) => !gateway.ready)
            .map((gateway): Placed => ({ kind: 'gateway', gateway })),
    ).flatMap((item) => (item.kind === 'gateway' ? [item.gateway] : []));
    // Folded by default: they cannot take a payment, and a long list of them
    // buries the ones that can. Open already when the reseller has keys saved
    // against one, so that is never hidden from them.
    const [showPending, setShowPending] = useState(pending.some((gateway) => gateway.connected));

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
                    {placed.map((item) =>
                        item.kind === 'family' ? (
                            <FamilyCard key={item.family.family} family={item.family} />
                        ) : (
                            <GatewayCard key={item.gateway.code} gateway={item.gateway} />
                        ),
                    )}
                </div>

                {pending.length > 0 && (
                    <div className="border-t border-border pt-6">
                        <button
                            type="button"
                            onClick={() => setShowPending((open) => !open)}
                            aria-expanded={showPending}
                            className="flex w-full items-center justify-between gap-3 rounded-xl border border-border bg-card px-5 py-4 text-left transition-colors hover:bg-accent/50"
                        >
                            <span>
                                <span className="font-heading block font-bold">
                                    Coming soon · {pending.length}
                                </span>
                                <span className="block text-sm text-muted-foreground">
                                    You can save your keys now, but these cannot take a payment
                                    until we finish connecting them.
                                </span>
                            </span>
                            <ChevronDown
                                className={`size-5 shrink-0 text-muted-foreground transition-transform ${showPending ? 'rotate-180' : ''}`}
                                aria-hidden
                            />
                        </button>

                        {showPending && (
                            <div className="mt-4 space-y-4">
                                {pending.map((gateway) => (
                                    <GatewayCard key={gateway.code} gateway={gateway} />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
