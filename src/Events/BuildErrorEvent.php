<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Events;

final readonly class BuildErrorEvent extends MachineReadableEvent
{
    /** @param list<string> $data */
    public function __construct(
        int $timestamp,
        ?string $target,
        array $data,
        string $rawLine,
        public string $message,
    ) {
        parent::__construct($timestamp, $target, 'error', $data, $rawLine);
    }
}
