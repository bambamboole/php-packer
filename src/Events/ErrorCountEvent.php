<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Events;

final readonly class ErrorCountEvent extends MachineReadableEvent
{
    /** @param list<string> $data */
    public function __construct(
        int $timestamp,
        ?string $target,
        array $data,
        string $rawLine,
        public int $count,
    ) {
        parent::__construct($timestamp, $target, 'error-count', $data, $rawLine);
    }
}
