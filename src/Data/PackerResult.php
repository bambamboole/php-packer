<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Data;

use Bambamboole\Packer\Exceptions\PackerBuildFailedException;
use Bambamboole\Packer\Exceptions\PackerInterruptedException;
use Bambamboole\Packer\Exceptions\PackerProcessException;
use Bambamboole\Packer\Exceptions\PackerTimedOutException;

final readonly class PackerResult
{
    /**
     * @param  list<Artifact>  $artifacts
     * @param  list<BuildError>  $errors
     */
    public function __construct(
        public ?int $exitCode,
        public float $durationSeconds,
        public array $artifacts,
        public array $errors,
        public bool $interrupted = false,
        public bool $timedOut = false,
        public bool $processFailed = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->interrupted && ! $this->timedOut && ! $this->processFailed;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function throw(): self
    {
        if ($this->successful()) {
            return $this;
        }

        if ($this->timedOut) {
            throw new PackerTimedOutException($this);
        }

        if ($this->interrupted) {
            throw new PackerInterruptedException($this);
        }

        if ($this->processFailed) {
            throw new PackerProcessException($this);
        }

        throw new PackerBuildFailedException($this);
    }
}
