<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            // The UUID is also the point id in Qdrant.
            $table->uuid('id')->primary();
            $table->foreignId('knowledge_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatbot_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->longText('content');
            $table->timestamp('embedded_at')->nullable();

            $table->index('knowledge_source_id');
            $table->index('chatbot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
