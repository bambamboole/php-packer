<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Exceptions;

use LogicException;

final class PackerCommandAlreadyExecutedException extends LogicException implements PackerException {}
