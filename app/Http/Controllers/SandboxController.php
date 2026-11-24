<?php

namespace App\Http\Controllers;

use App\Models\Chatbot;
use Illuminate\View\View;

class SandboxController extends Controller
{
    /** Same 404 for an unknown key, a disabled sandbox or an inactive chatbot: nothing tells them apart. */
    public function __invoke(string $publicKey): View
    {
        $chatbot = Chatbot::query()
            ->where('public_key', $publicKey)
            ->where('sandbox_enabled', true)
            ->where('is_active', true)
            ->first();

        abort_if($chatbot === null, 404);

        // Only the name and the snippet (public key) reach the view.
        return view('sandbox.show', [
            'name' => $chatbot->name,
            'snippet' => $chatbot->widgetSnippet(),
        ]);
    }
}
