<?php

/*
 * Only the widget API is exposed cross-origin. The CORS layer cannot know which chatbot a request
 * targets (preflights carry no body), so it answers "*" and every endpoint enforces the per-chatbot
 * allowed_domains list itself. No credentials: the widget authenticates with a Bearer token.
 */
return [

    'paths' => ['api/widget/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    'supports_credentials' => false,

];
