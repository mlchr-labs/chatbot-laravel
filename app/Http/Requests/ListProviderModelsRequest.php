<?php

namespace App\Http\Requests;

use App\Enums\LlmProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProviderModelsRequest extends FormRequest
{
    /** Authorization of the saved key is done in the controller through the chatbot policy. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(LlmProvider::class)],
            'api_key' => ['nullable', 'string', 'max:500'],
            'chatbot' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider.required' => 'Escolha um provedor.',
            'provider.enum' => 'O provedor é inválido.',
            'api_key.max' => 'A chave de API é longa demais.',
        ];
    }
}
