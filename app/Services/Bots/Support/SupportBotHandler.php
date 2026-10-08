<?php

namespace App\Services\Bots\Support;

use App\Models\BotConversation;
use App\Models\BotOrder;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Ai\AiAnswers;
use App\Services\Bots\BotHandler;
use App\Services\Bots\BotMessenger;
use App\Services\Bots\BotSettings;
use App\Services\Bots\BotSimulation;
use App\Models\PanelAccountLink;
use App\Services\Guarantee\RefillDecision;
use App\Services\Guarantee\RefillPolicy;
use App\Services\Panel\AccountLinker;
use App\Services\Panel\PanelAdminClient;
use App\Services\Panel\PanelResponse;
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

    /**
     * Question-and-answer pairs carried into the next AI question.
     *
     * Enough for a follow-up to make sense, few enough that the reseller is
     * not re-billed for a long conversation on every turn.
     */
    private const AI_HISTORY_TURNS = 4;

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

        if ($lower === 'verify' && $this->verificationAvailable()) {
            $this->askForAccount($from, null);

            return;
        }

        if ($lower === 'unlink' && $this->verificationAvailable()) {
            $this->unlink($from);

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
            SupportState::AiFaq => $this->onAiQuestion($from, $text, $conversation->context ?? []),
            SupportState::AwaitAccount => $this->onAccount($from, $text, $conversation->context ?? []),
            SupportState::AwaitCode => $this->onCode($from, $text, $conversation->context ?? []),
            SupportState::AwaitConfirm => $this->onConfirm($from, $text, $conversation->context ?? []),
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

        // Acting on an order means acting on somebody's account: when the
        // panel can tell whose it is, the customer proves the account is theirs
        // first, then goes on to the order.
        if ($this->mustVerify($from)) {
            $this->askForAccount($from, $action);

            return;
        }

        $this->askForOrderId($from, $action);
    }

    private function askForOrderId(string $from, SupportAction $action): void
    {
        $this->moveTo($from, SupportState::AwaitOrderId, ['action' => $action->value]);

        $this->messenger->sendText(
            $from,
            "🔢 Please send the *Order ID* for *{$action->label()}*.\n(Reply *back* for the menu.)",
        );
    }

    // ---- proving an account is the customer's ------------------------------

    /** The panel's Admin API is connected and the reseller wants proof. */
    private function verificationAvailable(): bool
    {
        return ! BotSimulation::active() && PanelAdminClient::forPanel($this->panel()) !== null;
    }

    /** Should this customer be sent to verify before acting on an order? */
    private function mustVerify(string $from): bool
    {
        if (! $this->verificationAvailable()) {
            return false;
        }

        if (! (bool) Arr::get(BotSettings::for($this->tenantId, self::BOT), 'verify.required', true)) {
            return false;
        }

        return $this->linkedAccount($from) === null;
    }

    private function linkedAccount(string $from): ?PanelAccountLink
    {
        $panel = $this->panel();

        return $panel === null ? null : app(AccountLinker::class)->linked($panel, $from);
    }

    private function askForAccount(string $from, ?SupportAction $then): void
    {
        $panel = $this->panel();
        $site = $panel?->name ?? $this->tenant->business_name;

        $this->moveTo($from, SupportState::AwaitAccount, ['action' => $then?->value]);

        $this->messenger->sendText(
            $from,
            "🔐 *Verify your account*\n\n"
                ."Send the *username or email* of your account on {$site}. "
                ."We'll put a 6-digit code in that account's *Tickets*.\n\n"
                .'(Reply *back* for the menu.)',
        );
    }

    private function onAccount(string $from, string $text, array $context): void
    {
        $identifier = trim($text);
        $panel = $this->panel();
        $client = PanelAdminClient::forPanel($panel);

        if ($panel === null || $client === null) {
            $this->finish($from);
            $this->messenger->sendText($from, "⚠️ Support isn't fully set up yet. Please try again later.");

            return;
        }

        if ($identifier === '' || mb_strlen($identifier) > 100 || preg_match('/\s/', $identifier) === 1) {
            $this->messenger->sendText($from, 'Please send just your username or email, with no spaces. (Reply *back* for the menu.)');

            return;
        }

        $outcome = app(AccountLinker::class)->start($panel, $client, $from, $identifier);

        if ($outcome === AccountLinker::THROTTLED) {
            $this->messenger->sendText($from, "⏳ That's a lot of code requests. Please try again in an hour.");

            return;
        }

        if ($outcome === AccountLinker::FAILED) {
            $this->messenger->sendText($from, "⚠️ We couldn't reach your account just now. Please try again in a moment.");

            return;
        }

        // The same words whether or not the account exists, on purpose.
        $this->moveTo($from, SupportState::AwaitCode, ['action' => $context['action'] ?? null]);

        $this->messenger->sendText(
            $from,
            "📨 If that account exists, a ticket with a 6-digit code is waiting in *Tickets* on {$panel->name}.\n\n"
                .'Open it and send the code here. It works for '.AccountLinker::CODE_MINUTES." minutes.\n\n"
                .'(Reply *back* to cancel.)',
        );
    }

    private function onCode(string $from, string $text, array $context): void
    {
        $panel = $this->panel();
        $code = preg_replace('/\D/', '', $text) ?? '';

        if ($panel === null) {
            $this->finish($from);

            return;
        }

        if (strlen($code) !== 6) {
            $this->messenger->sendText($from, 'The code is 6 digits. Please send it again, or reply *back* to cancel.');

            return;
        }

        $linker = app(AccountLinker::class);
        $outcome = $linker->verify($panel, $from, $code);

        if ($outcome === AccountLinker::WRONG) {
            $left = $linker->triesLeft($panel, $from);
            $this->messenger->sendText($from, "❌ That code isn't right. {$left} ".($left === 1 ? 'try' : 'tries').' left.');

            return;
        }

        if ($outcome !== AccountLinker::OK) {
            $this->moveTo($from, SupportState::AwaitAccount, ['action' => $context['action'] ?? null]);
            $this->messenger->sendText($from, '⌛ That code has expired. Send your username or email again to get a new one.');

            return;
        }

        $name = $linker->linked($panel, $from)?->panel_username;
        $this->messenger->sendText($from, "✅ Verified as *{$name}*. You can now ask about your orders. Send *unlink* any time to disconnect.");

        $action = SupportAction::tryFrom((string) ($context['action'] ?? ''));

        if ($action !== null && $action->needsOrderId()) {
            $this->askForOrderId($from, $action);

            return;
        }

        $this->showMenu($from);
    }

    private function unlink(string $from): void
    {
        $panel = $this->panel();

        if ($panel !== null) {
            app(AccountLinker::class)->unlink($panel, $from);
        }

        $this->finish($from);
        $this->messenger->sendText($from, '🔓 Your account is disconnected from this chat. Send *verify* to connect one again.');
    }

    private function handleImmediate(string $from, SupportAction $action): void
    {
        match ($action) {
            SupportAction::Human => $this->connectToHuman($from),
            SupportAction::TopupIssue => $this->explainTopupIssue($from),
            SupportAction::Faq => $this->startAiFaq($from),
            default => $this->showMenu($from),
        };
    }

    // ---- AI FAQ ----------------------------------------------------------

    /**
     * Option 8. A reseller without the add-on gets a human instead.
     *
     * Checked here rather than hidden from the menu because the menu is one
     * static block of text — and a customer who picked it must land somewhere
     * that helps, not on an apology.
     */
    private function startAiFaq(string $from): void
    {
        if (! app(AiAnswers::class)->isAvailable($this->tenantId)) {
            $this->connectToHuman($from);

            return;
        }

        $this->moveTo($from, SupportState::AiFaq, ['history' => []]);

        $this->messenger->sendText(
            $from,
            "🤖 Ask me anything about our services or prices.\n"
                .'Reply *0* for the menu, or *5* to reach a human.',
            'AI_FAQ_OPEN',
        );
    }

    /**
     * A question for the AI.
     *
     * The menu and exit words are handled before this is ever reached, so a
     * customer is never trapped in a conversation with the assistant — that
     * escape hatch matters more than any answer it gives.
     */
    private function onAiQuestion(string $from, string $text, array $context): void
    {
        if ($text === '') {
            $this->messenger->sendText($from, 'Please type your question, or *0* for the menu.');

            return;
        }

        $history = is_array($context['history'] ?? null) ? $context['history'] : [];

        $answer = app(AiAnswers::class)->answer(
            tenant: $this->tenant,
            question: $text,
            shop: Arr::get(BotSettings::for($this->tenantId, self::BOT), 'shop', []),
            history: $history,
        );

        // DeepSeek was unreachable, refused the key, or the add-on lapsed
        // mid-conversation. A customer who has already typed a question is
        // owed a person, not a retry.
        if ($answer === null) {
            $this->messenger->sendText(
                $from,
                "⚠️ I couldn't answer that one. Let me get you a human.",
                'AI_FAQ_FAILED',
            );

            $this->connectToHuman($from);

            return;
        }

        $this->messenger->sendText($from, $answer, 'AI_FAQ_ANSWER');

        $this->moveTo($from, SupportState::AiFaq, [
            'history' => $this->trimmedHistory($history, $text, $answer),
        ]);
    }

    /**
     * The last few turns, so a follow-up like "and for TikTok?" makes sense.
     *
     * Capped because the whole history is re-sent on every question and the
     * reseller pays for those tokens each time — an hour-long conversation
     * would bill them for the same opening exchange fifty times over.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function trimmedHistory(array $history, string $question, string $answer): array
    {
        $history[] = ['role' => 'user', 'content' => $question];
        $history[] = ['role' => 'assistant', 'content' => $answer];

        return array_slice($history, -self::AI_HISTORY_TURNS * 2);
    }

    /** More than this in one message is a list, not a request. */
    private const MAX_ORDERS = 10;

    /**
     * The order IDs in what the customer sent.
     *
     * A customer with several orders sends them together — one a line, or in a
     * sentence — so two or more runs of digits are read as separate orders.
     * Squeezing them into one number is what used to happen, and the panel
     * answered "Incorrect order ID" to a customer who had done nothing wrong.
     * A single value is taken as it is, so an ID with letters still works.
     *
     * @return list<string>
     */
    private function orderIds(string $text): array
    {
        preg_match_all('/\d{3,18}/', $text, $found);
        $groups = array_values(array_unique($found[0]));

        if (count($groups) >= 2) {
            return $groups;
        }

        $single = preg_replace('/[^A-Za-z0-9\-]/', '', $text) ?? '';

        return $single === '' ? [] : [$single];
    }

    private function onOrderId(string $from, string $text, array $context): void
    {
        $ids = $this->orderIds($text);

        if ($ids === []) {
            $this->messenger->sendText(
                $from,
                "That doesn't look like an order ID. Please send it again, or *back* for the menu.",
            );

            return;
        }

        if (count($ids) > self::MAX_ORDERS) {
            $this->messenger->sendText(
                $from,
                'Please send up to '.self::MAX_ORDERS.' order IDs at a time. You sent '.count($ids).'.',
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
        // A rehearsal has no panel to ask, and must not ask a real one.
        if ($action->needsPanel() && $panel === null && ! BotSimulation::active()) {
            $this->messenger->sendText($from, "⚠️ Support isn't fully set up yet. Please try again later.");
            $this->finish($from);

            return;
        }

        // With the panel's Admin API connected, an order is only ever shown or
        // acted on for the account the customer has proven is theirs, and the
        // panel's own rules and words decide the outcome.
        if ($this->verificationAvailable()) {
            $link = $this->linkedAccount($from);

            if ($link === null && $this->mustVerify($from)) {
                $this->askForAccount($from, $action);

                return;
            }

            if ($link !== null) {
                $client = PanelAdminClient::forPanel($panel);

                if (count($ids) === 1) {
                    $this->actForAccount($from, $action, $ids[0], $link, $client);
                } elseif ($this->batchForAccount($from, $action, $ids, $link, $client, false) === false) {
                    return;
                }

                $this->moveTo($from, SupportState::Menu);
                $this->messenger->sendText($from, 'Reply *0* to see the menu again, or *cancel* to exit.');

                return;
            }
        }

        foreach ($ids as $orderId) {
            match ($action) {
                SupportAction::Status => $this->reportStatus($from, $panel, $orderId),
                SupportAction::Refill => $this->requestRefill($from, $panel, $orderId),
                SupportAction::Cancel => $this->requestCancellation($from, $panel, $orderId),
                SupportAction::SpeedUp => $this->requestSpeedUp($from, $orderId),
                SupportAction::Partial => $this->reportPartial($from, $orderId),
                default => $this->showMenu($from),
            };
        }

        // Back to the menu, ready for the next request.
        $this->moveTo($from, SupportState::Menu);
        $this->messenger->sendText($from, 'Reply *0* to see the menu again, or *cancel* to exit.');
    }

    /** "yes" to go ahead with cancelling several orders; anything else stops. */
    private function onConfirm(string $from, string $text, array $context): void
    {
        $action = SupportAction::tryFrom((string) ($context['action'] ?? ''));
        $ids = array_values(array_filter((array) ($context['ids'] ?? []), 'is_string'));
        $answer = mb_strtolower(trim($text));

        if (! in_array($answer, ['yes', 'y', 'ndiyo', 'ndio', 'confirm', 'ok'], true)) {
            $this->moveTo($from, SupportState::Menu);
            $this->messenger->sendText($from, "👍 Nothing was cancelled. Reply *0* for the menu.");

            return;
        }

        $link = $this->linkedAccount($from);
        $client = PanelAdminClient::forPanel($this->panel());

        if ($action === null || $ids === [] || $link === null || $client === null) {
            $this->showMenu($from);

            return;
        }

        $this->batchForAccount($from, $action, $ids, $link, $client, true);
        $this->moveTo($from, SupportState::Menu);
        $this->messenger->sendText($from, 'Reply *0* to see the menu again, or *cancel* to exit.');
    }

    /**
     * Several orders at once, answered in one message.
     *
     * Each order is checked on its own, as a single one is: it must be this
     * customer's, and the panel's own rules decide what happens. The answer is
     * a line per order rather than a message each, and the team hears once.
     * Cancelling is not easily undone, so more than one asks first.
     *
     * @param  list<string>  $ids
     * @return bool|null false when it stopped to ask for confirmation
     */
    private function batchForAccount(string $from, SupportAction $action, array $ids, PanelAccountLink $link, PanelAdminClient $client, bool $confirmed): ?bool
    {
        if ($action === SupportAction::Cancel && ! $confirmed) {
            $this->moveTo($from, SupportState::AwaitConfirm, ['action' => $action->value, 'ids' => $ids]);
            $this->messenger->sendText(
                $from,
                '🗑️ Cancel *'.count($ids)."* orders?\n".implode(', ', array_map(fn ($id) => "#{$id}", $ids))
                    ."\n\nThis can't be undone. Reply *yes* to confirm, or *back* to stop.",
            );

            return false;
        }

        $lines = [];
        $done = [];
        $asked = [];

        foreach ($ids as $id) {
            $found = $client->order($id);
            $owner = is_array($found->get('user')) ? ($found->get('user')['id'] ?? null) : null;

            if ($found->failed && $found->code !== 404) {
                $lines[] = "⚠️ #{$id} — couldn't reach the panel";

                continue;
            }

            if ($found->failed || (int) $owner !== (int) $link->panel_user_id) {
                $lines[] = "❌ #{$id} — isn't on your account";

                continue;
            }

            switch ($action) {
                case SupportAction::Status:
                    $status = ucfirst(str_replace('_', ' ', (string) $found->get('status', 'unknown')));
                    $remains = $found->get('remains');
                    $lines[] = "📦 #{$id} — {$status}".($remains !== null ? " (remaining {$remains})" : '');
                    break;

                case SupportAction::Refill:
                    $result = $client->refill($id);

                    if ($result->failed) {
                        $lines[] = "❌ #{$id} — {$result->message}";
                    } else {
                        $lines[] = "♻️ #{$id} — refill submitted";
                        $done[] = $id;
                    }
                    break;

                case SupportAction::Cancel:
                    $result = $client->cancel($id);

                    if ($result->failed && $result->code === 403) {
                        // The key may not cancel: it becomes a request for the team.
                        $lines[] = "📝 #{$id} — requested, our team will confirm";
                        $asked[] = $id;
                    } elseif ($result->failed) {
                        $lines[] = "❌ #{$id} — {$result->message}";
                    } else {
                        $lines[] = "🗑️ #{$id} — cancelled";
                        $done[] = $id;
                    }
                    break;

                case SupportAction::SpeedUp:
                    $lines[] = "🚀 #{$id} — requested";
                    $asked[] = $id;
                    break;

                case SupportAction::Partial:
                    $lines[] = "🧾 #{$id} — reported, our team will review";
                    $asked[] = $id;
                    break;

                default:
                    break;
            }
        }

        $title = match ($action) {
            SupportAction::Status => '📦 *Order status*',
            SupportAction::Refill => '♻️ *Refills*',
            SupportAction::Cancel => '🗑️ *Cancellations*',
            SupportAction::SpeedUp => '🚀 *Speed-ups*',
            default => '🧾 *Reports*',
        };

        $this->messenger->sendText($from, $title."\n\n".implode("\n", $lines));

        $tag = fn (array $list) => implode(', ', array_map(fn ($id) => "#{$id}", $list));
        $note = [];

        if ($done !== []) {
            $note[] = ($action === SupportAction::Cancel ? 'cancelled ' : 'done ').$tag($done);
        }

        if ($asked !== []) {
            $note[] = 'needs you: '.$tag($asked);
        }

        if ($note !== [] && $action !== SupportAction::Status) {
            $this->notifyStaff("{$title} for {$from} (verified account) — ".implode('; ', $note));
        }

        return true;
    }
    // ---- the actions, for a verified account --------------------------------

    private function actForAccount(string $from, SupportAction $action, string $orderId, PanelAccountLink $link, PanelAdminClient $client): void
    {
        $found = $client->order($orderId);

        // "Not found" and "not yours" read the same: the customer learns
        // nothing about orders that are not theirs.
        $owner = is_array($found->get('user')) ? ($found->get('user')['id'] ?? null) : null;

        if ($found->failed && $found->code !== 404) {
            $this->messenger->sendText($from, "⚠️ We couldn't reach the panel just now. Please try again in a moment.");

            return;
        }

        if ($found->failed || (int) $owner !== (int) $link->panel_user_id) {
            $this->messenger->sendText($from, "❌ Order *#{$orderId}* isn't on your account.", 'NOT_FOUND');

            return;
        }

        match ($action) {
            SupportAction::Status => $this->reportAccountStatus($from, $orderId, $found),
            SupportAction::Refill => $this->requestAccountRefill($from, $orderId, $found, $client),
            SupportAction::Cancel => $this->requestAccountCancel($from, $orderId, $client),
            SupportAction::SpeedUp => $this->requestSpeedUp($from, $orderId),
            SupportAction::Partial => $this->reportPartial($from, $orderId),
            default => $this->showMenu($from),
        };
    }

    private function reportAccountStatus(string $from, string $orderId, PanelResponse $order): void
    {
        $status = ucfirst(str_replace('_', ' ', (string) $order->get('status', 'unknown')));

        $message = "📦 Order *#{$orderId}*\nStatus: *{$status}*";

        if ($order->get('service')) {
            $message .= "\nService: {$order->get('service')}";
        }

        if ($order->get('start_count') !== null) {
            $message .= "\nStart: {$order->get('start_count')}";
        }

        if ($order->get('remains') !== null) {
            $message .= "\nRemaining: {$order->get('remains')}";
        }

        $this->messenger->sendText($from, $message, 'STATUS_SUCCESS');
    }

    private function requestAccountRefill(string $from, string $orderId, PanelResponse $order, PanelAdminClient $client): void
    {
        $result = $client->refill($orderId);

        if ($result->failed) {
            $this->messenger->sendText(
                $from,
                "⚠️ Refill for *#{$orderId}* couldn't be submitted: {$result->message}",
                'REFILL_ERROR',
            );

            return;
        }

        $days = (int) $order->get('refill_days', 0);

        $this->messenger->sendText(
            $from,
            "♻️ Refill for *#{$orderId}* submitted!".($days > 0 ? "\nGuarantee: {$days} days ✅" : ''),
            'REFILL_SUCCESS',
        );

        $this->notifyStaff("♻️ Refill requested for *#{$orderId}* by {$from} (verified account)");
    }

    private function requestAccountCancel(string $from, string $orderId, PanelAdminClient $client): void
    {
        $result = $client->cancel($orderId);

        // The staff account behind the key may not be allowed to cancel. Then
        // it is a request for the team, as it was before the panel was connected.
        if ($result->failed && $result->code === 403) {
            $this->messenger->sendText(
                $from,
                "🗑️ Cancellation for *#{$orderId}* has been requested. Our team will confirm shortly.",
                'CANCEL_SUCCESS',
            );
            $this->notifyStaff("🗑️ Cancel requested for *#{$orderId}* by {$from} (verified account)");

            return;
        }

        if ($result->failed) {
            $this->messenger->sendText($from, "⚠️ Order *#{$orderId}* couldn't be cancelled: {$result->message}");

            return;
        }

        $this->messenger->sendText($from, "🗑️ Order *#{$orderId}* has been cancelled. Any refund is back in your panel balance.");
        $this->notifyStaff("🗑️ Order *#{$orderId}* cancelled by {$from} (verified account)");
    }

    // ---- the actions -----------------------------------------------------

    private function reportStatus(string $from, ?TenantPanel $panel, string $orderId): void
    {
        if (BotSimulation::active()) {
            $this->messenger->sendText(
                $from,
                "📦 Order *#{$orderId}*\nStatus: *In progress*\nStart: 1,204\nRemaining: 318".$this->simNote('read live from your panel'),
            );

            return;
        }

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

    private function requestRefill(string $from, ?TenantPanel $panel, string $orderId): void
    {
        if (BotSimulation::active()) {
            $this->messenger->sendText(
                $from,
                "♻️ Refill for *#{$orderId}* submitted!\nGuarantee: 30 days ✅".$this->simNote('checked against your guarantee rules, then sent to your panel'),
            );

            return;
        }

        // Whether a refill is owed depends on the service the order was for:
        // the reseller's rules first, then what the service promises, then
        // their default for services that say nothing.
        $decision = RefillPolicy::forTenant($this->tenantId)->decide($this->orderFor($orderId));

        if ($decision->outcome === RefillDecision::HUMAN) {
            $this->messenger->sendText(
                $from,
                "🤝 I've asked our team to check the refill for *#{$orderId}*. Someone will reply here shortly.",
            );
            $this->notifyStaff("🤝 Refill for *#{$orderId}* from {$from} needs a decision — this service doesn't say if it has a refill.");

            return;
        }

        if ($decision->outcome === RefillDecision::EXPIRED) {
            $this->messenger->sendText(
                $from,
                "⏳ The refill guarantee for *#{$orderId}* was {$decision->days} days, and this order is {$decision->ageDays} days old, so it has ended.",
            );

            return;
        }

        if (! $decision->allowed()) {
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

        // A refill allowed by default has no promise to quote.
        $guarantee = $decision->lifetime ? 'Lifetime ♾️' : ($decision->days !== null ? "{$decision->days} days" : null);

        $this->messenger->sendText(
            $from,
            "♻️ Refill for *#{$orderId}* submitted!".($guarantee !== null ? "\nGuarantee: {$guarantee} ✅" : ''),
            'REFILL_SUCCESS',
        );

        $this->notifyStaff("♻️ Refill requested for *#{$orderId}* by {$from}".($guarantee !== null ? " (guarantee: {$guarantee})" : ''));
    }

    private function requestCancellation(string $from, ?TenantPanel $panel, string $orderId): void
    {
        if (BotSimulation::active()) {
            $this->messenger->sendText(
                $from,
                "🗑️ Cancellation for *#{$orderId}* has been requested. Our team will confirm shortly.".$this->simNote('your team is alerted with the order ID'),
            );

            return;
        }

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

    /** What the person rehearsing is told about the line above it. */
    private function simNote(string $what): string
    {
        return "\n\n🧪 _Rehearsal: on your live shop this is {$what}._";
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
    private function orderFor(string $orderId): ?BotOrder
    {
        return BotOrder::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where(fn ($query) => $query
                ->where('provider_order_id', $orderId)
                ->orWhere('id', is_numeric($orderId) ? (int) $orderId : 0))
            ->first();
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
