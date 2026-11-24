<?php

namespace App\Actions\Knowledge;

use App\Contracts\VectorStore;
use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Storage;

/**
 * Removes everything the pipeline derived from a source (vectors, chunk rows, extracted text)
 * and leaves the uploaded file and the source row alone. Safe to run repeatedly.
 */
class PurgeKnowledgeIndex
{
    public function __construct(private readonly VectorStore $store) {}

    public function __invoke(KnowledgeSource $source): void
    {
        // Vectors first: if this throws, the chunk rows still describe what is in the store.
        $this->store->deleteBySource($source->chatbot->ulid, $source->ulid);

        Storage::disk((string) config('knowledge.disk'))->delete($source->extractedTextPath());

        $source->chunks()->delete();
        $source->update(['chunks_count' => 0]);
    }
}
