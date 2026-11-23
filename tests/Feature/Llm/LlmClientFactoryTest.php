<?php

namespace Tests\Feature\Llm;

use App\Enums\LlmProvider;
use App\Models\Chatbot;
use App\Services\LLM\Exceptions\MissingApiKeyException;
use App\Services\LLM\Exceptions\ModelNotAllowedException;
use App\Services\LLM\LlmClientFactory;
use App\Services\LLM\LlmRequest;
use App\Services\LLM\Providers\AnthropicClient;
use App\Services\LLM\Providers\GeminiClient;
use App\Services\LLM\Providers\OpenAiCompatibleClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmClientFactoryTest extends TestCase
{
    private function chatbot(LlmProvider $provider, ?string $key = null): Chatbot
    {
        return (new Chatbot)->forceFill(['provider' => $provider, 'api_key' => $key]);
    }

    public function test_resolves_the_client_for_each_provider(): void
    {
        config(['llm.providers.openai.api_key' => 'k', 'llm.providers.groq.api_key' => 'k',
            'llm.providers.deepseek.api_key' => 'k', 'llm.providers.anthropic.api_key' => 'k',
            'llm.providers.gemini.api_key' => 'k']);

        $factory = app(LlmClientFactory::class);

        foreach ([LlmProvider::OpenAi, LlmProvider::Groq, LlmProvider::DeepSeek] as $p) {
            $this->assertInstanceOf(OpenAiCompatibleClient::class, $factory->for($this->chatbot($p)));
        }
        $this->assertInstanceOf(AnthropicClient::class, $factory->for($this->chatbot(LlmProvider::Anthropic)));
        $this->assertInstanceOf(GeminiClient::class, $factory->for($this->chatbot(LlmProvider::Gemini)));
    }

    public function test_chatbot_key_wins_over_platform_key_and_platform_key_is_the_fallback(): void
    {
        config(['llm.providers.openai.api_key' => 'platform-key']);
        Http::fake(['*' => Http::response("data: [DONE]\n\n")]);
        $request = new LlmRequest(null, 's', [['role' => 'user', 'content' => 'x']]);

        app(LlmClientFactory::class)->for($this->chatbot(LlmProvider::OpenAi, 'byok-key'))->stream($request, fn () => null);
        app(LlmClientFactory::class)->for($this->chatbot(LlmProvider::OpenAi))->stream($request, fn () => null);

        $auth = [];
        Http::assertSent(function (Request $r) use (&$auth) {
            $auth[] = $r->header('Authorization')[0];

            return true;
        });
        $this->assertSame(['Bearer byok-key', 'Bearer platform-key'], $auth);
    }

    public function test_throws_a_domain_exception_without_any_key(): void
    {
        config(['llm.providers.anthropic.api_key' => null]);

        $this->expectException(MissingApiKeyException::class);

        app(LlmClientFactory::class)->for($this->chatbot(LlmProvider::Anthropic));
    }

    public function test_platform_key_rejects_a_model_outside_the_allowlist(): void
    {
        config(['llm.providers.openai.api_key' => 'platform-key', 'llm.platform_models.openai' => ['gpt-6-luna']]);
        $chatbot = $this->chatbot(LlmProvider::OpenAi)->forceFill(['model' => 'gpt-expensive']);

        try {
            app(LlmClientFactory::class)->for($chatbot);
            $this->fail('Expected the model to be refused.');
        } catch (ModelNotAllowedException $e) {
            // Handled by GenerateReply like a missing key ("not configured").
            $this->assertInstanceOf(MissingApiKeyException::class, $e);
        }

        $this->assertInstanceOf(
            OpenAiCompatibleClient::class,
            app(LlmClientFactory::class)->for($chatbot->forceFill(['model' => 'gpt-6-luna'])),
        );
    }

    public function test_platform_allowlist_defaults_to_the_provider_default_model(): void
    {
        config(['llm.providers.anthropic.api_key' => 'k', 'llm.providers.anthropic.model' => 'claude-default',
            'llm.platform_models.anthropic' => []]);

        $this->assertInstanceOf(
            AnthropicClient::class,
            app(LlmClientFactory::class)->for($this->chatbot(LlmProvider::Anthropic)->forceFill(['model' => 'claude-default'])),
        );

        $this->expectException(ModelNotAllowedException::class);
        app(LlmClientFactory::class)->for($this->chatbot(LlmProvider::Anthropic)->forceFill(['model' => 'other']));
    }

    public function test_byok_model_is_not_restricted_by_the_allowlist(): void
    {
        config(['llm.platform_models.openai' => ['gpt-6-luna']]);

        $client = app(LlmClientFactory::class)->for($this->chatbot(LlmProvider::OpenAi, 'my-key')->forceFill(['model' => 'anything-goes']));

        $this->assertInstanceOf(OpenAiCompatibleClient::class, $client);
    }
}
