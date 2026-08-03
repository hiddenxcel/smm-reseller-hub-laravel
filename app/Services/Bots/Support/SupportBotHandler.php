<?php

namespace App\Services\Bots\Support;

use App\Models\BotConversation;
use App\Models\BotOrder;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Bots\BotHandler;
use App\Services\Bots\BotMessenger;
use App\Services\Bots\BotSettings;
use App\Services\Guarantee\GuaranteeMatcher;
use App\Services\Panel\SmmProviderClient;
use Illuminate\Support\Arr;

/**
 * After-sales support: a numbered Quick Menu over WhatsApp/Telegram.
 *
 * The customer sends anything and gets the menu, replies with a number, and
 * most options then ask for an order ID and act on the reseller's panel.
 * Refill is gated by the reseller's guarantee rules; "talk to a human" and
 * the top-up issue path just notify staff.
 *
 * At any point: 0/menu/back returns to the menu, cancel exits.
 */
class SupportBotHandler implements BotHandler
{
    private const BOT = 'support';

    private const EXIT_WORDS = ['cancel', 'exit', 'toka'];

    private const MENU_WORDS = ['0', 'menu', 'back', 'hi', 'hello', 'help', 'habari', 'start'];

    private int $tenantId;

    public function __construct(
        private Tenant $tenant,
        private BotMessenger $messenger,
    ) {
        $this->tenantId = (int) $tenant->id;
    }

    public function handle(string $from, string $text): void
    {
        $text = trim($text);
        $lower = mb_strtolower($text);

        // A person is answering this customer. Record what they said so it
        // reaches the inbox, and stay out of the way — the whole point of the
        // handoff is that the customer is talking to one voice, not two.
        $handoff = Ticket::handoffFor($this->tenantId, $from);

        if ($handoff !== null) {
            TicketMessage::create([
                'ticket_id' => $handoff->id,
                'sender' => 'customer',
                'message' => $text,
            ]);

            $handoff->touchCustomerMessage();

            return;
        }

        if (in_array($lower, self::EXIT_WORDS, true)) {
            $this->finish($from);
            $this->messenger->sendText($from, '👋 Support closed. Send any message to open the menu again.');

            return;
        }

        $conversation = BotConversation::current($this->tenantId, $from, self::BOT);
        $state = $conversation === null ? null : SupportState::tryFrom($conversation->state);

        if ($state === null || in_array($lower, self::MENU_WORDS, true)) {
            $this->showMenu($from);

            return;
        }

        match ($state) {
            SupportState::Menu => $this->onMenuChoice($from, $text),
            SupportState::AwaitOrderId => $this->onOrderId($from, $text, $conversation->context ?? []),

            // AI FAQ arrives with the DeepSeek port; until then the menu is
            // better than silence.
            SupportState::AiFaq => $this->showMenu($from),
        };
    }

    private function showMenu(string $from): void
    {
        $this->moveTo($from, SupportState::Menu);

        $options = implode("\n", array_map(
            fn (SupportAction $action) => $action->label(),
            SupportAction::cases(),
        ));

        $this->messenger->sendText($from, implode('', [
            "📋 *Quick Menu (AI)*\n\n",
            "Welcome to {$this->tenant->business_name} — AI & Human Support\n",
            "We solve your problems in seconds with our AI-powered support bot. 🤖\n\n",
            "👇 Please choose an option:\n\n",
            $options,
            "\n\nReply with *0* for Main Menu, *back* to go back, or *cancel* to exit.",
        ]), 'SUPPORT_MENU');
    }

    private function onMenuChoice(string $from, string $text): void
    {
        $action = SupportAction::tryFrom(preg_replace('/\D/', '', $text) ?? '');

        if ($action === null) {
            $this->messenger->sendText($from, 'Please reply with a number from *1* to *8* (or *0* for the menu).');

            return;
        }

        if (! $this->isEnabled($action)) {
            $this->messenger->sendText($from, "That option isn't available. Reply *0* for the menu.");

            return;
        }

        if (! $action->needsOrderId()) {
            $this->handleImmediate($from, $action);

            return;
        }

        $this->moveTo($from, SupportState::AwaitOrderId, ['action' => $action->value]);

        $this->messenger->sendText(
            $from,
            "🔢 Please send the *Order ID* for *{$action->label()}*.\n(Reply *back* for the menu.)",
        );
    }

