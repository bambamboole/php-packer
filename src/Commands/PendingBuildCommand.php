<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Commands;

use Bambamboole\Packer\Enums\OnError;
use InvalidArgumentException;

final class PendingBuildCommand extends PendingCommand
{
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

    /** @return non-empty-string */
    protected function commandName(): string
    {
        return 'build';
    }

    /** @return list<string> */
    protected function commandOptions(): array
    {
        $arguments = [];

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

        return $arguments;
    }
}
