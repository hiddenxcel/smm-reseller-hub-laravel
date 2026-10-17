<?php

/*
|--------------------------------------------------------------------------
| Broadcast pacing
|--------------------------------------------------------------------------
|
| `broadcasts:send` runs every minute and sends at most this many messages per
| broadcast each time, spacing them out. WhatsApp flags a number that sends a
| sudden burst, so the limits are deliberately gentle; Telegram is far more
| tolerant.
|
*/

return [
    'whatsapp_per_run' => 15,
    'telegram_per_run' => 40,

    // Milliseconds between two WhatsApp sends in one run.
    'whatsapp_pause_ms' => 800,

    // This many refusals in a row and the broadcast pauses itself.
    'stop_after_failures' => 5,

    // How many a reseller may start in a day.
    'daily_limit' => 5,

    'max_length' => 900,
];
