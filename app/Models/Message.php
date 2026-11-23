<?php

namespace App\Models;

use App\Enums\MessageRole;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role', 'content', 'tokens_in', 'tokens_out', 'sources'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasPublicUlid;

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'sources' => 'array',
        ];
    }
}
