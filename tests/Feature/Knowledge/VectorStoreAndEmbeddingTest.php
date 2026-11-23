<?php

namespace Tests\Feature\Knowledge;

use App\Contracts\EmbeddingClient;
use App\Contracts\VectorStore;
use App\Services\Embeddings\TeiEmbeddingClient;
use App\Services\VectorStore\QdrantVectorStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class VectorStoreAndEmbeddingTest extends TestCase
{
    private function store(?string $apiKey = 'k'): QdrantVectorStore
    {
        return new QdrantVectorStore('http://qdrant.test:6333/', $apiKey, 'knowledge', 4, new Repository(new ArrayStore));
    }

    public function test_contracts_are_bound_to_the_implementations(): void
    {
        $this->assertInstanceOf(TeiEmbeddingClient::class, app(EmbeddingClient::class));
        $this->assertInstanceOf(QdrantVectorStore::class, app(VectorStore::class));
    }

    public function test_search_always_sends_the_chatbot_filter(): void
    {
        Http::fake(['*' => Http::response(['result' => [
            ['id' => 'abc', 'score' => 0.9, 'payload' => ['chunk_id' => 'abc']],
        ]])]);

        $hits = $this->store()->search('01chatbotulid', [0.1, 0.2, 0.3, 0.4], 6, 0.35);

        $this->assertSame([['id' => 'abc', 'score' => 0.9, 'payload' => ['chunk_id' => 'abc']]], $hits);

        Http::assertSent(fn (Request $r) => $r->url() === 'http://qdrant.test:6333/collections/knowledge/points/search'
            && $r['filter'] === ['must' => [['key' => 'chatbot_id', 'match' => ['value' => '01chatbotulid']]]]
            && $r['limit'] === 6
            && $r['score_threshold'] === 0.35
            && $r['with_payload'] === true
            && $r->header('api-key') === ['k']);
    }

    public function test_search_without_threshold_omits_it_and_still_filters(): void
    {
        Http::fake(['*' => Http::response(['result' => []])]);

        $this->store(apiKey: null)->search('bot', [0.1, 0.2, 0.3, 0.4], 3);

        Http::assertSent(fn (Request $r) => ! isset($r['score_threshold'])
            && $r['filter']['must'][0]['key'] === 'chatbot_id'
            && $r->header('api-key') === []);
    }

    public function test_search_and_delete_refuse_an_empty_tenant(): void
    {
        Http::fake();

        foreach ([
            fn () => $this->store()->search('', [0.1], 1),
            fn () => $this->store()->search('  ', [0.1], 1),
            fn () => $this->store()->deleteBySource('', 'src'),
            fn () => $this->store()->deleteByChatbot(' '),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    public function test_delete_by_chatbot_sends_a_tenant_filtered_delete(): void
    {
        Http::fake(['*' => Http::response(['result' => true])]);

        $this->store()->deleteByChatbot('01chatbotulid');

        Http::assertSent(fn (Request $r) => $r->url() === 'http://qdrant.test:6333/collections/knowledge/points/delete?wait=true'
            && $r['filter'] === ['must' => [['key' => 'chatbot_id', 'match' => ['value' => '01chatbotulid']]]]);
    }

    public function test_the_contract_has_no_unfiltered_search(): void
    {
        $methods = array_map(fn ($m) => $m->getName(), (new \ReflectionClass(VectorStore::class))->getMethods());
        sort($methods);

        $this->assertSame(['deleteByChatbot', 'deleteBySource', 'ensureCollection', 'search', 'upsert'], $methods);
        $this->assertSame('chatbotId', (new \ReflectionMethod(VectorStore::class, 'search'))->getParameters()[0]->getName());
        $this->assertFalse((new \ReflectionMethod(VectorStore::class, 'search'))->getParameters()[0]->isOptional());
    }

    public function test_ensure_collection_creates_collection_and_indexes_once(): void
    {
        Http::fake(function (Request $r) {
            return $r->method() === 'GET' ? Http::response([], 404) : Http::response(['result' => true]);
        });

        $store = $this->store();
        $store->ensureCollection();
        $store->ensureCollection();
        $store->ensureCollection();

        $sent = Http::recorded();
        $this->assertCount(4, $sent); // GET + create + 2 indexes, nothing on the next calls

        $create = $sent[1][0];
        $this->assertSame('PUT', $create->method());
        $this->assertSame('http://qdrant.test:6333/collections/knowledge', $create->url());
        $this->assertSame(['size' => 4, 'distance' => 'Cosine'], $create['vectors']);

        $this->assertSame(['field_name' => 'chatbot_id', 'field_schema' => ['type' => 'keyword', 'is_tenant' => true]], $sent[2][0]->data());
        $this->assertSame(['field_name' => 'source_id', 'field_schema' => 'keyword'], $sent[3][0]->data());
    }

    public function test_ensure_collection_does_not_recreate_an_existing_collection(): void
    {
        Http::fake(function (Request $r) {
            return $r->method() === 'GET' ? Http::response(['result' => []]) : Http::response(['result' => true]);
        });

        $this->store()->ensureCollection();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/collections/knowledge'));
    }

    public function test_qdrant_errors_throw(): void
    {
        Http::fake(['*' => Http::response('bad', 500)]);

        $this->expectException(RequestException::class);

        $this->store()->upsert([['id' => 'a', 'vector' => [0.1], 'payload' => ['chatbot_id' => 'b', 'source_id' => 's', 'chunk_id' => 'a']]]);
    }

    public function test_tei_batches_inputs_and_prefixes_passages_and_queries(): void
    {
        Http::fake(fn (Request $r) => Http::response(array_map(fn () => [1, 2, 3, 4], $r['inputs'])));

        $client = new TeiEmbeddingClient('http://tei.test/', 4, batchSize: 32);

        $vectors = $client->embedPassages(array_map(fn ($i) => "texto {$i}", range(1, 70)));

        $this->assertCount(70, $vectors);
        $this->assertSame([1.0, 2.0, 3.0, 4.0], $vectors[0]);
        $requests = Http::recorded();
        $this->assertCount(3, $requests);
        $this->assertSame(['http://tei.test/embed'], [$requests[0][0]->url()]);
        $this->assertCount(32, $requests[0][0]['inputs']);
        $this->assertCount(6, $requests[2][0]['inputs']);
        $this->assertSame('passage: texto 1', $requests[0][0]['inputs'][0]);

        $client->embedQuery('qual o prazo?');
        Http::assertSent(fn (Request $r) => $r['inputs'] === ['query: qual o prazo?']);
    }

    public function test_tei_rejects_wrong_dimensions_and_http_errors(): void
    {
        Http::fake(['*' => Http::response([[1, 2, 3]])]);

        try {
            (new TeiEmbeddingClient('http://tei.test', 4))->embedPassages(['a']);
            $this->fail('Expected a dimension mismatch');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dimension mismatch', $e->getMessage());
        }

    }

    public function test_tei_http_errors_throw(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $this->expectException(RequestException::class);
        (new TeiEmbeddingClient('http://tei.test', 4))->embedQuery('x');
    }

    public function test_tei_with_no_texts_makes_no_request(): void
    {
        Http::fake();

        $this->assertSame([], (new TeiEmbeddingClient('http://tei.test', 4))->embedPassages([]));
        Http::assertNothingSent();
    }
}
