<?php

use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\KnowledgeSourceController;
use App\Http\Controllers\ProviderModelController;
use App\Http\Controllers\SandboxController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

// Widget playground. Local only: the route does not exist in any other environment.
if (app()->isLocal()) {
    Route::get('/widget-demo', fn () => response()->file(resource_path('widget/demo.html')))->name('widget.demo');
}

// Public test page for a chatbot whose owner turned the sandbox on.
Route::get('/sandbox/{public_key}', SandboxController::class)
    ->middleware('throttle:sandbox-page')
    ->name('sandbox.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    // POST so the api key never lands in a url.
    Route::post('chatbots/models', ProviderModelController::class)
        ->middleware('throttle:provider-models')
        ->name('chatbots.models');

    Route::resource('chatbots', ChatbotController::class);
    Route::patch('chatbots/{chatbot}/sandbox', [ChatbotController::class, 'toggleSandbox'])->name('chatbots.sandbox.toggle');
    Route::post('chatbots/{chatbot}/rotate-key', [ChatbotController::class, 'rotateKey'])->name('chatbots.rotate-key');

    // Child models are resolved through the parent relation: a mismatch is a 404.
    Route::scopeBindings()->group(function () {
        Route::resource('chatbots.conversations', ConversationController::class)
            ->only(['index', 'show']);

        // {knowledgeSource} matches the Chatbot::knowledgeSources() relation used for scoping.
        Route::resource('chatbots.sources', KnowledgeSourceController::class)
            ->parameters(['sources' => 'knowledgeSource'])
            ->only(['index', 'destroy']);

        Route::post('chatbots/{chatbot}/sources', [KnowledgeSourceController::class, 'store'])
            ->middleware('throttle:knowledge-uploads')
            ->name('chatbots.sources.store');

        Route::post('chatbots/{chatbot}/sources/{knowledgeSource}/retry', [KnowledgeSourceController::class, 'retry'])
            ->middleware('throttle:knowledge-retries')
            ->name('chatbots.sources.retry');
    });
});
