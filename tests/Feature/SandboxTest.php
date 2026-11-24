<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_unknown_key_is_404(): void
    {
        $this->get('/sandbox/pk_does_not_exist')->assertNotFound();
    }

    public function test_disabled_sandbox_is_404_with_the_same_response_as_an_unknown_key(): void
    {
        $chatbot = Chatbot::factory()->create(['sandbox_enabled' => false]);

        $disabled = $this->get('/sandbox/'.$chatbot->public_key);
        $unknown = $this->get('/sandbox/pk_does_not_exist');

        $disabled->assertNotFound();
        $this->assertSame($unknown->getContent(), $disabled->getContent());
    }

    public function test_enabled_sandbox_renders_the_page_with_the_widget_snippet(): void
    {
        $chatbot = Chatbot::factory()->create(['name' => 'Atendimento', 'sandbox_enabled' => true]);

        $this->get('/sandbox/'.$chatbot->public_key)
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('Atendimento')
            ->assertSee($chatbot->widgetSnippet(), false)
            ->assertSee('data-chatbot="'.$chatbot->public_key.'"', false)
            ->assertDontSee($chatbot->system_prompt)
            ->assertDontSee($chatbot->welcome_message);
    }

    public function test_chatbot_name_is_escaped(): void
    {
        $chatbot = Chatbot::factory()->create(['name' => '<script>alert(1)</script>', 'sandbox_enabled' => true]);

        $this->get('/sandbox/'.$chatbot->public_key)
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_page_is_throttled(): void
    {
        config(['widget.limits.sandbox_page_per_minute_ip' => 2]);

        $this->get('/sandbox/pk_x')->assertNotFound();
        $this->get('/sandbox/pk_x')->assertNotFound();
        $this->get('/sandbox/pk_x')->assertTooManyRequests();
    }

    public function test_owner_can_toggle_the_sandbox(): void
    {
        $chatbot = Chatbot::factory()->for(User::factory())->create();

        $this->actingAs($chatbot->user)
            ->patch(route('chatbots.sandbox.toggle', $chatbot), ['sandbox_enabled' => 1])
            ->assertRedirect(route('chatbots.show', $chatbot).'#widget');
        $this->assertTrue($chatbot->fresh()->sandbox_enabled);

        $this->actingAs($chatbot->user)
            ->patch(route('chatbots.sandbox.toggle', $chatbot), ['sandbox_enabled' => 0])
            ->assertRedirect();
        $this->assertFalse($chatbot->fresh()->sandbox_enabled);
    }

    public function test_other_users_cannot_toggle_the_sandbox(): void
    {
        $chatbot = Chatbot::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('chatbots.sandbox.toggle', $chatbot), ['sandbox_enabled' => 1])
            ->assertNotFound();

        $this->assertFalse($chatbot->fresh()->sandbox_enabled);
    }

    public function test_guests_cannot_toggle_the_sandbox(): void
    {
        $chatbot = Chatbot::factory()->create();

        $this->patch(route('chatbots.sandbox.toggle', $chatbot), ['sandbox_enabled' => 1])
            ->assertRedirect(route('login'));

        $this->assertFalse($chatbot->fresh()->sandbox_enabled);
    }

    public function test_widget_tab_shows_the_link_only_when_enabled(): void
    {
        $chatbot = Chatbot::factory()->create();
        $url = route('sandbox.show', $chatbot->public_key);

        $this->actingAs($chatbot->user)->get(route('chatbots.show', $chatbot))
            ->assertOk()
            ->assertSee('Ligar sandbox')
            ->assertDontSee('Abrir sandbox');

        $chatbot->forceFill(['sandbox_enabled' => true])->save();

        $this->actingAs($chatbot->user)->get(route('chatbots.show', $chatbot))
            ->assertOk()
            ->assertSee('Desligar sandbox')
            ->assertSee('Abrir sandbox')
            ->assertSee($url, false);
    }

    public function test_chatbot_form_cannot_flip_the_sandbox(): void
    {
        $chatbot = Chatbot::factory()->create();

        $this->actingAs($chatbot->user)->put(route('chatbots.update', $chatbot), [
            'name' => 'Novo', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'temperature' => 0.5,
            'sandbox_enabled' => 1,
        ]);

        $this->assertFalse($chatbot->fresh()->sandbox_enabled);
    }
}
