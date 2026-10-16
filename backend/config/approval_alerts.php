<?php

return [
    // Disabled by default. Require an operator-configured BotFather bot, HTTPS
    // webhook with secret_token, and explicit owner private-chat pairing.
    'enabled' => (bool) env('VENUE_TELEGRAM_APPROVAL_ENABLED', false),
    'bot_token' => env('VENUE_TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('VENUE_TELEGRAM_BOT_USERNAME'),
    'webhook_secret' => env('VENUE_TELEGRAM_WEBHOOK_SECRET'),
];
