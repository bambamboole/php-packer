<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Data;

final readonly class Artifact
{
    /** @param list<string> $files */
    public function __construct(
        public ?string $target,
        public int $index,
        public ?string $builderId,
        public ?string $id,
        public ?string $description,
        public array $files,
        public ?int $declaredFileCount,
        public bool $isNull,
        public bool $complete,
    ) {}
}
