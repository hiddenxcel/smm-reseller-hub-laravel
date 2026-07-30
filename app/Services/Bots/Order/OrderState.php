<?php

namespace App\Services\Bots\Order;

/**
 * Where a customer is in the order bot. Persisted as a string in
 * bot_conversations.state, so the values are a data contract — renaming one
 * strands every customer currently sitting in it.
 */
enum OrderState: string
{
    case MainMenu = 'MAIN_MENU';
    case SelectLanguage = 'SELECT_LANG';

    // The order flow proper.
    case SelectPlatform = 'SELECT_PLATFORM';
    case SelectCategory = 'SELECT_CATEGORY';
    case SelectService = 'SELECT_SERVICE';
    case SelectQuantity = 'SELECT_QTY';
    case SendLink = 'SEND_LINK';
    case Confirm = 'CONFIRM';

    // Paying for it.
    case TopupAmount = 'TOPUP_AMOUNT';
    case TopupDecision = 'TOPUP_DECISION';
    case TopupPhone = 'TOPUP_PHONE';
    case AwaitingPayment = 'AWAITING_PAYMENT';
    case AwaitingBinanceOrder = 'AWAITING_BINANCE_ORDER';

    case AiChat = 'AI_CHAT';
}
