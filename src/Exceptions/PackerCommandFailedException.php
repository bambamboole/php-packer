<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Exceptions;

use Bambamboole\Packer\Data\PackerResult;

final class PackerCommandFailedException extends PackerResultException
{
    public function __construct(PackerResult $result)
    {
        $exitCode = $result->exitCode === null ? 'unknown' : (string) $result->exitCode;

        parent::__construct("Packer command failed with exit code {$exitCode}.", $result);
    }
}
