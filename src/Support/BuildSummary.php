<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Support;

use Bambamboole\Packer\Data\Artifact;
use Bambamboole\Packer\Data\BuildError;
use Bambamboole\Packer\Events\ArtifactEvent;
use Bambamboole\Packer\Events\BuildErrorEvent;
use Bambamboole\Packer\Events\Event;

final class BuildSummary
{
    /**
     * @var array<string, array<int, array{
     *     target: ?string,
     *     index: int,
     *     builderId: ?string,
     *     id: ?string,
     *     description: ?string,
     *     files: array<int, string>,
     *     declaredFileCount: ?int,
     *     isNull: bool,
     *     complete: bool
     * }>>
     */
    private array $artifacts = [];

    /** @var list<BuildError> */
    private array $errors = [];

    public function consume(Event $event): void
    {
        if ($event instanceof BuildErrorEvent) {
            $this->errors[] = new BuildError(
                $event->timestamp,
                $event->target,
                $event->message,
                $event->rawLine,
            );

            return;
        }

        if (! $event instanceof ArtifactEvent) {
            return;
        }

        $targetKey = $event->target ?? "\0";
        $state = $this->artifacts[$targetKey][$event->index] ?? [
            'target' => $event->target,
            'index' => $event->index,
            'builderId' => null,
            'id' => null,
            'description' => null,
            'files' => [],
            'declaredFileCount' => null,
            'isNull' => false,
            'complete' => false,
        ];

        match ($event->kind) {
            'builder-id' => $state['builderId'] = $event->value,
            'id' => $state['id'] = $event->value,
            'string' => $state['description'] = $event->value,
            'files-count' => $state['declaredFileCount'] = (int) $event->value,
            'file' => $state['files'][$event->fileIndex ?? count($state['files'])] = $event->value ?? '',
            'nil' => $state['isNull'] = true,
            'end' => $state['complete'] = true,
            default => null,
        };

        $this->artifacts[$targetKey][$event->index] = $state;
    }

    /** @return list<Artifact> */
    public function artifacts(): array
    {
        $artifacts = [];

        foreach ($this->artifacts as $targetArtifacts) {
            ksort($targetArtifacts);

            foreach ($targetArtifacts as $state) {
                ksort($state['files']);
                $artifacts[] = new Artifact(
                    $state['target'],
                    $state['index'],
                    $state['builderId'],
                    $state['id'],
                    $state['description'],
                    array_values($state['files']),
                    $state['declaredFileCount'],
                    $state['isNull'],
                    $state['complete'],
                );
            }
        }

        return $artifacts;
    }

    /** @return list<BuildError> */
    public function errors(): array
    {
        return $this->errors;
    }
}
