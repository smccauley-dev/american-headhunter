<?php

namespace App\DTOs;

use App\Models\Lease\Lease;

readonly class LeaseDetailDTO
{
    public function __construct(
        public Lease   $lease,
        public ?object $property,
        public ?object $lessee,
        public ?object $lessor,
    ) {}
}
