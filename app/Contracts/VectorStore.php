<?php

namespace App\Contracts;

/**
 * Tenant identifiers used by this contract:
 * - $chatbotId is the chatbot ULID (Chatbot::$ulid), stored in the payload as "chatbot_id".
 * - $sourceId is the knowledge source ULID (KnowledgeSource::$ulid), stored as "source_id".
 * - the point id and payload "chunk_id" are the KnowledgeChunk UUID.
 * Callers must always pass the same kind of value they wrote, or filters will not match.
 */
interface VectorStore
{
    /** Creates the collection and payload indexes when missing. Safe to call repeatedly. */
    public function ensureCollection(): void;

    /**
     * @param  list<array{id: string, vector: list<float>, payload: array{chatbot_id: string, source_id: string, chunk_id: string}}>  $points
     */
    public function upsert(array $points): void;

    /**
     * There is intentionally no unfiltered search: every query is scoped to one chatbot.
     *
     * @param  list<float>  $vector
     * @return list<array{id: string, score: float, payload: array<string, mixed>}>
     */
    public function search(string $chatbotId, array $vector, int $limit, ?float $scoreThreshold = null): array;

    public function deleteBySource(string $chatbotId, string $sourceId): void;
}
