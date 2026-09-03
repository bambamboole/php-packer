<?php
declare(strict_types=1);

use Bambamboole\Packer\Data\PackerResult;
use Bambamboole\Packer\Events\DiagnosticEvent;
use Bambamboole\Packer\Events\UiEvent;
use Bambamboole\Packer\Exceptions\PackerBuildFailedException;
use Bambamboole\Packer\Exceptions\PackerInterruptedException;
use Bambamboole\Packer\Exceptions\PackerProcessException;
use Bambamboole\Packer\Exceptions\PackerProcessStartException;
use Bambamboole\Packer\Exceptions\PackerResultNotReadyException;
use Bambamboole\Packer\Exceptions\PackerTimedOutException;
use Bambamboole\Packer\Packer;
use Bambamboole\Packer\Support\CommandExecution;
use Illuminate\Process\Exceptions\ProcessTimedOutException as IlluminateProcessTimedOutException;
use Illuminate\Process\Factory;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\FakeProcessDescription;
use Illuminate\Process\FakeProcessResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

it('streams events before the process result is available', function () {
    $process = new Factory;
    $process->fake(['*' => $process->describe()
        ->output('1700000000,,ui,say,First')
        ->output('1700000001,,ui,message,Second')
        ->runsFor(3),
    ])->preventStrayProcesses();
    $build = (new Packer($process, executablePath: PHP_BINARY))
        ->build('image.pkr.hcl');

    expect(fn () => $build->result())->toThrow(PackerResultNotReadyException::class);

    $events = $build->execute();
    $events->rewind();

    expect($events->current())->toBeInstanceOf(UiEvent::class)
        ->and($events->current()->message)->toBe('First')
        ->and(fn () => $build->result())->toThrow(PackerResultNotReadyException::class);

    $events->next();
    expect($events->current()->message)->toBe('Second');

    $events->next();
    expect($events->valid())->toBeFalse()
        ->and($build->result()->successful())->toBeTrue();
});

it('emits stderr as diagnostics and never parses it as machine readable output', function () {
    $process = new Factory;
    $process->fake(['*' => $process->describe()
        ->errorOutput('1700000000,,ui,error,this is a log line')
        ->output('1700000001,,ui,say,Real event'),
    ])->preventStrayProcesses();
    $build = (new Packer($process, executablePath: PHP_BINARY))->build('image.pkr.hcl');

    $events = iterator_to_array($build->execute());

    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(DiagnosticEvent::class)
        ->and($events[0]->rawData)->toBe("1700000000,,ui,error,this is a log line\n")
        ->and($events[1])->toBeInstanceOf(UiEvent::class);
});

it('assembles multiple artifacts and build errors into the result', function () {
    $process = new Factory;
    $process->fake(['*' => $process->describe()->output([
        '1700000000,amazon-ebs,artifact-count,2',
        '1700000000,amazon-ebs,artifact,0,builder-id,packer.amazon-ebs',
        '1700000000,amazon-ebs,artifact,0,id,eu-west-1:ami-123',
        '1700000000,amazon-ebs,artifact,0,string,An AMI',
        '1700000000,amazon-ebs,artifact,0,files-count,1',
        '1700000000,amazon-ebs,artifact,0,file,0,manifest.json',
        '1700000000,amazon-ebs,artifact,0,end',
        '1700000000,amazon-ebs,artifact,1,nil',
        '1700000000,amazon-ebs,artifact,1,end',
        '1700000001,docker,artifact-count,1',
        '1700000001,docker,artifact,0,id,sha256:abc',
        '1700000001,docker,artifact,0,end',
        '1700000002,broken,error,plugin failed',
    ])->exitCode(1)])->preventStrayProcesses();
    $build = (new Packer($process, executablePath: PHP_BINARY))->build('image.pkr.hcl');

    iterator_to_array($build->execute());
    $result = $build->result();

    expect($result->successful())->toBeFalse()
        ->and($result)->toBeInstanceOf(PackerResult::class)
        ->and($result->exitCode)->toBe(1)
        ->and($result->artifacts)->toHaveCount(3)
        ->and($result->artifacts[0]->target)->toBe('amazon-ebs')
        ->and($result->artifacts[0]->id)->toBe('eu-west-1:ami-123')
        ->and($result->artifacts[0]->files)->toBe(['manifest.json'])
        ->and($result->artifacts[1]->isNull)->toBeTrue()
        ->and($result->artifacts[2]->target)->toBe('docker')
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->message)->toBe('plugin failed')
        ->and(fn () => $result->throw())->toThrow(PackerBuildFailedException::class);

    try {
        $result->throw();
    } catch (PackerBuildFailedException $exception) {
        expect($exception->result)->toBe($result);
    }
});

