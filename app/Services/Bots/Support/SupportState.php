<?php

namespace App\Services\Bots\Support;

/**
 * Persisted in bot_conversations.state, so these values are a data contract.
 */
enum SupportState: string
{
    case Menu = 'MENU';
    case AwaitOrderId = 'AWAIT_ORDER';
    case AiFaq = 'AI_FAQ';

    /** Waiting for the username or email of the panel account to verify. */
    case AwaitAccount = 'AWAIT_ACCOUNT';

    /** Waiting for the code that was put in that account's tickets. */
    case AwaitCode = 'AWAIT_CODE';

    /** Waiting for "yes" before several orders are cancelled at once. */
    case AwaitConfirm = 'AWAIT_CONFIRM';
}
