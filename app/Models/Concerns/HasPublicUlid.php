<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Exposes a ULID as the public route key. Sequential ids never leave the server.
 */
trait HasPublicUlid
{
    public static function bootHasPublicUlid(): void
    {
        static::creating(function ($model): void {
            if (empty($model->ulid)) {
                $model->ulid = Str::lower((string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