    private function handleImmediate(string $from, SupportAction $action): void
    {
        match ($action) {
            SupportAction::Human => $this->connectToHuman($from),
            SupportAction::TopupIssue => $this->explainTopupIssue($from),

            // Without the AI add-on ported yet, route this to a human rather
            // than leaving the customer with nothing.
            SupportAction::Faq => $this->connectToHuman($from),
            default => $this->showMenu($from),
        };
    }

    private function onOrderId(string $from, string $text, array $context): void
    {
        $orderId = preg_replace('/[^A-Za-z0-9\-]/', '', $text) ?? '';

        if ($orderId === '') {
            $this->messenger->sendText(
                $from,
                "That doesn't look like an order ID. Please send it again, or *back* for the menu.",
            );

            return;
        }

        $action = SupportAction::tryFrom($context['action'] ?? '');

        if ($action === null) {
            $this->showMenu($from);

            return;
        }

        $panel = $this->panel();

        // Only fail out when the action actually needs the panel — a
        // partial-completion report is just a note to staff.
        if ($action->needsPanel() && $panel === null) {
            $this->messenger->sendText($from, "⚠️ Support isn't fully set up yet. Please try again later.");
            $this->finish($from);

            return;
        }

        match ($action) {
            SupportAction::Status => $this->reportStatus($from, $panel, $orderId),
            SupportAction::Refill => $this->requestRefill($from, $panel, $orderId),
            SupportAction::Cancel => $this->requestCancellation($from, $panel, $orderId),
            SupportAction::SpeedUp => $this->requestSpeedUp($from, $orderId),
            SupportAction::Partial => $this->reportPartial($from, $orderId),
            default => $this->showMenu($from),
        };

        // Back to the menu, ready for the next request.
        $this->moveTo($from, SupportState::Menu);
        $this->messenger->sendText($from, 'Reply *0* to see the menu again, or *cancel* to exit.');
    }

    // ---- the actions -----------------------------------------------------

    private function reportStatus(string $from, TenantPanel $panel, string $orderId): void
    {
        $result = SmmProviderClient::forPanel($panel)->checkStatus($orderId);

        if ($result->failed) {
            $this->messenger->sendText($from, "❌ Order *#{$orderId}* not found: {$result->message}", 'NOT_FOUND');

            return;
        }

        $message = "📦 Order *#{$orderId}*\nStatus: *{$result->get('status')}*";

        if ($result->get('start_count') !== null) {
            $message .= "\nStart: {$result->get('start_count')}";
        }

        if ($result->get('remains') !== null) {
            $message .= "\nRemaining: {$result->get('remains')}";
        }

        $this->messenger->sendText($from, $message, 'STATUS_SUCCESS');
    }

    private function requestRefill(string $from, TenantPanel $panel, string $orderId): void
    {
        // Whether a refill is owed depends on the service the order was for,
        // matched against the reseller's own guarantee keywords.
        $verdict = GuaranteeMatcher::forTenant($this->tenantId)
            ->evaluate($this->serviceNameFor($orderId) ?? '');

        if (! $verdict->allowed) {
            $this->messenger->sendText(
                $from,
                "🚫 Order *#{$orderId}* has no refill guarantee.",
                'REFILL_NO_GUARANTEE',
            );

            return;
        }

        $result = SmmProviderClient::forPanel($panel)->refill($orderId);

        if ($result->failed) {
            $this->messenger->sendText(
                $from,
                "⚠️ Refill for *#{$orderId}* couldn't be submitted: {$result->message}",
                'REFILL_ERROR',
            );

            return;
        }

        $guarantee = $verdict->lifetime ? 'Lifetime ♾️' : "{$verdict->days} days";

        $this->messenger->sendText(
            $from,
            "♻️ Refill for *#{$orderId}* submitted!\nGuarantee: {$guarantee} ✅",
            'REFILL_SUCCESS',
        );

        $this->notifyStaff("♻️ Refill requested for *#{$orderId}* by {$from} (guarantee: {$guarantee})");
    }

