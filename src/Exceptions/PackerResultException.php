<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Exceptions;

use Bambamboole\Packer\Data\PackerResult;
use RuntimeException;

abstract class PackerResultException extends RuntimeException implements PackerException
{
    public function __construct(
        string $message,
        public readonly PackerResult $result,
    ) {
        parent::__construct($message);
    }
}
