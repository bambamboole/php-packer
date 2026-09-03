<?php
declare(strict_types=1);

namespace Bambamboole\Packer;

use Bambamboole\Packer\Commands\PendingBuildCommand;
use Bambamboole\Packer\Exceptions\PackerExecutableNotFoundException;
use Illuminate\Process\Factory;
use Symfony\Component\Process\ExecutableFinder;
use UnexpectedValueException;

final class Packer
{
    public function __construct(
        private readonly Factory $process = new Factory,
        private ?string $executablePath = null,
        private readonly float $cancellationGraceSeconds = 5.0,
    ) {}

    public function build(string $template): PendingBuildCommand
    {
        return new PendingBuildCommand(
            $template,
            $this->process,
            $this->executable(),
            $this->cancellationGraceSeconds,
        );
    }

    public function version(): string
    {
        $output = trim($this->process
            ->newPendingProcess()
            ->run([$this->executable(), '--version'])
            ->throw()
            ->output());

        if (preg_match('/\APacker v(?<version>\S+)\z/', $output, $matches) !== 1) {
            throw new UnexpectedValueException("Unexpected Packer version output: {$output}");
        }

        return $matches['version'];
    }

    private function executable(): string
    {
        return $this->executablePath ??= (new ExecutableFinder)->find('packer')
            ?? throw new PackerExecutableNotFoundException('Unable to find the Packer executable [packer].');
    }
}
