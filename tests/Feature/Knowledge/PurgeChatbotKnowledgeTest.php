<?php

namespace Tests\Feature\Knowledge;

use App\Contracts\VectorStore;
use App\Jobs\Knowledge\PurgeChatbotKnowledge;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeChatbotKnowledgeTest extends TestCase
{
    public function test_it_deletes_the_chatbot_vectors_and_its_upload_directory_only(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response(['result' => true])]);

        Storage::disk('local')->put('knowledge/7/01ULIDA/one.pdf', 'x');
        Storage::disk('local')->put('knowledge/7/01ULIDA/one.pdf.extracted.txt', 'x');
        Storage::disk('local')->put('knowledge/7/01ULIDB/other.pdf', 'x');

        (new PurgeChatbotKnowledge('01ULIDA', 7))->handle(app(VectorStore::class));

        Storage::disk('local')->assertMissing('knowledge/7/01ULIDA/one.pdf');
        Storage::disk('local')->assertMissing('knowledge/7/01ULIDA/one.pdf.extracted.txt');
        Storage::disk('local')->assertExists('knowledge/7/01ULIDB/other.pdf');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/points/delete?wait=true')
            && $r['filter']['must'] === [['key' => 'chatbot_id', 'match' => ['value' => '01ULIDA']]]);
    }
}
