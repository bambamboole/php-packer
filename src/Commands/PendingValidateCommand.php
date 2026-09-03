<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Commands;

use InvalidArgumentException;

final class PendingValidateCommand extends PendingCommand
{
    /** @var array<string, string> */
    private array $variables = [];

    /** @var list<string> */
    private array $variableFiles = [];

    /** @var list<string> */
    private array $only = [];

    /** @var list<string> */
    private array $except = [];

    private bool $syntaxOnly = false;

    private bool $evaluateDataSources = false;

    private bool $warnOnUndeclaredVariables = true;

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

    public function syntaxOnly(bool $enabled = true): self
    {
        $this->ensureNotExecuted();
        $this->syntaxOnly = $enabled;

        return $this;
    }

    public function evaluateDataSources(bool $enabled = true): self
    {
        $this->ensureNotExecuted();
        $this->evaluateDataSources = $enabled;

        return $this;
    }

    public function warnOnUndeclaredVariables(bool $enabled = true): self
    {
        $this->ensureNotExecuted();
        $this->warnOnUndeclaredVariables = $enabled;

        return $this;
    }

    /** @return non-empty-string */
    protected function commandName(): string
    {
        return 'validate';
    }

    /** @return list<string> */
    protected function commandOptions(): array
    {
        $arguments = [];

        if ($this->syntaxOnly) {
            $arguments[] = '-syntax-only';
        }

        if ($this->evaluateDataSources) {
            $arguments[] = '-evaluate-datasources';
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

        if (! $this->warnOnUndeclaredVariables) {
            $arguments[] = '-no-warn-undeclared-var';
        }

        return $arguments;
    }
}
