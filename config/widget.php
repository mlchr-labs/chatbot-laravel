<?php

return [

    // Lifetime of a widget token, in minutes.
    'token_ttl_minutes' => (int) env('WIDGET_TOKEN_TTL_MINUTES', 240),

    // Maximum size of a visitor message, in characters.
    'max_message_length' => (int) env('WIDGET_MAX_MESSAGE_LENGTH', 2000),

    // Messages returned when a conversation is resumed.
    'history_messages' => 20,

    // Seconds a reply may stay "in progress" before the lock expires on its own (above the job timeout).
    'reply_lock_seconds' => (int) env('WIDGET_REPLY_LOCK_SECONDS', 150),

    'limits' => [
        'config_per_minute_ip' => (int) env('WIDGET_LIMIT_CONFIG_PER_MINUTE_IP', 60),
        'sandbox_page_per_minute_ip' => (int) env('WIDGET_LIMIT_SANDBOX_PAGE_PER_MINUTE_IP', 30),
        'sessions_per_minute_ip' => (int) env('WIDGET_LIMIT_SESSIONS_PER_MINUTE_IP', 20),
        'messages_per_minute_conversation' => (int) env('WIDGET_LIMIT_MESSAGES_PER_MINUTE_CONVERSATION', 10),
        'messages_per_minute_ip' => (int) env('WIDGET_LIMIT_MESSAGES_PER_MINUTE_IP', 30),
        'broadcast_auth_per_minute_ip' => (int) env('WIDGET_LIMIT_BROADCAST_AUTH_PER_MINUTE_IP', 60),
        // Rolling 24h window per chatbot.
        'messages_per_day_chatbot' => (int) env('WIDGET_DAILY_QUOTA', 2000),
        // Rolling 24h window across every chatbot of the same owner (see also config/plans.php).
        'messages_per_day_user' => (int) env('WIDGET_DAILY_QUOTA_USER', 5000),
        // Rolling 24h windows per visitor IP (hashed), so one address cannot burn a chatbot's or the platform's quota.
        'messages_per_day_ip_chatbot' => (int) env('WIDGET_DAILY_MESSAGES_PER_IP_CHATBOT', 200),
        'sessions_per_day_ip' => (int) env('WIDGET_DAILY_SESSIONS_PER_IP', 300),
    ],

    // Token streaming: flush when this many characters are pending or this many ms passed.
    'stream' => [
        'flush_chars' => (int) env('WIDGET_STREAM_FLUSH_CHARS', 20),
        'flush_ms' => (int) env('WIDGET_STREAM_FLUSH_MS', 40),
    ],

];
