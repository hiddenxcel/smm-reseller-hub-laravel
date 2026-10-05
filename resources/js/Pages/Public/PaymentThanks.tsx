import { Section } from '@/components/landing/Section';
import PublicLayout from '@/Layouts/PublicLayout';
import { Head } from '@inertiajs/react';
import { CheckCircle2, Clock, MessageCircle } from 'lucide-react';

type Props = {
    demoNumber: string | null;
    assistantEnabled: boolean;
};

/**
 * Where a customer lands after the payment page.
 *
 * It does not say the payment worked, because it cannot know: arriving here
 * proves only that they left the gateway's page. What credits the wallet is the
 * gateway's own confirmation, which usually lands within a minute, and the bot
 * tells them in the chat when it does. So the page points them back there.
 */
export default function PaymentThanks({ demoNumber, assistantEnabled }: Props) {
    return (
        <PublicLayout
            eyebrow="Payment"
            title="Thanks — back to WhatsApp"
            description="Your payment is being confirmed. The shop's bot will message you in the chat the moment it clears."
            demoNumber={demoNumber}
            assistantEnabled={assistantEnabled}
        >
            {/* A page about one customer's payment has no business in search
                results, and nothing on it is worth indexing. */}
            <Head>
                <meta name="robots" content="noindex" />
            </Head>

            <Section className="pt-0">
                <div className="card-surface mx-auto max-w-xl rounded-2xl p-6 sm:p-8">
                    <ul className="space-y-4 text-sm">
                        <li className="flex items-start gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <MessageCircle className="size-4" />
                            </span>
                            <span>
                                <strong className="block">Go back to the WhatsApp chat</strong>
                                <span className="text-muted-foreground">
                                    You will get a message there when the top-up lands, and your order continues from
                                    where you left it.
                                </span>
                            </span>
                        </li>

                        <li className="flex items-start gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <Clock className="size-4" />
                            </span>
                            <span>
                                <strong className="block">It usually takes under a minute</strong>
                                <span className="text-muted-foreground">
                                    Some providers take a little longer. You do not need to pay again.
                                </span>
                            </span>
                        </li>

                        <li className="flex items-start gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <CheckCircle2 className="size-4" />
                            </span>
                            <span>
                                <strong className="block">Nothing arrived after a few minutes?</strong>
                                <span className="text-muted-foreground">
                                    Send the shop a message with the reference from your payment receipt and they will sort
                                    it out.
                                </span>
                            </span>
                        </li>
                    </ul>
                </div>
            </Section>
        </PublicLayout>
    );
}
