<?php

namespace App\Services;

use Carbon\CarbonImmutable;

readonly class CashFlowEntry
{
    public function __construct(
        public string $key,
        public string $source,
        public int $source_id,
        public ?int $sale_id,
        public string $description,
        public CarbonImmutable $date,
        public string $amount,
        public string $direction,
        public string $stage,
        public string $nature,
    ) {}
}
