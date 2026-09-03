<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Support;

use Bambamboole\Packer\Events\ArtifactCountEvent;
use Bambamboole\Packer\Events\ArtifactEvent;
use Bambamboole\Packer\Events\BuildErrorEvent;
use Bambamboole\Packer\Events\ErrorCountEvent;
use Bambamboole\Packer\Events\Event;
use Bambamboole\Packer\Events\MalformedLineEvent;
use Bambamboole\Packer\Events\UiEvent;
use Bambamboole\Packer\Events\UnknownEvent;
use LogicException;

final class MachineReadableParser
{
    private string $buffer = '';

    private bool $finished = false;

    /** @return list<Event> */
    public function push(string $chunk): array
    {
        if ($this->finished) {
            throw new LogicException('The machine-readable parser has already been finished.');
        }

        $this->buffer .= $chunk;
        $events = [];

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);
            $events[] = $this->parseLine($this->withoutCarriageReturn($line));
        }

        return $events;
    }

    /** @return list<Event> */
    public function finish(): array
    {
        if ($this->finished) {
            return [];
        }

        $this->finished = true;

        if ($this->buffer === '') {
            return [];
        }

        $line = $this->withoutCarriageReturn($this->buffer);
        $this->buffer = '';

        return [$this->parseLine($line)];
    }

    public function parseLine(string $rawLine): Event
    {
        $fields = explode(',', $rawLine);

        if (count($fields) < 3) {
            return new MalformedLineEvent($rawLine, 'Expected timestamp, target, and event type.');
        }

        if ($fields[0] === '' || ! ctype_digit($fields[0])) {
            return new MalformedLineEvent($rawLine, 'The timestamp is not a non-negative Unix timestamp.');
        }

        if ($fields[2] === '') {
            return new MalformedLineEvent($rawLine, 'The event type is empty.');
        }

        $timestamp = (int) $fields[0];
        $target = $fields[1] === '' ? null : $fields[1];
        $type = $fields[2];
        $data = array_map($this->decode(...), array_slice($fields, 3));

        return match ($type) {
            'ui' => $this->parseUi($timestamp, $target, $data, $rawLine),
            'error-count' => $this->parseErrorCount($timestamp, $target, $data, $rawLine),
            'error' => $this->parseError($timestamp, $target, $data, $rawLine),
            'artifact-count' => $this->parseArtifactCount($timestamp, $target, $data, $rawLine),
            'artifact' => $this->parseArtifact($timestamp, $target, $data, $rawLine),
            default => new UnknownEvent($timestamp, $target, $type, $data, $rawLine),
        };
    }

    /** @param list<string> $data */
    private function parseUi(int $timestamp, ?string $target, array $data, string $rawLine): Event
    {
        if (count($data) < 2 || $data[0] === '') {
            return new MalformedLineEvent($rawLine, 'A UI event requires a subtype and message.');
        }

        return new UiEvent($timestamp, $target, $data, $rawLine, $data[0], $data[1]);
    }

    /** @param list<string> $data */
    private function parseErrorCount(int $timestamp, ?string $target, array $data, string $rawLine): Event
    {
        if (! $this->hasUnsignedIntegerAt($data, 0)) {
            return new MalformedLineEvent($rawLine, 'An error-count event requires a non-negative count.');
        }

        return new ErrorCountEvent($timestamp, $target, $data, $rawLine, (int) $data[0]);
    }

    /** @param list<string> $data */
    private function parseError(int $timestamp, ?string $target, array $data, string $rawLine): Event
    {
        if (! array_key_exists(0, $data)) {
            return new MalformedLineEvent($rawLine, 'An error event requires a message.');
        }

        return new BuildErrorEvent($timestamp, $target, $data, $rawLine, $data[0]);
    }

    /** @param list<string> $data */
    private function parseArtifactCount(int $timestamp, ?string $target, array $data, string $rawLine): Event
    {
        if (! $this->hasUnsignedIntegerAt($data, 0)) {
            return new MalformedLineEvent($rawLine, 'An artifact-count event requires a non-negative count.');
        }

        return new ArtifactCountEvent($timestamp, $target, $data, $rawLine, (int) $data[0]);
    }

    /** @param list<string> $data */
    private function parseArtifact(int $timestamp, ?string $target, array $data, string $rawLine): Event
    {
        if (! $this->hasUnsignedIntegerAt($data, 0) || ! isset($data[1]) || $data[1] === '') {
            return new MalformedLineEvent($rawLine, 'An artifact event requires an index and kind.');
        }

        $index = (int) $data[0];
        $kind = $data[1];

        if (in_array($kind, ['builder-id', 'id', 'string'], true)) {
            if (! array_key_exists(2, $data)) {
                return new MalformedLineEvent($rawLine, "Artifact kind {$kind} requires a value.");
            }

            return new ArtifactEvent($timestamp, $target, $data, $rawLine, $index, $kind, $data[2]);
        }

        if ($kind === 'files-count') {
            if (! $this->hasUnsignedIntegerAt($data, 2)) {
                return new MalformedLineEvent($rawLine, 'Artifact kind files-count requires a non-negative count.');
            }

            return new ArtifactEvent($timestamp, $target, $data, $rawLine, $index, $kind, $data[2]);
        }

        if ($kind === 'file') {
            if (! $this->hasUnsignedIntegerAt($data, 2) || ! array_key_exists(3, $data)) {
                return new MalformedLineEvent($rawLine, 'Artifact kind file requires an index and path.');
            }

            return new ArtifactEvent($timestamp, $target, $data, $rawLine, $index, $kind, $data[3], (int) $data[2]);
        }

        if (in_array($kind, ['nil', 'end'], true)) {
            return new ArtifactEvent($timestamp, $target, $data, $rawLine, $index, $kind);
        }

        return new UnknownEvent($timestamp, $target, 'artifact', $data, $rawLine);
    }

    private function decode(string $value): string
    {
        return str_replace(
            ['%!(PACKER_COMMA)', '\\r', '\\n'],
            [',', "\r", "\n"],
            $value,
        );
    }

    /** @param list<string> $data */
    private function hasUnsignedIntegerAt(array $data, int $index): bool
    {
        return isset($data[$index]) && $data[$index] !== '' && ctype_digit($data[$index]);
    }

    private function withoutCarriageReturn(string $line): string
    {
        return str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
    }
}
