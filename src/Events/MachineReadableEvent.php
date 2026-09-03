<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Events;

abstract readonly class MachineReadableEvent implements Event
{
    /** @param list<string> $data */
    public function __construct(
        public int $timestamp,
        public ?string $target,
        public string $type,
        public array $data,
        public string $rawLine,
    ) {}
}
