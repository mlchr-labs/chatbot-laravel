<?php

namespace Tests\Unit;

use App\Enums\LlmProvider;
use App\Services\LLM\PlatformModels;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

class PlatformModelsCatalogTest extends TestCase
{
    public function test_catalog_has_default_and_allowed_models_for_every_provider(): void
    {
        $providers = [];
        $platform = [];
        foreach (LlmProvider::cases() as $p) {
            $providers[$p->value] = ['model' => $p->value.'-default'];
            $platform[$p->value] = [$p->value.'-default', $p->value.'-big'];
        }

        $catalog = (new PlatformModels(new Repository(['llm' => ['providers' => $providers, 'platform_models' => $platform]])))->catalog();

        $this->assertSame(array_map(fn ($p) => $p->value, LlmProvider::cases()), array_keys($catalog));
        $this->assertSame(['default' => 'groq-default', 'models' => ['groq-default', 'groq-big']], $catalog['groq']);
    }

    public function test_provider_without_a_platform_list_falls_back_to_its_default_model(): void
    {
        $platform = new PlatformModels(new Repository(['llm' => ['providers' => ['gemini' => ['model' => 'gem-1']]]]));

        $this->assertSame('gem-1', $platform->defaultFor(LlmProvider::Gemini));
        $this->assertSame(['default' => 'gem-1', 'models' => ['gem-1']], $platform->catalog()['gemini']);
    }
}
