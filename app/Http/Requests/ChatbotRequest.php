<?php

namespace App\Http\Requests;

use App\Enums\LlmProvider;
use App\Support\HostnameNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared rules for creating and updating a chatbot.
 * Authorization is done in the controller through the policy.
 */
abstract class ChatbotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'allowed_domains' => HostnameNormalizer::parseList($this->input('allowed_domains')),
            'is_active' => $this->boolean('is_active'),
            'remove_api_key' => $this->boolean('remove_api_key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'system_prompt' => ['nullable', 'string', 'max:8000'],
            'provider' => ['required', Rule::enum(LlmProvider::class)],
            'model' => ['required', 'string', 'max:100'],
            'temperature' => ['required', 'numeric', 'between:0,2'],
            'welcome_message' => ['nullable', 'string', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'remove_api_key' => ['boolean'],
            'is_active' => ['boolean'],
            'allowed_domains' => ['array', 'max:20'],
            'allowed_domains.*' => [
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! HostnameNormalizer::isValid((string) $value)) {
                        $fail("O domínio \"{$value}\" é inválido. Informe só o host, sem https:// nem caminho.");
                    }
                },
            ],
        ];
    }

    /**
     * Attributes safe for mass assignment. user_id is never part of it.
     * An empty api_key keeps the current one; remove_api_key clears it.
     *
     * @return array<string, mixed>
     */
    public function chatbotAttributes(): array
    {
        $data = $this->safe()->except(['api_key', 'remove_api_key']);

        if ($this->boolean('remove_api_key')) {
            $data['api_key'] = null;
        } elseif (filled($this->validated('api_key'))) {
            $data['api_key'] = $this->validated('api_key');
        }

        return $data;
    }
}
