<?php

namespace App\Models;

use App\Enums\KnowledgeSourceStatus;
use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\KnowledgeSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// chatbot_id and user_id are set through relations, never from request input.
#[Fillable(['original_name', 'disk_path', 'mime', 'size', 'status', 'error', 'chunks_count'])]
class KnowledgeSource extends Model
{
    /** @use HasFactory<KnowledgeSourceFactory> */
    use BelongsToUser, HasFactory, HasPublicUlid;

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    protected function casts(): array
    {
        return [
            'status' => KnowledgeSourceStatus::class,
            'size' => 'integer',
            'chunks_count' => 'integer',
        ];
    }
}
