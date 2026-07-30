<?php

namespace App\Enums;

/**
 * The a-la-carte services. Each is sold on its own and holds its own
 * subscription row with its own ends_at.
 */
enum ServiceKey: string
{
    case OrderBot = 'order_bot';
    case SupportBot = 'support_bot';
    case AiTickets = 'ai_tickets';
    case AiChat = 'ai_chat';
    case NumberRental = 'number_rental';
}
