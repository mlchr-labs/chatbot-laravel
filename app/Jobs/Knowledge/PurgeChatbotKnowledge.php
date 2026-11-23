<?php

namespace App\Jobs\Knowledge;

use App\Contracts\VectorStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Cleans what a deleted chatbot leaves outside the database: its vectors and uploaded files.
 * Carries plain identifiers because the chatbot row no longer exists when this runs.
 */
class PurgeChatbotKnowledge implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $chatbotUlid,
        public readonly int $userId,
    ) {
        $this->onQueue((string) config('knowledge.queue'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(VectorStore $store): void
    {
        $store->deleteByChatbot($this->chatbotUlid);

        // Same layout used by KnowledgeSourceController on upload.
        Storage::disk((string) config('knowledge.disk'))
            ->deleteDirectory("knowledge/{$this->userId}/{$this->chatbotUlid}");
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Chatbot knowledge purge failed', [
            'chatbot_ulid' => $this->chatbotUlid,
            'exception' => $exception,
        ]);
    }
}
