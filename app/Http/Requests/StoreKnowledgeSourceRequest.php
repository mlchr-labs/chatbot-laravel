<?php

namespace App\Http\Requests;

use App\Enums\KnowledgeFileType;
use App\Enums\KnowledgeSourceStatus;
use App\Models\Chatbot;
use App\Models\KnowledgeSource;
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

                        return;
                    }

                    $this->assertWithinUserCaps($value, $fail);
                },
            ],
        ];
    }

    /** Caps per owner, across all of their chatbots. Sources being deleted no longer count. */
    private function assertWithinUserCaps(UploadedFile $file, \Closure $fail): void
    {
        $chatbot = $this->route('chatbot');
        if (! $chatbot instanceof Chatbot) {
            return;
        }

        $owned = KnowledgeSource::query()
            ->where('user_id', $chatbot->user_id)
            ->where('status', '!=', KnowledgeSourceStatus::Deleting->value);

        $maxSources = (int) config('plans.max_knowledge_sources_per_user');
        if ((clone $owned)->count() >= $maxSources) {
            $fail("Você atingiu o limite de {$maxSources} documentos. Exclua algum para enviar outro.");

            return;
        }

        $maxBytes = (int) config('plans.max_knowledge_bytes_per_user');
        if ((int) (clone $owned)->sum('size') + (int) $file->getSize() > $maxBytes) {
            $fail('Você atingiu o limite de armazenamento de documentos ('.self::formatBytes($maxBytes).'). Exclua algum para enviar outro.');
        }
    }

    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, ',', '.'), '0'), ',').' MB'
            : max(1, intdiv($bytes, 1024)).' KB';
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
