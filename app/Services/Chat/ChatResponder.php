<?php

namespace App\Services\Chat;

use App\Contracts\EmbeddingClient;
use App\Contracts\VectorStore;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\Message;
use App\Services\LLM\LlmClientFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatResponder
{
    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly VectorStore $vectors,
        private readonly LlmClientFactory $clients,
        private readonly PromptBuilder $prompts,
        private readonly int $topK = 6,
        private readonly ?float $scoreThreshold = null,
        private readonly int $historyLimit = 10,
    ) {}

    /**
     * Answers $userMessage (already stored in the conversation), streaming tokens to $onToken,
     * and persists the assistant reply.
     *
     * @param  callable(string): void  $onToken
     */
    public function respond(Conversation $conversation, Message $userMessage, callable $onToken): Message
    {
        $chatbot = $conversation->chatbot;

        // Resolve the client first so a missing key fails before any retrieval work.
        $client = $this->clients->for($chatbot);

        $chunks = $this->retrieve($conversation, $userMessage->content);

        $request = $this->prompts->build($chatbot, $this->history($conversation), $chunks);

        $response = $client->stream($request, $onToken);

        $reply = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $response->content,
            'tokens_in' => $response->tokensIn,
            'tokens_out' => $response->tokensOut,
            'sources' => $chunks->pluck('id')->values()->all(),
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        return $reply;
    }

    /** @return Collection<int, KnowledgeChunk> */
    private function retrieve(Conversation $conversation, string $question): Collection
    {
        $chatbot = $conversation->chatbot;

        try {
            $hits = $this->vectors->search(
                $chatbot->ulid,
                $this->embeddings->embedQuery($question),
                $this->topK,
                $this->scoreThreshold,
            );

            if ($hits === []) {
                return new Collection;
            }

            // The vector store is not trusted for tenancy: chunks must belong to this chatbot in the database too.
            $found = KnowledgeChunk::query()
                ->where('chatbot_id', $chatbot->id)
                ->whereIn('id', array_column($hits, 'id'))
                ->get()
                ->keyBy('id');

            return (new Collection($hits))
                ->map(fn (array $hit) => $found->get($hit['id']))
                ->filter()
                ->values();
        } catch (Throwable $e) {
            // Answer without context instead of failing the conversation.
            Log::warning('Chat retrieval failed, answering without context', [
                'chatbot_id' => $chatbot->id,
                'conversation_id' => $conversation->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return new Collection;
        }
    }

    /** @return Collection<int, Message> */
    private function history(Conversation $conversation): Collection
    {
        return $conversation->messages()
            ->orderByDesc('id')
            ->limit(max(1, $this->historyLimit))
            ->get()
            ->reverse()
            ->values();
    }
}
