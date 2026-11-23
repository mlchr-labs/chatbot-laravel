<?php

namespace Tests\Feature\Widget;

use App\Http\Controllers\Widget\MessageController;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Widget\WidgetTokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class SessionTest extends WidgetTestCase
{
    private function open(array $body, ?string $origin = self::ORIGIN)
    {
        return $this->postJson('/api/widget/sessions', $body, $origin ? ['Origin' => $origin] : []);
    }

    public function test_creates_a_conversation_and_returns_the_contract(): void
    {
        $chatbot = $this->chatbot();

        $response = $this->open(['public_key' => $chatbot->public_key, 'visitor_id' => 'abc_123'])
            ->assertOk()
            ->assertJsonStructure([
                'conversation_ulid', 'visitor_id', 'widget_token', 'expires_at',
                'reverb' => ['key', 'host', 'port', 'scheme'], 'channel', 'auth_endpoint', 'messages',
            ])
            ->assertJsonPath('visitor_id', 'abc_123')
            ->assertJsonPath('reverb', ['key' => 'test-key', 'host' => 'ws.example.com', 'port' => 8090, 'scheme' => 'https'])
            ->assertJsonPath('messages', []);

        $ulid = $response->json('conversation_ulid');
        $this->assertSame('widget.conversation.'.$ulid, $response->json('channel'));

        $conversation = Conversation::where('ulid', $ulid)->firstOrFail();
        $this->assertSame($chatbot->id, $conversation->chatbot_id);
        $this->assertSame('site.example.com', $conversation->origin);
        $this->assertSame(64, strlen($conversation->ip_hash));
        $this->assertNotSame('127.0.0.1', $conversation->ip_hash);
    }

    public function test_generates_a_visitor_id_when_missing(): void
    {
        $chatbot = $this->chatbot();

        $this->open(['public_key' => $chatbot->public_key])
            ->assertOk()
            ->assertJsonPath('visitor_id', fn ($v) => is_string($v) && strlen($v) === 36);
    }

    public function test_resumes_own_conversation_with_last_20_messages(): void
    {
        $chatbot = $this->chatbot();
        $conversation = Conversation::factory()->create(['chatbot_id' => $chatbot->id, 'visitor_id' => 'v1']);
        Message::factory()->count(25)->create(['conversation_id' => $conversation->id]);
        $last = Message::factory()->assistant()->create(['conversation_id' => $conversation->id, 'content' => 'ultima']);

        $response = $this->open([
            'public_key' => $chatbot->public_key,
            'conversation_ulid' => strtoupper($conversation->ulid),
            'visitor_id' => 'v1',
        ])->assertOk();

        $this->assertSame($conversation->ulid, $response->json('conversation_ulid'));
        $this->assertCount(20, $response->json('messages'));
        $this->assertSame('ultima', $response->json('messages.19.content'));
        $this->assertSame($last->ulid, $response->json('messages.19.id'));
        $this->assertSame('assistant', $response->json('messages.19.role'));
        $this->assertSame(['id', 'role', 'content', 'created_at'], array_keys($response->json('messages.0')));
        $this->assertSame(1, Conversation::count());
    }

    public function test_does_not_resume_with_a_different_visitor_id(): void
    {
        $chatbot = $this->chatbot();
        $conversation = Conversation::factory()->create(['chatbot_id' => $chatbot->id, 'visitor_id' => 'v1']);
        Message::factory()->create(['conversation_id' => $conversation->id, 'content' => 'segredo']);

        $response = $this->open([
            'public_key' => $chatbot->public_key,
            'conversation_ulid' => $conversation->ulid,
            'visitor_id' => 'intruder',
        ])->assertOk();

        $this->assertNotSame($conversation->ulid, $response->json('conversation_ulid'));
        $this->assertSame([], $response->json('messages'));
    }

    public function test_does_not_resume_a_conversation_of_another_chatbot(): void
    {
        $mine = $this->chatbot();
        $other = $this->chatbot();
        $foreign = Conversation::factory()->create(['chatbot_id' => $other->id, 'visitor_id' => 'v1']);
        Message::factory()->create(['conversation_id' => $foreign->id, 'content' => 'segredo']);

        $response = $this->open([
            'public_key' => $mine->public_key,
            'conversation_ulid' => $foreign->ulid,
            'visitor_id' => 'v1',
        ])->assertOk();

        $this->assertNotSame($foreign->ulid, $response->json('conversation_ulid'));
        $this->assertSame([], $response->json('messages'));
        $this->assertStringNotContainsString('segredo', $response->getContent());
    }

    public function test_disallowed_origin_is_forbidden(): void
    {
        $chatbot = $this->chatbot();

        $this->open(['public_key' => $chatbot->public_key], 'https://evil.example.org')->assertForbidden();
        $this->assertSame(0, Conversation::count());
    }

    public function test_missing_origin_is_forbidden(): void
    {
        $chatbot = $this->chatbot();

        $this->open(['public_key' => $chatbot->public_key], null)->assertForbidden();
    }

    public function test_empty_allowed_domains_blocks_everything(): void
    {
        $chatbot = $this->chatbot(['allowed_domains' => []]);

        $this->open(['public_key' => $chatbot->public_key])->assertForbidden();
    }

    public function test_origin_port_must_match_the_allowed_entry(): void
    {
        $chatbot = $this->chatbot(['allowed_domains' => ['localhost:3000', 'site.example.com']]);

        $this->open(['public_key' => $chatbot->public_key], 'http://localhost:3000')->assertOk();
        $this->open(['public_key' => $chatbot->public_key], 'http://localhost:4000')->assertForbidden();
        $this->open(['public_key' => $chatbot->public_key], 'https://SITE.example.com:443')->assertOk();
        $this->open(['public_key' => $chatbot->public_key], 'https://site.example.com:8443')->assertForbidden();
        $this->open(['public_key' => $chatbot->public_key], 'https://site.example.com.evil.org')->assertForbidden();
        $this->open(['public_key' => $chatbot->public_key], 'null')->assertForbidden();
    }

    public function test_inactive_and_unknown_chatbots_are_both_404(): void
    {
        $inactive = $this->chatbot(['is_active' => false]);

        $this->open(['public_key' => $inactive->public_key])->assertNotFound();
        $this->open(['public_key' => 'pk_unknown'])->assertNotFound();
    }

    public function test_validates_input(): void
    {
        $this->open([])->assertStatus(422)->assertJsonValidationErrors('public_key');
        $this->open(['public_key' => 'pk_x', 'visitor_id' => 'bad id!'])->assertStatus(422)->assertJsonValidationErrors('visitor_id');
    }

    public function test_sessions_are_rate_limited_per_ip_with_a_pt_br_message(): void
    {
        config(['widget.limits.sessions_per_minute_ip' => 2]);
        $chatbot = $this->chatbot();

        $this->open(['public_key' => $chatbot->public_key])->assertOk();
        $this->open(['public_key' => $chatbot->public_key])->assertOk();
        $this->open(['public_key' => $chatbot->public_key])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Muitas conexões. Tente novamente em instantes.');
    }

    public function test_config_endpoint(): void
    {
        $chatbot = $this->chatbot(['name' => 'Ajuda', 'welcome_message' => 'Oi!', 'theme' => ['color' => '#123456']]);

        $this->getJson('/api/widget/config/'.$chatbot->public_key, ['Origin' => self::ORIGIN])
            ->assertOk()
            ->assertExactJson(['name' => 'Ajuda', 'welcome_message' => 'Oi!', 'theme' => ['color' => '#123456']]);

        $this->getJson('/api/widget/config/'.$chatbot->public_key, ['Origin' => 'https://evil.example.org'])->assertForbidden();
        $this->getJson('/api/widget/config/'.$chatbot->public_key)->assertForbidden();
        $this->getJson('/api/widget/config/pk_nope', ['Origin' => self::ORIGIN])->assertNotFound();

        $chatbot->update(['is_active' => false]);
        $this->getJson('/api/widget/config/'.$chatbot->public_key, ['Origin' => self::ORIGIN])->assertNotFound();
    }

    public function test_cors_headers_on_widget_paths_only(): void
    {
        $chatbot = $this->chatbot();

        $this->call('OPTIONS', '/api/widget/sessions', [], [], [], [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ])->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', '*');

        $this->open(['public_key' => $chatbot->public_key])->assertHeader('Access-Control-Allow-Origin', '*');

        $this->get('/login', ['Origin' => self::ORIGIN])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_origin_must_be_https_except_for_local_hosts(): void
    {
        $key = fn (array $domains) => $this->chatbot(['allowed_domains' => $domains])->public_key;

        // http on a real host is refused even when the host is allowed.
        $this->open(['public_key' => $key(['site.example.com'])], 'http://site.example.com')->assertForbidden();
        $this->open(['public_key' => $key(['site.example.com'])], 'https://site.example.com')->assertOk();

        // localhost and 127.0.0.1 may use http (or https).
        $this->open(['public_key' => $key(['localhost'])], 'http://localhost')->assertOk();
        $this->open(['public_key' => $key(['localhost:3000'])], 'http://localhost:3000')->assertOk();
        $this->open(['public_key' => $key(['127.0.0.1:8000'])], 'http://127.0.0.1:8000')->assertOk();
        $this->open(['public_key' => $key(['localhost'])], 'https://localhost')->assertOk();

        // Lookalikes are not local.
        $this->open(['public_key' => $key(['localhost.evil.com'])], 'http://localhost.evil.com')->assertForbidden();
        $this->open(['public_key' => $key(['127.0.0.1.nip.io'])], 'http://127.0.0.1.nip.io')->assertForbidden();
    }

    public function test_rotating_the_public_key_revokes_issued_tokens(): void
    {
        $chatbot = $this->chatbot();
        [$conversation, $token] = $this->conversationWithToken($chatbot);
        $post = fn () => $this->postJson(
            "/api/widget/conversations/{$conversation->ulid}/messages",
            ['content' => 'oi'],
            $this->headers($token),
        );
        Queue::fake();

        $post()->assertStatus(202);
        // The reply lock of the first message would otherwise answer 409.
        Cache::lock(MessageController::lockKey($conversation->id))->forceRelease();

        $chatbot->rotatePublicKey();

        $post()->assertUnauthorized();

        // A token issued after the rotation works.
        $fresh = app(WidgetTokenService::class)
            ->issue($conversation->ulid, $chatbot->ulid, $chatbot->fresh()->public_key)->value;
        $this->postJson("/api/widget/conversations/{$conversation->ulid}/messages", ['content' => 'oi'], $this->headers($fresh))
            ->assertStatus(202);
    }
}
