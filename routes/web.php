<?php

use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\KnowledgeSourceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::resource('chatbots', ChatbotController::class);
    Route::post('chatbots/{chatbot}/rotate-key', [ChatbotController::class, 'rotateKey'])->name('chatbots.rotate-key');

    // Child models are resolved through the parent relation: a mismatch is a 404.
    Route::scopeBindings()->group(function () {
        Route::resource('chatbots.conversations', ConversationController::class)
            ->only(['index', 'show']);

        // {knowledgeSource} matches the Chatbot::knowledgeSources() relation used for scoping.
        Route::resource('chatbots.sources', KnowledgeSourceController::class)
            ->parameters(['sources' => 'knowledgeSource'])
            ->only(['index', 'store', 'destroy']);
    });
});
