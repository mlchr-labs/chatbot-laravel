<?php

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:'.(int) config('widget.max_message_length')],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Escreva uma mensagem.',
            'content.max' => 'A mensagem é longa demais (máximo de :max caracteres).',
        ];
    }
}
