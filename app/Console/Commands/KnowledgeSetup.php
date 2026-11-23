<?php

namespace App\Console\Commands;

use App\Contracts\VectorStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('knowledge:setup')]
#[Description('Create the vector collection and payload indexes if they do not exist yet')]
class KnowledgeSetup extends Command
{
    public function handle(VectorStore $store): int
    {
        $store->ensureCollection();

        $this->info('Vector collection is ready.');

        return self::SUCCESS;
    }
}
