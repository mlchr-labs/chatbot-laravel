<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbots', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('system_prompt')->nullable();
            $table->string('provider', 32);
            $table->string('model');
            $table->text('api_key')->nullable();
            $table->decimal('temperature', 3, 2)->default(0.7);
            $table->text('welcome_message')->nullable();
            $table->json('theme')->nullable();
            $table->string('public_key', 64)->unique();
            $table->json('allowed_domains')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbots');
    }
};
