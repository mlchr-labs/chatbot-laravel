<?php

namespace App\Contracts;

interface EmbeddingClient
{
    /**
     * Embeds document passages in as few requests as the backend allows.
     * Model specific prefixes (e5 "passage: ") are applied by the implementation.
     *
     * @param  list<string>  $texts
     * @return list<list<float>> one vector per input, same order
     */
    public function embedPassages(array $texts): array;

    /**
     * Embeds a user question. Model specific prefixes (e5 "query: ") are applied by the implementation.
     *
     * @return list<float>
     */
    public function embedQuery(string $text): array;
}
