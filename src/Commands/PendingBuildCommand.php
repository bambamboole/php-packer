<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Commands;

use Bambamboole\Packer\Data\PackerResult;
use Bambamboole\Packer\Enums\OnError;
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

final class PendingBuildCommand
{
    private ?string $workingDirectory = null;

    /** @var array<string, string|false> */
    private array $environment = [];

    private int $timeout = 3600;

    /** @var array<string, string> */
    private array $variables = [];

    /** @var list<string> */
    private array $variableFiles = [];

    /** @var list<string> */
    private array $only = [];

    /** @var list<string> */
    private array $except = [];

    private bool $force = false;

    private ?OnError $onError = null;

    private ?int $parallelBuilds = null;

    private bool $warnOnUndeclaredVariables = false;

    private bool $skipEnforcement = false;

    private bool $executed = false;

    private ?CommandExecution $execution = null;

    /** @internal */
    public function __construct(
        private readonly string $template,
        private readonly Factory $process,
        private readonly string $executablePath,
        private readonly float $cancellationGraceSeconds,
    ) {
        if (trim($template) === '') {
            throw new InvalidArgumentException('The Packer template cannot be empty.');
        }
    }

    public function workingDirectory(string $workingDirectory): self
    {
        $this->ensureNotExecuted();

        if (trim($workingDirectory) === '') {
            throw new InvalidArgumentException('The working directory cannot be empty.');
        }

        $this->workingDirectory = trim($workingDirectory);

        return $this;
    }

    /** @param array<array-key, mixed> $environment */
    public function environment(array $environment): self
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

    public function environmentVariable(string $name, string|false $value): self
    {
        return $this->environment([$name => $value]);
    }

    public function timeout(int $seconds): self
    {
        $this->ensureNotExecuted();

        if ($seconds <= 0) {
            throw new InvalidArgumentException('The build timeout must be greater than zero.');
        }

        $this->timeout = $seconds;

        return $this;
    }

    /** @param array<array-key, mixed> $variables */
    public function variables(array $variables): self
    {
        $this->ensureNotExecuted();
        $normalized = [];

        foreach ($variables as $name => $value) {
            if (! is_string($name) || trim($name) === '' || ! is_string($value)) {
                throw new InvalidArgumentException('Variables must contain non-empty string keys and string values.');
            }

            $normalized[$name] = $value;
        }

        $this->variables = array_replace($this->variables, $normalized);

        return $this;
    }

    public function variable(string $name, string $value): self
    {
        return $this->variables([$name => $value]);
    }

    public function variableFile(string $file): self
    {
        return $this->variableFiles($file);
    }

    public function variableFiles(string ...$files): self
    {
        $this->ensureNotExecuted();
        $this->variableFiles = [...$this->variableFiles, ...$this->validatedStrings($files, 'Variable files')];

        return $this;
    }

    public function only(string ...$targets): self
    {
        $this->ensureNotExecuted();
        $this->only = [...$this->only, ...$this->validatedStrings($targets, 'Only filters')];

        return $this;
    }

    public function except(string ...$targets): self
    {
        $this->ensureNotExecuted();
        $this->except = [...$this->except, ...$this->validatedStrings($targets, 'Except filters')];

        return $this;
    }

    public function force(bool $enabled = true): self
    {
        $this->ensureNotExecuted();
        $this->force = $enabled;

        return $this;
    }

    public function onError(OnError $behavior): self
    {
        $this->ensureNotExecuted();
        $this->onError = $behavior;

        return $this;
    }

    public function parallelBuilds(int $parallelBuilds): self
    {
        $this->ensureNotExecuted();

        if ($parallelBuilds < 0) {
            throw new InvalidArgumentException('Parallel builds cannot be negative.');
        }

        $this->parallelBuilds = $parallelBuilds;

        return $this;
    }

    public function warnOnUndeclaredVariables(bool $enabled = true): self
    {
        $this->ensureNotExecuted();
        $this->warnOnUndeclaredVariables = $enabled;

        return $this;
    }

    public function skipEnforcement(bool $enabled = true): self
    {
        $this->ensureNotExecuted();
        $this->skipEnforcement = $enabled;

        return $this;
    }

    /** @return Traversable<int, Event> */
    public function execute(): Traversable
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

    public function result(): PackerResult
    {
        if ($this->execution === null) {
            throw new PackerResultNotReadyException('The Packer result is not available until the command has completed.');
        }

        return $this->execution->result();
    }

    public function cancel(): void
    {
        $this->execution?->cancel();
    }

    private function ensureNotExecuted(): void
    {
        if ($this->executed) {
            throw new PackerCommandAlreadyExecutedException('The Packer command has already been executed.');
        }
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function validatedStrings(array $values, string $label): array
    {
        foreach ($values as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("{$label} must contain non-empty strings.");
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function arguments(): array
    {
        $arguments = [$this->executablePath, 'build', '-machine-readable'];

        if ($this->force) {
            $arguments[] = '-force';
        }

        if ($this->onError !== null) {
            $arguments[] = '-on-error';
            $arguments[] = $this->onError->value;
        }

        if ($this->parallelBuilds !== null) {
            $arguments[] = '-parallel-builds';
            $arguments[] = (string) $this->parallelBuilds;
        }

        if ($this->only !== []) {
            $arguments[] = '-only';
            $arguments[] = implode(',', $this->only);
        }

        if ($this->except !== []) {
            $arguments[] = '-except';
            $arguments[] = implode(',', $this->except);
        }

        foreach ($this->variables as $name => $value) {
            $arguments[] = '-var';
            $arguments[] = $name.'='.$value;
        }

        foreach ($this->variableFiles as $file) {
            $arguments[] = '-var-file';
            $arguments[] = $file;
        }

        if ($this->warnOnUndeclaredVariables) {
            $arguments[] = '-warn-on-undeclared-var';
        }

        if ($this->skipEnforcement) {
            $arguments[] = '-skip-enforcement';
        }

        $arguments[] = trim($this->template);

        return $arguments;
    }
}
