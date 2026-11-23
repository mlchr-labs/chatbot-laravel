<?php

namespace App\Jobs\Knowledge;

use App\Enums\KnowledgeSourceStatus;
use App\Exceptions\KnowledgeProcessingException;
use App\Models\KnowledgeChunk;
use App\Support\TextChunker;
use Illuminate\Support\Facades\DB;

class ChunkKnowledgeText extends KnowledgeSourceJob
{
    public int $tries = 1;

    public int $timeout = 120;

    public function handle(TextChunker $chunker): void
    {
        if ($this->isBeingDeleted()) {
            return;
        }

        $path = $this->source->extractedTextPath();
        $text = $this->disk()->get($path);

        if ($text === null) {
            throw new KnowledgeProcessingException('O texto extraído não foi encontrado.');
        }

        $pieces = $chunker->chunk($text);

        if ($pieces === []) {
            throw new KnowledgeProcessingException('Nenhum conteúdo utilizável foi encontrado no documento.');
        }

        $template = new KnowledgeChunk;

        DB::transaction(function () use ($pieces, $template): void {
            // Makes the step idempotent if it ever runs twice.
            $this->source->chunks()->delete();

            foreach (array_chunk($pieces, 200, preserve_keys: true) as $batch) {
                KnowledgeChunk::query()->insert(array_map(fn (string $content, int $position) => [
                    'id' => $template->newUniqueId(),
                    'knowledge_source_id' => $this->source->id,
                    'chatbot_id' => $this->source->chatbot_id,
                    'position' => $position,
                    'content' => $content,
                    'embedded_at' => null,
                ], $batch, array_keys($batch)));
            }

            $this->source->update([
                'status' => KnowledgeSourceStatus::Embedding,
                'chunks_count' => count($pieces),
            ]);
        });

        $this->disk()->delete($path);
    }
}
