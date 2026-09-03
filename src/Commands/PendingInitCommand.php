<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Commands;

final class PendingInitCommand extends PendingCommand
{
    private bool $force = false;

    private bool $upgrade = false;

    public function force(bool $enabled = true): self
    {
        $this->ensureNotExecuted();
        $this->force = $enabled;

        return $this;
    }

    public function upgrade(bool $enabled = true): self
    {
        $this->ensureNotExecuted();
        $this->upgrade = $enabled;

        return $this;
    }

    /** @return non-empty-string */
    protected function commandName(): string
    {
        return 'init';
    }

    /** @return list<string> */
    protected function commandOptions(): array
    {
        $arguments = [];

        if ($this->force) {
            $arguments[] = '-force';
        }

        if ($this->upgrade) {
            $arguments[] = '-upgrade';
        }

        return $arguments;
    }
}
