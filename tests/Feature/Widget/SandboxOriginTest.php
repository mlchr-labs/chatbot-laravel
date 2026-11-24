<?php

namespace Tests\Feature\Widget;

class SandboxOriginTest extends WidgetTestCase
{
    private const APP_ORIGIN = 'https://app.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::APP_ORIGIN]);
    }

    private function appChatbot(bool $sandbox)
    {
        $chatbot = $this->chatbot(['allowed_domains' => []]);
        $chatbot->forceFill(['sandbox_enabled' => $sandbox])->save();

        return $chatbot;
    }

    public function test_app_origin_opens_a_session_only_when_the_sandbox_is_enabled(): void
    {
        $on = $this->appChatbot(true);
        $off = $this->appChatbot(false);

        $this->postJson('/api/widget/sessions', ['public_key' => $on->public_key], ['Origin' => self::APP_ORIGIN])->assertOk();
        $this->postJson('/api/widget/sessions', ['public_key' => $off->public_key], ['Origin' => self::APP_ORIGIN])->assertForbidden();
    }

    public function test_same_origin_config_request_without_origin_header_works_when_enabled(): void
    {
        $chatbot = $this->appChatbot(true);

        $this->getJson('/api/widget/config/'.$chatbot->public_key, ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
        $this->getJson('/api/widget/config/'.$chatbot->public_key)->assertForbidden();
    }

    public function test_foreign_origin_is_still_denied_with_the_sandbox_enabled(): void
    {
        $chatbot = $this->appChatbot(true);

        $this->postJson('/api/widget/sessions', ['public_key' => $chatbot->public_key], ['Origin' => 'https://evil.example.com'])
            ->assertForbidden();
    }
}
