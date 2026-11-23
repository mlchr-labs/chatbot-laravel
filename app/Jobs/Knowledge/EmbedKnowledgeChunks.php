<?php

namespace App\Jobs\Knowledge;

use App\Contracts\EmbeddingClient;
use App\Contracts\VectorStore;
use App\Enums\KnowledgeSourceStatus;
use App\Models\KnowledgeChunk;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class EmbedKnowledgeChunks extends KnowledgeSourceJob
{
    public int $tries = 3;

    public int $timeout = 280;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(EmbeddingClient $embeddings, VectorStore $store): void
    {
        if ($this->isBeingDeleted()) {
            return;
        }

        $this->source->update(['status' => KnowledgeSourceStatus::Embedding]);

        $store->ensureCollection();

        $chatbotUlid = $this->source->chatbot->ulid;
        $batchSize = max(1, (int) config('knowledge.embed_batch_size'));

        // Only chunks without embedded_at: a retry resumes where the last attempt stopped.
        while (($chunks = $this->nextBatch($batchSize))->isNotEmpty()) {
            if ($this->isBeingDeleted()) {
                return;
            }

            $vectors = $embeddings->embedPassages($chunks->pluck('content')->all());

            if (count($vectors) !== $chunks->count()) {
                throw new RuntimeException('Embedding count does not match chunk count.');
            }

            $store->upsert($chunks->values()->map(fn (KnowledgeChunk $chunk, int $i) => [
                'id' => $chunk->id,
                'vector' => $vectors[$i],
                'payload' => [
                    'chatbot_id' => $chatbotUlid,
                    'source_id' => $this->source->ulid,
                    'chunk_id' => $chunk->id,
                ],
            ])->all());

            KnowledgeChunk::query()->whereKey($chunks->modelKeys())->update(['embedded_at' => now()]);
        }

        $this->source->update([
            'status' => KnowledgeSourceStatus::Indexed,
            'error' => null,
            'chunks_count' => $this->source->chunks()->count(),
        ]);
    }

    /** @return Collection<int, KnowledgeChunk> */
    private function nextBatch(int $size)
    {
        return $this->source->chunks()
            ->whereNull('embedded_at')
            ->orderBy('position')
            ->limit($size)
            ->get();
    }
}
