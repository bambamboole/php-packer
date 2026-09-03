<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Events;

use Bambamboole\Packer\Enums\OutputStream;

final readonly class DiagnosticEvent implements Event
{
    public function __construct(
        public OutputStream $stream,
        public string $rawData,
    ) {}
}