it('interrupts the process when iteration ends early', function () {
    $process = new Factory;
    $process->fake(['*' => $process->describe()
        ->output('1700000000,,ui,say,First')
        ->output('1700000001,,ui,say,Second')
        ->runsFor(100),
    ])->preventStrayProcesses();
    $build = (new Packer($process, executablePath: PHP_BINARY, cancellationGraceSeconds: 0))
        ->build('image.pkr.hcl');

    foreach ($build->execute() as $event) {
        expect($event)->toBeInstanceOf(UiEvent::class);
        break;
    }

    expect($build->result()->interrupted)->toBeTrue()
        ->and($build->result()->successful())->toBeFalse()
        ->and(fn () => $build->result()->throw())->toThrow(PackerInterruptedException::class);
});

it('supports cancelling a retained iterator after iteration ends early', function () {
    $process = new Factory;
    $process->fake(['*' => $process->describe()
        ->output('1700000000,,ui,say,First')
        ->runsFor(100),
    ])->preventStrayProcesses();
    $build = (new Packer($process, executablePath: PHP_BINARY, cancellationGraceSeconds: 0))
        ->build('image.pkr.hcl');
    $events = $build->execute();

    foreach ($events as $event) {
        expect($event)->toBeInstanceOf(UiEvent::class);
        break;
    }

    expect(fn () => $build->result())->toThrow(PackerResultNotReadyException::class);

    $build->cancel();

    expect($build->result()->interrupted)->toBeTrue();
});

it('supports explicit cancellation before consuming events', function () {
    $process = new Factory;
    $process->fake(['*' => $process->describe()->runsFor(100)])->preventStrayProcesses();
    $build = (new Packer($process, executablePath: PHP_BINARY, cancellationGraceSeconds: 0))
        ->build('image.pkr.hcl');
    $events = $build->execute();

    $executionProperty = new ReflectionProperty($build, 'execution');
    $execution = $executionProperty->getValue($build);
    $processProperty = new ReflectionProperty(CommandExecution::class, 'process');
    $invokedProcess = $processProperty->getValue($execution);

    expect($invokedProcess)->toBeInstanceOf(FakeInvokedProcess::class);

    $build->cancel();

    expect($build->result()->interrupted)->toBeTrue()
        ->and($invokedProcess->running())->toBeFalse();

    if (PHP_OS_FAMILY !== 'Windows') {
        expect($invokedProcess->hasReceivedSignal(defined('SIGINT') ? SIGINT : 2))->toBeTrue();
    }

    iterator_to_array($events);
});

it('converts an Illuminate process timeout into a typed completed result', function () {
    $symfonyProcess = new SymfonyProcess(['packer']);
    $symfonyTimeout = new SymfonyProcessTimedOutException(
        $symfonyProcess,
        SymfonyProcessTimedOutException::TYPE_GENERAL,
    );
    $timeout = new IlluminateProcessTimedOutException(
        $symfonyTimeout,
        new FakeProcessResult(exitCode: 124),
    );
    $invokedProcess = new class(new FakeProcessDescription, $timeout) extends FakeInvokedProcess
    {
        public function __construct(
            FakeProcessDescription $description,
            private readonly IlluminateProcessTimedOutException $timeout,
        ) {
            $description->runsFor(1);
            parent::__construct('packer build', $description);
        }

        public function ensureNotTimedOut()
        {
            throw $this->timeout;
        }
    };
    $execution = new CommandExecution($invokedProcess, new SplQueue, 0, hrtime(true));

    expect(iterator_to_array($execution->events()))->toBeArray()
        ->and($execution->result()->timedOut)->toBeTrue()
        ->and($execution->result()->interrupted)->toBeFalse()
        ->and($execution->result()->successful())->toBeFalse()
        ->and(fn () => $execution->result()->throw())->toThrow(PackerTimedOutException::class);
});

it('converts asynchronous process failures into a typed completed result', function () {
    $invokedProcess = new class('packer build', new FakeProcessDescription) extends FakeInvokedProcess
    {
        public function running()
        {
            throw new RuntimeException('Unable to poll process.');
        }
    };
    $execution = new CommandExecution($invokedProcess, new SplQueue, 0, hrtime(true));

    expect(iterator_to_array($execution->events()))->toBeArray()
        ->and($execution->result()->processFailed)->toBeTrue()
        ->and($execution->result()->successful())->toBeFalse()
        ->and(fn () => $execution->result()->throw())->toThrow(PackerProcessException::class);
});

it('wraps process start failures in a package exception', function () {
    $process = new Factory;
    $process->fake(fn () => new stdClass)->preventStrayProcesses();

    $build = (new Packer($process, executablePath: PHP_BINARY))->build('image.pkr.hcl');

    expect(fn () => $build->execute())
        ->toThrow(PackerProcessStartException::class);
});
