<?php

// Comma separated env list -> array of trimmed, non empty strings.
$csv = static fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));

return [

    // Seconds without receiving data (read timeout while streaming) and to open the connection.
    'timeout' => (int) env('LLM_TIMEOUT', 60),
    'connect_timeout' => (int) env('LLM_CONNECT_TIMEOUT', 5),

    // Model listing used by the chatbot form when the chatbot has a key of its own.
    'models_cache_ttl' => (int) env('LLM_MODELS_CACHE_TTL', 300),
    'models_per_minute_user' => (int) env('LLM_MODELS_PER_MINUTE_USER', 10),

    // Ids containing any of these fragments (case insensitive) are dropped from the model listing:
    // speech, embeddings, moderation, image and other models that cannot answer a chat.
    'non_chat_model_patterns' => $csv((string) env('LLM_NON_CHAT_MODEL_PATTERNS', '')) ?: [
        'whisper', 'tts', 'orpheus', 'embed', 'embedding', 'guard', 'safeguard', 'moderation',
        'dall-e', 'transcribe', 'audio', 'realtime', 'image',
    ],

    // Used when a chatbot does not set its own limit. Anthropic requires max_tokens on every request.
    'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),

    'providers' => [
        // Chat Completions. "model" is the default used when a chatbot has no model set.
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL', 'gpt-6-luna'),
            'max_tokens_param' => 'max_completion_tokens',
            // Reasoning models may reject a custom temperature, so it is not sent unless enabled.
            'send_temperature' => (bool) env('OPENAI_SEND_TEMPERATURE', false),
            'stream_usage' => true,
        ],
        'groq' => [
            'api_key' => env('GROQ_API_KEY'),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'max_tokens_param' => 'max_tokens',
            'send_temperature' => true,
            // Usage arrives in x_groq.usage of the last chunk, parsed by the client.
            'stream_usage' => false,
        ],
        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-v4-flash'),
            'max_tokens_param' => 'max_tokens',
            'send_temperature' => (bool) env('DEEPSEEK_SEND_TEMPERATURE', false),
            'stream_usage' => true,
        ],
        // Messages API. Current Claude models reject temperature, top_p and top_k, so none is sent.
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'model' => env('ANTHROPIC_MODEL', 'claude-haiku-5-5'),
            'version' => env('ANTHROPIC_VERSION', '2026-10-21'),
        ],
        // streamGenerateContent with alt=sse.
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        ],
    ],

    // Models a chatbot may use while it runs on the PLATFORM key (no key of its own). Bring-your-own-key
    // chatbots are not restricted by this list. Each provider defaults to its default model above.
    // Override with a comma separated list, e.g. OPENAI_PLATFORM_MODELS=gpt-6-luna,gpt-6-mini.
    'platform_models' => [
        'openai' => $csv((string) env('OPENAI_PLATFORM_MODELS', '')) ?: [env('OPENAI_MODEL', 'gpt-6-luna')],
        'groq' => $csv((string) env('GROQ_PLATFORM_MODELS', '')) ?: [env('GROQ_MODEL', 'llama-3.3-70b-versatile')],
        'deepseek' => $csv((string) env('DEEPSEEK_PLATFORM_MODELS', '')) ?: [env('DEEPSEEK_MODEL', 'deepseek-v4-flash')],
        'anthropic' => $csv((string) env('ANTHROPIC_PLATFORM_MODELS', '')) ?: [env('ANTHROPIC_MODEL', 'claude-haiku-5-5')],
        'gemini' => $csv((string) env('GEMINI_PLATFORM_MODELS', '')) ?: [env('GEMINI_MODEL', 'gemini-3.5-flash-lite')],
    ],

    'chat' => [
        // Last N messages of the conversation sent to the model.
        'history_limit' => (int) env('CHAT_HISTORY_LIMIT', 10),
        'top_k' => (int) env('CHAT_TOP_K', 6),
        // Cosine score floor for retrieved chunks. Tune it per embedding model.
        'score_threshold' => (float) env('CHAT_SCORE_THRESHOLD', 0.5),
    ],

];
