<?php

namespace App\Http\Controllers;

use App\Enums\LlmProvider;
use App\Http\Requests\ListProviderModelsRequest;
use App\Models\Chatbot;
use App\Services\LLM\Exceptions\LlmException;
use App\Services\LLM\ProviderModels;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Lists the models of a provider for a key of the user. Always called from the server, never from the browser. */
class ProviderModelController extends Controller
{
    public function __invoke(ListProviderModelsRequest $request, ProviderModels $models): JsonResponse
    {
        $provider = LlmProvider::from($request->validated('provider'));
        $apiKey = trim((string) $request->validated('api_key'));

        if ($apiKey === '' && filled($request->validated('chatbot'))) {
            $chatbot = Chatbot::where('ulid', $request->validated('chatbot'))->firstOrFail();

            // A chatbot that is not the user's answers 404, like everywhere else in the panel.
            Gate::authorize('update', $chatbot);

            // The saved key is only ever sent to the provider it was saved for.
            if ($chatbot->provider !== $provider) {
                return $this->failure('A chave salva é de outro provedor. Digite a chave de API para carregar os modelos.', 422);
            }

            $apiKey = (string) $chatbot->api_key;
        }

        if ($apiKey === '') {
            return $this->failure('Informe a chave de API para carregar os modelos.', 422);
        }

        try {
            return response()->json(['models' => $models->list($provider, $apiKey)]);
        } catch (LlmException $e) {
            // The exception message is pt-BR and never carries the key or the provider body.
            return $this->failure($e->getMessage(), in_array($e->getCode(), [401, 403], true) ? 422 : 502);
        }
    }

    private function failure(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
