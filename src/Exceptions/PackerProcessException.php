<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Exceptions;

use Bambamboole\Packer\Data\PackerResult;

final class PackerProcessException extends PackerResultException
{
    public function __construct(PackerResult $result)
    {
        parent::__construct('Packer process execution failed.', $result);
    }
}
