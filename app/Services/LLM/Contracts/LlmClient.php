<?php

namespace App\Services\LLM\Contracts;

use App\Services\LLM\Exceptions\LlmException;
use App\Services\LLM\LlmRequest;
use App\Services\LLM\LlmResponse;

interface LlmClient
{
    /** @throws LlmException */
    public function complete(LlmRequest $request): LlmResponse;

    /**
     * Calls $onToken with each text fragment as it arrives and returns the full response.
     *
     * @param  callable(string): void  $onToken
     *
     * @throws LlmException
     */
    public function stream(LlmRequest $request, callable $onToken): LlmResponse;
}
