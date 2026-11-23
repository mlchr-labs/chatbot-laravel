<?php

namespace Tests\Unit;

use App\Services\Widget\WidgetTokenService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WidgetTokenServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function service(string $key = 'base64:secret-a', int $ttl = 60): WidgetTokenService
    {
        return new WidgetTokenService($key, $ttl);
    }

    public function test_round_trip(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 10:00:00');
        $service = $this->service();

        $token = $service->issue('conv1', 'bot1', 'pk_test');
        $payload = $service->verify($token->value);

        $this->assertSame('conv1', $payload->conversationUlid);
        $this->assertSame('bot1', $payload->chatbotUlid);
        $this->assertSame(WidgetTokenService::keyVersion('pk_test'), $payload->keyVersion);
        $this->assertSame(16, strlen($payload->keyVersion));
        $this->assertNotSame('pk_test', $payload->keyVersion);
        $this->assertSame(CarbonImmutable::now()->addMinutes(60)->getTimestamp(), $payload->expiresAt);
        $this->assertTrue($token->expiresAt->equalTo(CarbonImmutable::now()->addMinutes(60)));
    }

    public function test_expired_token_is_rejected(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 10:00:00');
        $token = $this->service()->issue('c', 'b', 'pk_test');

        CarbonImmutable::setTestNow('2026-01-01 10:59:59');
        $this->assertNotNull($this->service()->verify($token->value));

        CarbonImmutable::setTestNow('2026-01-01 11:00:01');
        $this->assertNull($this->service()->verify($token->value));
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $service = $this->service();
        [, $signature] = explode('.', $service->issue('c', 'b', 'pk_test')->value);

        $forged = rtrim(strtr(base64_encode(json_encode(['c' => 'other', 'b' => 'b', 'exp' => time() + 3600])), '+/', '-_'), '=');

        $this->assertNull($service->verify($forged.'.'.$signature));
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $service = $this->service();
        $token = $service->issue('c', 'b', 'pk_test')->value;

        $this->assertNull($service->verify($token.'x'));
        $this->assertNull($service->verify(substr($token, 0, -2).'AA'));
    }

    public function test_token_from_another_key_is_rejected(): void
    {
        $token = $this->service('key-a')->issue('c', 'b', 'pk_test')->value;

        $this->assertNull($this->service('key-b')->verify($token));
    }

    #[DataProvider('garbage')]
    public function test_garbage_is_rejected(?string $value): void
    {
        $this->assertNull($this->service()->verify($value));
    }

    public static function garbage(): array
    {
        return [[null], [''], ['abc'], ['a.b'], ['a.b.c'], [str_repeat('a', 2000)], ['....']];
    }
}
