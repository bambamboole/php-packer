<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Enums;

enum OnError: string
{
    case Cleanup = 'cleanup';
    case Abort = 'abort';
    case RunCleanupProvisioner = 'run-cleanup-provisioner';
}
