<?php

namespace Tests\Unit;

use App\Support\HostnameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HostnameNormalizerTest extends TestCase
{
    public function test_it_trims_and_lowercases(): void
    {
        $this->assertSame('example.com', HostnameNormalizer::normalize("  ExAmple.COM \n"));
    }

    public function test_parse_list_splits_normalizes_and_deduplicates(): void
    {
        $result = HostnameNormalizer::parseList("Example.com\nwww.example.com, EXAMPLE.com\n\n  ");

        $this->assertSame(['example.com', 'www.example.com'], $result);
    }

    public function test_parse_list_accepts_arrays_and_null(): void
    {
        $this->assertSame(['a.com', 'b.com'], HostnameNormalizer::parseList([' A.com', 'b.com', '']));
        $this->assertSame([], HostnameNormalizer::parseList(null));
        $this->assertSame([], HostnameNormalizer::parseList(''));
    }

    #[DataProvider('validHosts')]
    public function test_valid_hostnames(string $host): void
    {
        $this->assertTrue(HostnameNormalizer::isValid($host));
    }

    #[DataProvider('invalidHosts')]
    public function test_invalid_hostnames(string $host): void
    {
        $this->assertFalse(HostnameNormalizer::isValid($host));
    }

    public static function validHosts(): array
    {
        return [
            'simple' => ['example.com'],
            'subdomain' => ['app.sub.example.com.br'],
            'localhost' => ['localhost'],
            'with port' => ['localhost:3000'],
            'hyphen' => ['my-site.com'],
        ];
    }

    public static function invalidHosts(): array
    {
        return [
            'scheme' => ['https://example.com'],
            'path' => ['example.com/path'],
            'scheme and path' => ['https://x.com/path'],
            'query' => ['example.com?a=1'],
            'wildcard' => ['*.example.com'],
            'leading hyphen' => ['-example.com'],
            'trailing dot label' => ['example..com'],
            'space' => ['exa mple.com'],
            'userinfo' => ['user@example.com'],
            'empty' => [''],
            'script' => ['<script>'],
        ];
    }
}
