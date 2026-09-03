<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Enums;

enum OutputStream: string
{
    case Stdout = 'out';
    case Stderr = 'err';
}
