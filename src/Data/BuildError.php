<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Data;

final readonly class BuildError
{
    public function __construct(
        public int $timestamp,
        public ?string $target,
        public string $message,
        public string $rawLine,
    ) {}
}
