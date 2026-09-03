<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Exceptions;

use LogicException;

final class PackerResultNotReadyException extends LogicException implements PackerException {}
