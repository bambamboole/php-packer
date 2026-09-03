<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Commands;

use Bambamboole\Packer\Data\PackerResult;
use Bambamboole\Packer\Enums\OutputStream;
use Bambamboole\Packer\Events\Event;
use Bambamboole\Packer\Exceptions\PackerCommandAlreadyExecutedException;
use Bambamboole\Packer\Exceptions\PackerProcessStartException;
use Bambamboole\Packer\Exceptions\PackerResultNotReadyException;
use Bambamboole\Packer\Support\CommandExecution;
use Illuminate\Process\Factory;
use InvalidArgumentException;
use SplQueue;
use Throwable;
use Traversable;

abstract class PendingCommand
{
    private ?string $workingDirectory = null;

    /** @var array<string, string|false> */
    private array $environment = [];

    private int $timeout = 3600;

    private bool $executed = false;

    private ?CommandExecution $execution = null;

    protected readonly string $template;

    /** @internal */
    public function __construct(
        string $template,
        private readonly Factory $process,
        private readonly string $executablePath,
        private readonly float $cancellationGraceSeconds,
    ) {
        $template = trim($template);

        if ($template === '') {
            throw new InvalidArgumentException('The Packer template cannot be empty.');
        }

        $this->template = $template;
    }

    final public function workingDirectory(string $workingDirectory): static
    {
        $this->ensureNotExecuted();

        if (trim($workingDirectory) === '') {
            throw new InvalidArgumentException('The working directory cannot be empty.');
        }

        $this->workingDirectory = trim($workingDirectory);

        return $this;
    }

    /** @param array<array-key, mixed> $environment */
    final public function environment(array $environment): static
    {
        $this->ensureNotExecuted();
        $normalized = [];

        foreach ($environment as $name => $value) {
            if (! is_string($name) || trim($name) === '' || (! is_string($value) && $value !== false)) {
                throw new InvalidArgumentException('Environment must contain non-empty string keys and string or false values.');
            }

            $normalized[$name] = $value;
        }

        $this->environment = array_replace($this->environment, $normalized);

        return $this;
    }

    final public function environmentVariable(string $name, string|false $value): static
    {
        return $this->environment([$name => $value]);
    }

    final public function timeout(int $seconds): static
    {
        $this->ensureNotExecuted();

        if ($seconds <= 0) {
            throw new InvalidArgumentException('The command timeout must be greater than zero.');
        }

        $this->timeout = $seconds;

        return $this;
    }

    /** @return Traversable<int, Event> */
    final public function execute(): Traversable
    {
        $this->ensureNotExecuted();
        $this->executed = true;

        $pending = $this->process
            ->newPendingProcess()
            ->timeout($this->timeout)
            ->env($this->environment)
            ->tty(false);

        if ($this->workingDirectory !== null) {
            $pending->path($this->workingDirectory);
        }

        /** @var SplQueue<array{OutputStream, string}> $chunks */
        $chunks = new SplQueue;
        $startedAtNanoseconds = hrtime(true);

        try {
            $process = $pending->start(
                $this->arguments(),
                static function (string $type, string $data) use ($chunks): void {
                    $stream = $type === OutputStream::Stdout->value
                        ? OutputStream::Stdout
                        : OutputStream::Stderr;

                    $chunks->enqueue([$stream, $data]);
                },
            );
        } catch (Throwable $exception) {
            throw new PackerProcessStartException(
                'Unable to start the Packer process: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        $this->execution = new CommandExecution(
            $process,
            $chunks,
            $this->cancellationGraceSeconds,
            $startedAtNanoseconds,
        );

        return $this->execution->events();
    }

    final public function result(): PackerResult
    {
        if ($this->execution === null) {
            throw new PackerResultNotReadyException('The Packer result is not available until the command has completed.');
        }

        return $this->execution->result();
    }

    final public function cancel(): void
    {
        $this->execution?->cancel();
    }

    final protected function ensureNotExecuted(): void
    {
        if ($this->executed) {
            throw new PackerCommandAlreadyExecutedException('The Packer command has already been executed.');
        }
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    final protected function validatedStrings(array $values, string $label): array
    {
        foreach ($values as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("{$label} must contain non-empty strings.");
            }
        }

        return $values;
    }

    /** @return non-empty-string */
    abstract protected function commandName(): string;

    /** @return list<string> */
    abstract protected function commandOptions(): array;

    /** @return list<string> */
    private function arguments(): array
    {
        return [
            $this->executablePath,
            $this->commandName(),
            '-machine-readable',
            ...$this->commandOptions(),
            $this->template,
        ];
    }
}
