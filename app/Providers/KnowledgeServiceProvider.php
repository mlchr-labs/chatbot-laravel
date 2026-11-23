<?php

namespace App\Providers;

use App\Contracts\EmbeddingClient;
use App\Contracts\VectorStore;
use App\Services\Embeddings\TeiEmbeddingClient;
use App\Services\VectorStore\QdrantVectorStore;
use App\Support\TextChunker;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\ServiceProvider;

class KnowledgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmbeddingClient::class, fn () => new TeiEmbeddingClient(
            url: (string) config('services.embeddings.url'),
            dimensions: (int) config('services.embeddings.dimensions'),
            batchSize: (int) config('knowledge.embed_batch_size'),
        ));

        $this->app->singleton(VectorStore::class, fn ($app) => new QdrantVectorStore(
            url: (string) config('services.qdrant.url'),
            apiKey: config('services.qdrant.api_key'),
            collection: (string) config('services.qdrant.collection'),
            dimensions: (int) config('services.embeddings.dimensions'),
            cache: $app->make(Repository::class),
        ));

        $this->app->bind(TextChunker::class, fn () => new TextChunker(
            targetSize: (int) config('knowledge.chunk_size'),
            overlap: (int) config('knowledge.chunk_overlap'),
        ));
    }
}
