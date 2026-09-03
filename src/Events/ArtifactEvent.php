<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Events;

final readonly class ArtifactEvent extends MachineReadableEvent
{
    /** @param list<string> $data */
    public function __construct(
        int $timestamp,
        ?string $target,
        array $data,
        string $rawLine,
        public int $index,
        public string $kind,
        public ?string $value = null,
        public ?int $fileIndex = null,
    ) {
        parent::__construct($timestamp, $target, 'artifact', $data, $rawLine);
    }
}
