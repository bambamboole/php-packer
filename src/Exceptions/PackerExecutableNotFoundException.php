<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Exceptions;

use RuntimeException;

final class PackerExecutableNotFoundException extends RuntimeException implements PackerException {}
