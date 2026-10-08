<?php

namespace App\Services\Ingestion;

final readonly class IngestResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public array $body,
        public bool $replayed,
    ) {}
}
