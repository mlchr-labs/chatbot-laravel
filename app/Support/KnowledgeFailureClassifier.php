<?php

namespace App\Support;

use App\Contracts\EmbeddingClient;
use App\Contracts\VectorStore;
use App\Enums\KnowledgeFailureReason;
use App\Exceptions\KnowledgeProcessingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/** The single place that turns a pipeline exception into a failure reason. */
class KnowledgeFailureClassifier
{
    public function classify(Throwable $e): KnowledgeFailureReason
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof KnowledgeProcessingException && $current->reason !== null) {
                return $current->reason;
            }

            if ($this->isUnavailability($current)) {
                return $this->backendOf($current) ?? KnowledgeFailureReason::Unknown;
            }
        }

        return KnowledgeFailureReason::Unknown;
    }

    /** Connection errors, timeouts and server side errors. A 4xx means a bad request, not an outage. */
    private function isUnavailability(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status >= 500 || in_array($status, [408, 429], true);
        }

        return false;
    }

    /** Both backends fail with the same HTTP exceptions: the call stack tells which one was in use. */
    private function backendOf(Throwable $e): ?KnowledgeFailureReason
    {
        foreach ($e->getTrace() as $frame) {
            $class = $frame['class'] ?? null;

            if ($class === null) {
                continue;
            }

            if (is_a($class, EmbeddingClient::class, true)) {
                return KnowledgeFailureReason::EmbeddingUnavailable;
            }

            if (is_a($class, VectorStore::class, true)) {
                return KnowledgeFailureReason::VectorStoreUnavailable;
            }
        }

        return null;
    }
}
