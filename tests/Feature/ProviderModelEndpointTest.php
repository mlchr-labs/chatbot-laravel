<?php

namespace Tests\Feature;

use App\Enums\LlmProvider;
use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ProviderModelEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const GROQ = 'https://api.groq.com/openai/v1/models';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();
        $this->user = User::factory()->create();
    }

    private function fakeGroq(): void
    {
        Http::fake([self::GROQ => Http::response(['object' => 'list', 'data' => [['id' => 'llama-a'], ['id' => 'llama-b']]])]);
    }

    public function test_guests_cannot_use_the_endpoint(): void
    {
        $this->postJson(route('chatbots.models'), ['provider' => 'groq', 'api_key' => 'k'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_lists_models_with_a_typed_key_and_never_echoes_it(): void
    {
        $this->fakeGroq();

        $response = $this->actingAs($this->user)
            ->postJson(route('chatbots.models'), ['provider' => 'groq', 'api_key' => 'gsk-typed-secret'])
            ->assertOk()
            ->assertExactJson(['models' => ['llama-a', 'llama-b']]);

        $this->assertStringNotContainsString('gsk-typed-secret', $response->getContent());
        Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer gsk-typed-secret'] && ! str_contains($r->url(), 'gsk-typed-secret'));
    }

    public function test_the_endpoint_does_not_accept_get(): void
    {
        $this->actingAs($this->user)->get('/chatbots/models?provider=groq&api_key=k')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_validates_provider_and_requires_some_key(): void
    {
        $this->actingAs($this->user)->postJson(route('chatbots.models'), ['provider' => 'nope', 'api_key' => 'k'])
            ->assertUnprocessable()->assertJsonValidationErrors('provider');

        $this->actingAs($this->user)->postJson(route('chatbots.models'), ['provider' => 'groq'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Informe a chave de API para carregar os modelos.');

        Http::assertNothingSent();
    }

    public function test_uses_the_saved_key_of_an_owned_chatbot_when_no_key_is_typed(): void
    {
        $this->fakeGroq();
        $chatbot = Chatbot::factory()->for($this->user)->withApiKey('gsk-saved-secret')->create(['provider' => LlmProvider::Groq]);

        $response = $this->actingAs($this->user)
            ->postJson(route('chatbots.models'), ['provider' => 'groq', 'chatbot' => $chatbot->ulid])
            ->assertOk()
            ->assertJsonPath('models.0', 'llama-a');

        $this->assertStringNotContainsString('gsk-saved-secret', $response->getContent());
        Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer gsk-saved-secret']);
    }

    public function test_a_typed_key_wins_over_the_saved_one(): void
    {
        $this->fakeGroq();
        $chatbot = Chatbot::factory()->for($this->user)->withApiKey('gsk-saved-secret')->create(['provider' => LlmProvider::Groq]);

        $this->actingAs($this->user)
            ->postJson(route('chatbots.models'), ['provider' => 'groq', 'api_key' => 'gsk-typed', 'chatbot' => $chatbot->ulid])
            ->assertOk();

        Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer gsk-typed']);
    }

    public function test_the_saved_key_of_someone_elses_chatbot_is_not_used(): void
    {
        $other = Chatbot::factory()->for(User::factory()->create())->withApiKey('gsk-victim')->create(['provider' => LlmProvider::Groq]);

        $this->actingAs($this->user)
            ->postJson(route('chatbots.models'), ['provider' => 'groq', 'chatbot' => $other->ulid])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_saved_key_is_never_sent_to_another_provider(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->withApiKey('gsk-saved-secret')->create(['provider' => LlmProvider::Groq]);

        $this->actingAs($this->user)
            ->postJson(route('chatbots.models'), ['provider' => 'openai', 'chatbot' => $chatbot->ulid])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A chave salva é de outro provedor. Digite a chave de API para carregar os modelos.');

        Http::assertNothingSent();
    }

    public function test_chatbot_without_a_saved_key_and_without_a_typed_key_is_rejected(): void
    {
        $chatbot = Chatbot::factory()->for($this->user)->create(['provider' => LlmProvider::Groq]);

        $this->actingAs($this->user)
            ->postJson(route('chatbots.models'), ['provider' => 'groq', 'chatbot' => $chatbot->ulid])
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_list_is_cached_per_provider_and_key(): void
    {
        $this->fakeGroq();

        foreach ([1, 2] as $_) {
            $this->actingAs($this->user)->postJson(route('chatbots.models'), ['provider' => 'groq', 'api_key' => 'gsk-one'])->assertOk();
        }
        Http::assertSentCount(1);

        $this->actingAs($this->user)->postJson(route('chatbots.models'), ['provider' => 'groq', 'api_key' => 'gsk-two'])->assertOk();
        Http::assertSentCount(2);
    }

    public function test_invalid_key_returns_a_friendly_message_and_is_not_cached(): void
    {
        Http::fake([self::GROQ => Http::sequence()
            ->push(['error' => ['message' => 'Invalid API Key gsk-bad']], 401)
            ->push(['data' => [['id' => 'llama-a']]])]);

        $response = $this->actingAs($this->user)->postJson(route('chatbots.models'), ['provider' => 'groq', 'api_key' => 'gsk-bad'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'O provedor de IA recusou a chave de API.']);

        $this->assertStringNotContainsString('gsk-bad', $response->getContent());

        $this->actingAs($this->user)->postJson(route('chatbots.models'), ['provider' => 'groq', 'api_key' => 'gsk-bad'])->assertOk();
    }

    public function test_provider_outage_and_timeout_return_a_friendly_message(): void
    {
        Http::fake([self::GROQ => Http::response('boom', 500)]);

        $this->actingAs($this->user)->postJson(route('chatbots.models'), ['provider' => 'groq', 'api_key' => 'k'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'O provedor de IA está indisponível no momento.');

        Http::fake(fn () => throw new ConnectionException('cURL error 28'));

        $this->actingAs($this->user)->postJson(route('chatbots.models'), ['provider' => 'groq', 'api_key' => 'k2'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Não foi possível conectar ao provedor de IA.');
    }

    public function test_the_route_is_throttled_per_user(): void
    {
        config(['llm.models_per_minute_user' => 2]);
        RateLimiter::clear('user:'.$this->user->id);
        $this->fakeGroq();

        $post = fn () => $this->actingAs($this->user)->postJson(route('chatbots.models'), ['provider' => 'groq', 'api_key' => 'k']);

        $post()->assertOk();
        $post()->assertOk();
        $post()->assertStatus(429)->assertJsonPath('message', 'Muitas consultas de modelos. Aguarde um instante e tente de novo.');
    }
}
