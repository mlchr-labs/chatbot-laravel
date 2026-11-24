<?php

namespace Tests\Unit;

use App\Enums\KnowledgeFailureReason;
use App\Exceptions\KnowledgeProcessingException;
use App\Support\KnowledgeFailureClassifier;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Knowledge\FakeEmbeddingClient;
use Tests\Support\Knowledge\FakeVectorStore;

class KnowledgeFailureClassifierTest extends TestCase
{
    private function classify(\Throwable $e): KnowledgeFailureReason
    {
        return (new KnowledgeFailureClassifier)->classify($e);
    }

    public function test_connection_error_from_the_embedding_client_is_embedding_unavailable(): void
    {
        $client = new FakeEmbeddingClient;
        $client->down = true;

        try {
            $client->embedPassages(['a']);
            $this->fail('Expected a connection exception.');
        } catch (ConnectionException $e) {
            $this->assertSame(KnowledgeFailureReason::EmbeddingUnavailable, $this->classify($e));
        }
    }

    public function test_connection_error_from_the_vector_store_is_vector_store_unavailable(): void
    {
        $store = new FakeVectorStore;
        $store->down = true;

        try {
            $store->ensureCollection();
            $this->fail('Expected a connection exception.');
        } catch (ConnectionException $e) {
            $this->assertSame(KnowledgeFailureReason::VectorStoreUnavailable, $this->classify($e));
        }
    }

    public function test_connection_error_outside_both_backends_is_unknown(): void
    {
        $this->assertSame(KnowledgeFailureReason::Unknown, $this->classify(new ConnectionException('cURL error 6')));
    }

    public function test_server_errors_count_as_unavailability_but_client_errors_do_not(): void
    {
        $embeddings = new class extends FakeEmbeddingClient
        {
            public int $status = 500;

            public function embedPassages(array $texts): array
            {
                // Built here so the stack trace goes through an EmbeddingClient, like the real one.
                throw new RequestException(new Response(new PsrResponse($this->status)));
            }
        };

        $capture = function (int $status) use ($embeddings): \Throwable {
            $embeddings->status = $status;
            try {
                $embeddings->embedPassages(['a']);
            } catch (\Throwable $e) {
                return $e;
            }
        };

        $this->assertSame(KnowledgeFailureReason::EmbeddingUnavailable, $this->classify($capture(503)));
        $this->assertSame(KnowledgeFailureReason::EmbeddingUnavailable, $this->classify($capture(429)));
        $this->assertSame(KnowledgeFailureReason::Unknown, $this->classify($capture(400)));
    }

    public function test_processing_exceptions_carry_their_own_reason(): void
    {
        foreach ([
            KnowledgeFailureReason::EmptyDocument,
            KnowledgeFailureReason::UnsupportedFile,
            KnowledgeFailureReason::ExtractionFailed,
            KnowledgeFailureReason::DocumentTooLarge,
        ] as $reason) {
            $this->assertSame($reason, $this->classify(new KnowledgeProcessingException('x', $reason)));
        }
    }

    public function test_reason_is_found_through_the_previous_chain(): void
    {
        $wrapped = new RuntimeException('outer', 0, new KnowledgeProcessingException('inner', KnowledgeFailureReason::EmptyDocument));

        $this->assertSame(KnowledgeFailureReason::EmptyDocument, $this->classify($wrapped));
    }

    public function test_anything_else_is_unknown(): void
    {
        $this->assertSame(KnowledgeFailureReason::Unknown, $this->classify(new RuntimeException('boom')));
        $this->assertSame(KnowledgeFailureReason::Unknown, $this->classify(new KnowledgeProcessingException('no reason')));
    }

    public function test_every_reason_has_a_message_without_internal_details(): void
    {
        foreach (KnowledgeFailureReason::cases() as $reason) {
            $this->assertNotSame('', $reason->message());
            $this->assertDoesNotMatchRegularExpression('#webapp_|https?://|/var/|cURL#i', $reason->message());
        }
    }
}
