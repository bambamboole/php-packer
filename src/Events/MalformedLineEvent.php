<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Events;

final readonly class MalformedLineEvent implements Event
{
    public function __construct(
        public string $rawLine,
        public string $reason,
    ) {}
}
