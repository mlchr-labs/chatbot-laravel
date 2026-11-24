<?php

namespace App\Services\LLM\Contracts;

use App\Services\LLM\Exceptions\LlmException;

/** Lists the model ids an API key can use on a provider. */
interface ModelCatalog
{
    /**
     * @return list<string> unique ids in the order the provider returned them
     *
     * @throws LlmException
     */
    public function models(): array;
}