    private function requestCancellation(string $from, TenantPanel $panel, string $orderId): void
    {
        // Confirm the order exists before promising anything about it.
        if (SmmProviderClient::forPanel($panel)->checkStatus($orderId)->failed) {
            $this->messenger->sendText($from, "❌ Order *#{$orderId}* not found.", 'CANCEL_INVALID');

            return;
        }

        $this->messenger->sendText(
            $from,
            "🗑️ Cancellation for *#{$orderId}* has been requested. Our team will confirm shortly.",
            'CANCEL_SUCCESS',
        );

        $this->notifyStaff("🗑️ Cancel requested for *#{$orderId}* by {$from}");
    }

    private function requestSpeedUp(string $from, string $orderId): void
    {
        $this->messenger->sendText(
            $from,
            "🚀 Speed-up for *#{$orderId}* requested. We'll prioritise it where possible.",
            'SPEEDUP_SUCCESS',
        );

        $this->notifyStaff("🚀 Speed-up requested for *#{$orderId}* by {$from}");
    }

    private function reportPartial(string $from, string $orderId): void
    {
        $this->messenger->sendText(
            $from,
            "🧾 Thanks — a *partial / fake completion* report for *#{$orderId}* has been logged. "
                .'Our team will review and compensate if eligible.',
            'PARTIAL_LOGGED',
        );

        $this->notifyStaff("🧾 Partial/Fake-comp report for *#{$orderId}* by {$from} — please review.");
    }

    /**
     * Option 5. From here a person answers, on this same number.
     *
     * The old platform handed out a `wa.me` link to a staff member's personal
     * WhatsApp, which moved the conversation somewhere the reseller's inbox
     * could not see and left the bot still listening on this one. Instead the
     * conversation is claimed: a ticket carries the thread, staff reply from
     * the inbox, and the bot goes quiet until they hand it back.
     */
    private function connectToHuman(string $from): void
    {
        Ticket::openHandoff($this->tenantId, $from);

        // The bot's own state is cleared: when staff hand the conversation
        // back, the customer should get the menu fresh rather than resume a
        // half-finished question from before the handoff.
        $this->finish($from);

        $this->messenger->sendText(
            $from,
            "👤 Connecting you to our team — someone will reply here shortly.\n"
                .'You can keep typing; your messages reach them directly.',
            'HUMAN_HANDOFF',
        );

        $this->notifyStaff("👤 {$from} asked to speak to a human — replying in the Support inbox.");
    }

    private function explainTopupIssue(string $from): void
    {
        $this->messenger->sendText($from, implode('', [
            "💸 *Top-up issue*\n\n",
            "Please send:\n",
            "• the amount you paid\n",
            "• the payment reference\n",
            "• the time of payment\n\n",
            'Our team will check and credit your wallet.',
        ]), 'TOPUP_HELP');

        $this->notifyStaff("💸 {$from} reported a top-up issue.");
        $this->moveTo($from, SupportState::Menu);
    }

    // ---- helpers ---------------------------------------------------------

    private function isEnabled(SupportAction $action): bool
    {
        $toggle = $action->toggleKey();

        if ($toggle === null) {
            return true;
        }

        return (bool) Arr::get(BotSettings::for($this->tenantId, self::BOT), "commands.{$toggle}", false);
    }

    private function panel(): ?TenantPanel
    {
        return TenantPanel::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->orderBy('id')
            ->first();
    }

    /**
     * The service an order was for, so refill eligibility can be judged. The
     * id may be ours or the panel's, depending on which the customer quotes.
     */
    private function serviceNameFor(string $orderId): ?string
    {
        return BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where(fn ($query) => $query
                ->where('provider_order_id', $orderId)
                ->orWhere('id', is_numeric($orderId) ? (int) $orderId : 0))
            ->value('service_name');
    }

    private function notifyStaff(string $message): void
    {
        foreach (Arr::get(BotSettings::for($this->tenantId, self::BOT), 'staff.numbers', []) as $number) {
            $this->messenger->sendText((string) $number, $message);
        }
    }

    private function moveTo(string $from, SupportState $state, array $context = []): void
    {
        BotConversation::put($this->tenantId, $from, self::BOT, $state->value, $context);
    }

    private function finish(string $from): void
    {
        BotConversation::clear($this->tenantId, $from, self::BOT);
    }
}
