<?php

namespace App\Services\Widget;

use App\Models\Chatbot;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gate shared by every widget endpoint: the chatbot must be active (otherwise 404, same as an unknown key)
 * and the request Origin must be in its allowed_domains, or be the app's own origin while the sandbox is
 * enabled (otherwise 403). See OriginValidator.
 */
class ChatbotAccess
{
    public function __construct(private readonly OriginValidator $origins) {}

    public function byPublicKey(Request $request, string $publicKey): Chatbot
    {
        $chatbot = Chatbot::query()->where('public_key', $publicKey)->first();

        $this->assertAllowed($request, $chatbot);

        return $chatbot;
    }

    /** @phpstan-assert Chatbot $chatbot */
    public function assertAllowed(Request $request, ?Chatbot $chatbot): void
    {
        if ($chatbot === null || ! $chatbot->is_active) {
            throw new NotFoundHttpException;
        }

        if (! $this->origins->allows($request, $chatbot)) {
            throw new AccessDeniedHttpException('Origem não autorizada para este chatbot.');
        }
    }
}
