<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Services\Widget\ChatbotAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    public function __invoke(Request $request, string $publicKey, ChatbotAccess $access): JsonResponse
    {
        $chatbot = $access->byPublicKey($request, $publicKey);

        return response()->json([
            'name' => $chatbot->name,
            'welcome_message' => $chatbot->welcome_message,
            'theme' => $chatbot->theme ?? (object) [],
        ]);
    }
}
