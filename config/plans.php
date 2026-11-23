<?php

return [

    // Caps applied to each panel user, whatever the number of chatbots.

    // Chatbots a user may own.
    'max_chatbots_per_user' => (int) env('PLAN_MAX_CHATBOTS_PER_USER', 10),

    // Knowledge sources (uploaded documents) a user may keep, across all chatbots.
    'max_knowledge_sources_per_user' => (int) env('PLAN_MAX_KNOWLEDGE_SOURCES_PER_USER', 200),

    // Total size, in bytes, of the documents a user may keep (default 500 MB).
    'max_knowledge_bytes_per_user' => (int) env('PLAN_MAX_KNOWLEDGE_BYTES_PER_USER', 500 * 1024 * 1024),

];
