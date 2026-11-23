<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('chatbot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('disk_path');
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->string('status', 32)->default('pending');
            $table->text('error')->nullable();
            $table->unsignedInteger('chunks_count')->default(0);
            $table->timestamps();

            $table->index('chatbot_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_sources');
    }
};
