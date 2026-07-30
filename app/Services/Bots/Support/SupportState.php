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
}
