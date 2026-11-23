<?php

namespace Tests\Feature\Idor;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\KnowledgeSource;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PanelIdorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $intruder;

    private Chatbot $chatbot;

    private Conversation $conversation;

    private KnowledgeSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Bus::fake();
        Storage::fake('local');

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
        $this->chatbot = Chatbot::factory()->for($this->owner)->withApiKey()->create(['name' => 'Original']);
        $this->conversation = Conversation::factory()->for($this->chatbot)->create();
        Message::factory()->for($this->conversation)->create(['content' => 'segredo do cliente']);
        $this->source = KnowledgeSource::factory()->for($this->chatbot)->create(['original_name' => 'contrato-secreto.pdf']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function panelRoutes(): array
    {
        return [
            'show' => ['get', 'chatbots.show', ['chatbot']],
            'edit' => ['get', 'chatbots.edit', ['chatbot']],
            'update' => ['put', 'chatbots.update', ['chatbot']],
            'destroy' => ['delete', 'chatbots.destroy', ['chatbot']],
            'rotate key' => ['post', 'chatbots.rotate-key', ['chatbot']],
            'conversations index' => ['get', 'chatbots.conversations.index', ['chatbot']],
            'conversations show' => ['get', 'chatbots.conversations.show', ['chatbot', 'conversation']],
            'sources status' => ['get', 'chatbots.sources.index', ['chatbot']],
            'sources upload' => ['post', 'chatbots.sources.store', ['chatbot']],
            'sources destroy' => ['delete', 'chatbots.sources.destroy', ['chatbot', 'source']],
        ];
    }

    #[DataProvider('panelRoutes')]
    public function test_another_user_cannot_reach_the_resource(string $method, string $route, array $params): void
    {
        $url = route($route, array_map(fn (string $p) => $this->{$p}, $params));
        $publicKey = $this->chatbot->public_key;

        $response = $this->actingAs($this->intruder)->{$method}($url, [
            'name' => 'Hacked',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.5,
        ]);

        $response->assertNotFound();
        $response->assertDontSee('segredo do cliente', false);
        $response->assertDontSee('contrato-secreto.pdf', false);

        $fresh = $this->chatbot->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Original', $fresh->name);
        $this->assertSame($publicKey, $fresh->public_key);
        $this->assertSame($this->owner->id, $fresh->user_id);
        $this->assertSame('sk-test-secret-key', $fresh->api_key);
        $this->assertDatabaseHas('conversations', ['id' => $this->conversation->id]);
        $this->assertDatabaseHas('knowledge_sources', ['id' => $this->source->id, 'status' => 'pending']);
        $this->assertDatabaseCount('knowledge_sources', 1);
    }

    #[DataProvider('panelRoutes')]
    public function test_guests_are_redirected_to_login(string $method, string $route, array $params): void
    {
        $url = route($route, array_map(fn (string $p) => $this->{$p}, $params));

        $this->{$method}($url)->assertRedirect(route('login'));
    }

    #[DataProvider('panelRoutes')]
    public function test_owner_is_not_blocked(string $method, string $route, array $params): void
    {
        $url = route($route, array_map(fn (string $p) => $this->{$p}, $params));

        $response = $this->actingAs($this->owner)->{$method}($url, [
            'name' => 'Original',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.5,
        ]);

        $this->assertNotContains($response->getStatusCode(), [403, 404]);
    }

    public function test_conversation_of_another_chatbot_is_404_under_nested_binding(): void
    {
        $other = Chatbot::factory()->for($this->owner)->create();

        $this->actingAs($this->owner)
            ->get(route('chatbots.conversations.show', [$other, $this->conversation]))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->get(route('chatbots.conversations.show', [$this->chatbot, $this->conversation]))
            ->assertOk();
    }

    public function test_intruder_chatbot_with_foreign_conversation_is_404(): void
    {
        $mine = Chatbot::factory()->for($this->intruder)->create();

        $this->actingAs($this->intruder)
            ->get(route('chatbots.conversations.show', [$mine, $this->conversation]))
            ->assertNotFound();
    }

    public function test_index_only_lists_own_chatbots(): void
    {
        Chatbot::factory()->for($this->intruder)->create(['name' => 'Do intruso']);

        $this->actingAs($this->intruder)
            ->get(route('chatbots.index'))
            ->assertOk()
            ->assertSee('Do intruso')
            ->assertDontSee('Original')
            ->assertDontSee($this->chatbot->ulid);
    }

    public function test_route_keys_are_ulids_not_sequential_ids(): void
    {
        $this->assertSame('ulid', $this->chatbot->getRouteKeyName());
        $this->assertStringContainsString($this->chatbot->ulid, route('chatbots.show', $this->chatbot));

        $this->actingAs($this->owner)
            ->get('/chatbots/'.$this->chatbot->id)
            ->assertNotFound();
    }
}
