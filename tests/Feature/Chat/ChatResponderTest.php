<?php

namespace Tests\Feature\Chat;

use App\Contracts\EmbeddingClient;
use App\Contracts\VectorStore;
use App\Enums\LlmProvider;
use App\Enums\MessageRole;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\Message;
use App\Services\Chat\ChatResponder;
use App\Services\Chat\PromptBuilder;
use App\Services\LLM\Exceptions\MissingApiKeyException;
use App\Services\LLM\LlmClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ChatResponderTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{string, int, ?float}> */
    private array $searches = [];

    private function sse(): string
    {
        return implode("\n\n", [
            'data: {"choices":[{"delta":{"content":"Em "}}]}',
            'data: {"choices":[{"delta":{"content":"3 dias."}}]}',
            'data: {"choices":[{"delta":{},"finish_reason":"stop"}]}',
            'data: {"choices":[],"usage":{"prompt_tokens":40,"completion_tokens":6}}',
            'data: [DONE]',
        ])."\n\n";
    }

    /** @param  list<string>  $hitIds  point ids the fake vector store returns, whatever the tenant */
    private function responder(array $hitIds, bool $storeFails = false): ChatResponder
    {
        $this->searches = [];
        $test = $this;

        $embeddings = new class implements EmbeddingClient
        {
            public ?string $query = null;

            public function embedPassages(array $texts): array
            {
                return array_map(fn () => [0.1], $texts);
            }

            public function embedQuery(string $text): array
            {
                $this->query = $text;

                return [0.1, 0.2];
            }
        };

        $vectors = new class($hitIds, $storeFails, function (...$args) use ($test) {
            $test->searches[] = $args;
        }) implements VectorStore
        {

            public function __construct(private array $ids, private bool $fails, private \Closure $record) {}

            public function ensureCollection(): void {}

            public function upsert(array $points): void {}

            public function search(string $chatbotId, array $vector, int $limit, ?float $scoreThreshold = null): array
            {
                ($this->record)($chatbotId, $limit, $scoreThreshold);

                if ($this->fails) {
                    throw new RuntimeException('qdrant down');
                }

                return array_map(fn ($id) => ['id' => $id, 'score' => 0.9, 'payload' => []], $this->ids);
            }

            public function deleteBySource(string $chatbotId, string $sourceId): void {}

            public function deleteByChatbot(string $chatbotId): void {}
        };

        return new ChatResponder($embeddings, $vectors, app(LlmClientFactory::class), new PromptBuilder(historyLimit: 10), topK: 6, scoreThreshold: 0.5, historyLimit: 10);
    }

    private function conversation(?Chatbot $chatbot = null): Conversation
    {
        $chatbot ??= Chatbot::factory()->withApiKey()->create(['provider' => LlmProvider::OpenAi]);

        return Conversation::factory()->for($chatbot)->create();
    }

    private function chunkFor(Chatbot $chatbot, string $content): KnowledgeChunk
    {
        $source = KnowledgeSource::factory()->for($chatbot)->create();

        return KnowledgeChunk::factory()->create([
            'knowledge_source_id' => $source->id,
            'chatbot_id' => $chatbot->id,
            'content' => $content,
        ]);
    }

    public function test_end_to_end_streams_persists_reply_and_searches_with_the_chatbot_ulid(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->sse())]);

        $conversation = $this->conversation();
        $chatbot = $conversation->chatbot;
        $chunk = $this->chunkFor($chatbot, 'O prazo de entrega é de 3 dias úteis.');
        $user = Message::factory()->for($conversation)->create(['content' => 'Qual o prazo de entrega?']);

        $tokens = [];
        $reply = $this->responder([$chunk->id])->respond($conversation, $user, function (string $t) use (&$tokens) {
            $tokens[] = $t;
        });

        $this->assertSame([[$chatbot->ulid, 6, 0.5]], $this->searches);
        $this->assertSame(['Em ', '3 dias.'], $tokens);

        $this->assertSame(MessageRole::Assistant, $reply->role);
        $this->assertSame('Em 3 dias.', $reply->content);
        $this->assertSame([40, 6], [$reply->tokens_in, $reply->tokens_out]);
        $this->assertSame([$chunk->id], $reply->sources);
        $this->assertDatabaseHas('messages', ['id' => $reply->id, 'conversation_id' => $conversation->id]);

        Http::assertSent(function (Request $r) {
            $messages = $r->data()['messages'];

            return str_contains($messages[0]['content'], 'O prazo de entrega é de 3 dias úteis.')
                && str_contains($messages[0]['content'], 'Você é um assistente prestativo.')
                && end($messages) === ['role' => 'user', 'content' => 'Qual o prazo de entrega?'];
        });
    }

    public function test_a_chunk_from_another_chatbot_returned_by_the_store_never_reaches_the_prompt(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->sse())]);

        $conversation = $this->conversation();
        $own = $this->chunkFor($conversation->chatbot, 'Conteúdo legítimo do chatbot.');
        $foreign = $this->chunkFor(Chatbot::factory()->create(), 'SEGREDO DE OUTRO CLIENTE');
        $user = Message::factory()->for($conversation)->create(['content' => 'Oi']);

        $reply = $this->responder([$foreign->id, $own->id])->respond($conversation, $user, fn () => null);

        $this->assertSame([$own->id], $reply->sources);
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], 'Conteúdo legítimo')
            && ! str_contains($r['messages'][0]['content'], 'SEGREDO DE OUTRO CLIENTE')
            && ! str_contains($r['messages'][0]['content'], $foreign->id));
    }

    public function test_retrieval_failure_continues_without_context(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->sse())]);

        $conversation = $this->conversation();
        $user = Message::factory()->for($conversation)->create(['content' => 'Oi']);

        $reply = $this->responder([], storeFails: true)->respond($conversation, $user, fn () => null);

        $this->assertSame('Em 3 dias.', $reply->content);
        $this->assertSame([], $reply->sources);
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], 'no documents were found'));
    }

    public function test_history_is_limited_and_ordered(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->sse())]);

        $conversation = $this->conversation();
        foreach (range(1, 14) as $i) {
            Message::factory()->for($conversation)->create([
                'role' => $i % 2 === 1 ? MessageRole::User : MessageRole::Assistant,
                'content' => "msg{$i}",
            ]);
        }
        $user = Message::factory()->for($conversation)->create(['content' => 'pergunta final']);

        $this->responder([])->respond($conversation, $user, fn () => null);

        Http::assertSent(function (Request $r) {
            $history = array_slice($r->data()['messages'], 1);

            return count($history) <= 10
                && end($history)['content'] === 'pergunta final'
                && $history[0]['role'] === 'user';
        });
    }

    public function test_missing_api_key_fails_before_any_search(): void
    {
        config(['llm.providers.openai.api_key' => null]);
        Http::fake();

        $conversation = $this->conversation(Chatbot::factory()->create(['provider' => LlmProvider::OpenAi, 'api_key' => null]));
        $user = Message::factory()->for($conversation)->create();
        $responder = $this->responder([]);

        try {
            $responder->respond($conversation, $user, fn () => null);
            $this->fail('Expected exception');
        } catch (MissingApiKeyException) {
            $this->assertSame([], $this->searches);
            Http::assertNothingSent();
        }
    }
}
