<?php

namespace App\Providers;

use App\Contracts\EmbeddingClient;
use App\Contracts\VectorStore;
use App\Services\Chat\ChatResponder;
use App\Services\Chat\PromptBuilder;
use App\Services\LLM\LlmClientFactory;
use Illuminate\Support\ServiceProvider;

class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PromptBuilder::class, fn () => new PromptBuilder(
            historyLimit: (int) config('llm.chat.history_limit'),
        ));

        $this->app->bind(ChatResponder::class, fn ($app) => new ChatResponder(
            embeddings: $app->make(EmbeddingClient::class),
            vectors: $app->make(VectorStore::class),
            clients: $app->make(LlmClientFactory::class),
            prompts: $app->make(PromptBuilder::class),
            topK: (int) config('llm.chat.top_k'),
            scoreThreshold: config('llm.chat.score_threshold'),
            historyLimit: (int) config('llm.chat.history_limit'),
        ));
    }
}
