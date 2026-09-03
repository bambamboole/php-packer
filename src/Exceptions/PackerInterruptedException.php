<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Exceptions;

use Bambamboole\Packer\Data\PackerResult;

final class PackerInterruptedException extends PackerResultException
{
    public function __construct(PackerResult $result)
    {
        parent::__construct('Packer command was interrupted.', $result);
    }
}
