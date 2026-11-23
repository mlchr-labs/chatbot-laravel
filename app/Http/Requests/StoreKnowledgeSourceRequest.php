<?php

namespace App\Http\Requests;

use App\Enums\KnowledgeFileType;
use App\Models\Chatbot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\File;

class StoreKnowledgeSourceRequest extends FormRequest
{
    private ?KnowledgeFileType $detectedType = null;

    public function authorize(): bool
    {
        // Ownership first, so a foreign chatbot gets 404 before any validation feedback.
        $chatbot = $this->route('chatbot');

        if ($chatbot instanceof Chatbot) {
            Gate::authorize('update', $chatbot);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(KnowledgeFileType::extensions())->max((int) config('knowledge.max_upload_kb')),
                // The extension and Content-Type are client controlled: check the real content too.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $this->detectedType = $value instanceof UploadedFile
                        ? KnowledgeFileType::detect($value)
                        : null;

                    if ($this->detectedType === null) {
                        $fail('O conteúdo do arquivo não corresponde a um PDF, TXT, MD ou HTML válido.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Selecione um arquivo.',
            'file.max' => 'O arquivo não pode passar de 16 MB.',
            'file.mimes' => 'Envie um arquivo PDF, TXT, MD ou HTML.',
            'file.uploaded' => 'Falha no envio do arquivo. Verifique o tamanho (máximo 16 MB).',
        ];
    }

    public function fileType(): KnowledgeFileType
    {
        return $this->detectedType ?? throw new \LogicException('Request was not validated.');
    }
}
