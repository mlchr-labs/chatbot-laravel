<?php

namespace Tests\Unit;

use App\Models\Chatbot;
use App\Services\Widget\OriginValidator;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OriginValidatorSandboxTest extends TestCase
{
    private function chatbot(bool $sandbox, array $domains = []): Chatbot
    {
        return (new Chatbot(['allowed_domains' => $domains]))->forceFill(['sandbox_enabled' => $sandbox]);
    }

    private function request(?string $origin, array $server = []): Request
    {
        $request = Request::create('/api/widget/config/x', 'GET', server: $server);
        if ($origin !== null) {
            $request->headers->set('Origin', $origin);
        }

        return $request;
    }

    public function test_app_origin_is_accepted_only_when_the_sandbox_is_enabled(): void
    {
        config(['app.url' => 'https://app.example.com']);
        $validator = new OriginValidator;

        $this->assertTrue($validator->allows($this->request('https://app.example.com'), $this->chatbot(true)));
        $this->assertFalse($validator->allows($this->request('https://app.example.com'), $this->chatbot(false)));
    }

    public function test_port_is_part_of_the_app_origin(): void
    {
        config(['app.url' => 'http://localhost:8080']);
        $validator = new OriginValidator;
        $chatbot = $this->chatbot(true);

        $this->assertTrue($validator->allows($this->request('http://localhost:8080'), $chatbot));
        $this->assertFalse($validator->allows($this->request('http://localhost:9999'), $chatbot));
        $this->assertFalse($validator->allows($this->request('http://localhost'), $chatbot));
    }

    #[DataProvider('foreignOrigins')]
    public function test_other_origins_stay_denied_with_the_sandbox_enabled(string $origin): void
    {
        config(['app.url' => 'https://app.example.com']);

        $this->assertFalse((new OriginValidator)->allows($this->request($origin), $this->chatbot(true)));
    }

    public static function foreignOrigins(): array
    {
        return [
            'other host' => ['https://evil.example.com'],
            'subdomain' => ['https://sub.app.example.com'],
            'suffix trick' => ['https://app.example.com.evil.test'],
            'other scheme' => ['http://app.example.com'],
            'opaque origin' => ['null'],
        ];
    }

    public function test_same_origin_get_without_origin_header_relies_on_sec_fetch_site(): void
    {
        config(['app.url' => 'https://app.example.com']);
        $validator = new OriginValidator;

        $this->assertTrue($validator->allows($this->request(null, ['HTTP_SEC_FETCH_SITE' => 'same-origin']), $this->chatbot(true)));
        $this->assertFalse($validator->allows($this->request(null, ['HTTP_SEC_FETCH_SITE' => 'cross-site']), $this->chatbot(true)));
        $this->assertFalse($validator->allows($this->request(null), $this->chatbot(true)));
        $this->assertFalse($validator->allows($this->request(null, ['HTTP_SEC_FETCH_SITE' => 'same-origin']), $this->chatbot(false)));
    }

    public function test_registered_domains_keep_working_with_or_without_the_sandbox(): void
    {
        config(['app.url' => 'https://app.example.com']);
        $validator = new OriginValidator;

        foreach ([true, false] as $sandbox) {
            $chatbot = $this->chatbot($sandbox, ['site.example.com']);

            $this->assertTrue($validator->allows($this->request('https://site.example.com'), $chatbot));
            $this->assertFalse($validator->allows($this->request('https://other.example.com'), $chatbot));
        }
    }
}
