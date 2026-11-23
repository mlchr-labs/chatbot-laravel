<?php

namespace App\Models;

use App\Enums\LlmProvider;
use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\ChatbotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

// user_id, ulid and public_key are intentionally not fillable.
#[Fillable([
    'name', 'system_prompt', 'provider', 'model', 'api_key', 'temperature',
    'welcome_message', 'theme', 'allowed_domains', 'is_active',
])]
#[Hidden(['api_key'])]
class Chatbot extends Model
{
    /** @use HasFactory<ChatbotFactory> */
    use BelongsToUser, HasFactory, HasPublicUlid;

    protected static function booted(): void
    {
        static::creating(function (Chatbot $chatbot): void {
            if (empty($chatbot->public_key)) {
                $chatbot->public_key = static::generatePublicKey();
            }
        });
    }

    public static function generatePublicKey(): string
    {
        return 'pk_'.Str::random(32);
    }

    public function rotatePublicKey(): void
    {
        $this->forceFill(['public_key' => static::generatePublicKey()])->save();
    }

    public function hasApiKey(): bool
    {
        return ! empty($this->attributes['api_key']);
    }

    public function knowledgeSources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    public function knowledgeChunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    protected function casts(): array
    {
        return [
            'provider' => LlmProvider::class,
            'api_key' => 'encrypted',
            'temperature' => 'float',
            'theme' => 'array',
            'allowed_domains' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
