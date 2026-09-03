<?php
declare(strict_types=1);

namespace Bambamboole\Packer\Support;

use Bambamboole\Packer\Data\PackerResult;
use Bambamboole\Packer\Enums\OutputStream;
use Bambamboole\Packer\Events\DiagnosticEvent;
use Bambamboole\Packer\Events\Event;
use Bambamboole\Packer\Exceptions\PackerResultNotReadyException;
use Generator;
use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Process\Exceptions\ProcessTimedOutException as IlluminateProcessTimedOutException;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\InvokedProcess;
use SplQueue;
use Throwable;

/** @internal */
final class CommandExecution
{
    private const int POLLING_INTERVAL_MICROSECONDS = 10_000;

    private bool $interrupted = false;

    private bool $timedOut = false;

    private bool $processFailed = false;

    private ?PackerResult $finalResult = null;

    private readonly MachineReadableParser $parser;

    private readonly BuildSummary $summary;

    /** @var SplQueue<Event> */
    private SplQueue $pendingEvents;

    /**
     * @param  SplQueue<array{OutputStream, string}>  $chunks
     *
     * @internal
     */
    public function __construct(
        private readonly InvokedProcess|FakeInvokedProcess $process,
        private readonly SplQueue $chunks,
        private readonly float $cancellationGraceSeconds,
        private readonly int $startedAtNanoseconds,
    ) {
        $this->parser = new MachineReadableParser;
        $this->summary = new BuildSummary;
        $this->pendingEvents = new SplQueue;
    }

    public function result(): PackerResult
    {
        if ($this->finalResult === null) {
            throw new PackerResultNotReadyException('The Packer result is not available until the process has completed.');
        }

        return $this->finalResult;
    }

    public function cancel(): void
    {
        if ($this->finalResult !== null) {
            return;
        }

        $this->interrupted = $this->terminateProcess() || $this->interrupted;
        $this->completeFromStoppedProcess();
    }

    public function __destruct()
    {
        if ($this->finalResult === null) {
            try {
                $this->cancel();
            } catch (Throwable) {
            }
        }
    }

    /** @return Generator<int, Event> */
    public function events(): Generator
    {
        try {
            while ($this->finalResult === null) {
                $this->pumpChunks();
                yield from $this->yieldPendingEvents();

                if ($this->finalResult !== null) {
                    break;
                }

                try {
                    $running = $this->process->running();
                    $this->pumpChunks();
                    yield from $this->yieldPendingEvents();

                    if (! $running) {
                        $this->complete($this->process->wait());

                        continue;
                    }

                    $this->process->ensureNotTimedOut();
                } catch (IlluminateProcessTimedOutException $exception) {
                    $this->handleTimeout($exception);
                } catch (Throwable) {
                    $this->handleProcessFailure();
                }

                if ($this->finalResult === null && $this->chunks->isEmpty() && $this->pendingEvents->isEmpty()) {
                    usleep(self::POLLING_INTERVAL_MICROSECONDS);
                }
            }

            yield from $this->yieldPendingEvents();
        } finally {
            if ($this->finalResult === null) {
                $this->cancel();
            }
        }
    }

    /** @return Generator<int, Event> */
    private function yieldPendingEvents(): Generator
    {
        while (! $this->pendingEvents->isEmpty()) {
            yield $this->pendingEvents->dequeue();
        }
    }

    private function pumpChunks(): void
    {
        while (! $this->chunks->isEmpty()) {
            [$stream, $data] = $this->chunks->dequeue();

            if ($stream === OutputStream::Stderr) {
                if ($data !== '') {
                    $this->enqueue(new DiagnosticEvent($stream, $data));
                }

                continue;
            }

            foreach ($this->parser->push($data) as $event) {
                $this->enqueue($event);
            }
        }
    }

    private function enqueue(Event $event): void
    {
        $this->summary->consume($event);
        $this->pendingEvents->enqueue($event);
    }

    private function complete(ProcessResultContract $processResult): void
    {
        $this->completeWithExitCode($processResult->exitCode());
    }

    private function completeWithExitCode(?int $exitCode): void
    {
        if ($this->finalResult !== null) {
            return;
        }

        $this->pumpChunks();

        foreach ($this->parser->finish() as $event) {
            $this->enqueue($event);
        }

        $this->finalResult = new PackerResult(
            exitCode: $exitCode,
            durationSeconds: max(0.0, (hrtime(true) - $this->startedAtNanoseconds) / 1_000_000_000),
            artifacts: $this->summary->artifacts(),
            errors: $this->summary->errors(),
            interrupted: $this->interrupted,
            timedOut: $this->timedOut,
            processFailed: $this->processFailed,
        );
    }

    private function completeFromStoppedProcess(): void
    {
        try {
            $this->complete($this->process->wait());
        } catch (IlluminateProcessTimedOutException $exception) {
            $this->timedOut = true;
            $this->complete($exception->result);
        } catch (Throwable) {
            $this->processFailed = true;
            $this->completeWithExitCode(null);
        }
    }

    private function handleTimeout(IlluminateProcessTimedOutException $exception): void
    {
        $this->timedOut = true;
        $this->terminateProcess();
        $this->complete($exception->result);
    }

    private function handleProcessFailure(): void
    {
        $this->processFailed = true;
        $this->terminateProcess();
        $this->completeFromStoppedProcess();
    }

    private function terminateProcess(): bool
    {
        try {
            if (! $this->process->running()) {
                $this->pumpChunks();

                return false;
            }
        } catch (Throwable) {
        }

        $signalSent = false;

        if (PHP_OS_FAMILY !== 'Windows') {
            try {
                $this->process->signal(defined('SIGINT') ? SIGINT : 2);
                $signalSent = true;
            } catch (Throwable) {
            }
        }

        if ($signalSent && $this->cancellationGraceSeconds > 0) {
            $deadline = hrtime(true) + (int) ($this->cancellationGraceSeconds * 1_000_000_000);

            while (hrtime(true) < $deadline) {
                try {
                    if (! $this->process->running()) {
                        $this->pumpChunks();

                        return true;
                    }
                } catch (Throwable) {
                    break;
                }

                $this->pumpChunks();
                usleep(self::POLLING_INTERVAL_MICROSECONDS);
            }
        }

        try {
            if ($this->process->running()) {
                $this->process->stop(0);
            }
        } catch (Throwable) {
            try {
                $this->process->stop(0);
            } catch (Throwable) {
            }
        }

        $this->pumpChunks();

        return true;
    }
}
