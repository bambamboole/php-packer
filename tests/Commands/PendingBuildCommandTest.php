<?php
declare(strict_types=1);

use Bambamboole\Packer\Commands\PendingBuildCommand;
use Bambamboole\Packer\Enums\OnError;
use Bambamboole\Packer\Exceptions\PackerCommandAlreadyExecutedException;
use Bambamboole\Packer\Packer;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;

it('configures every supported build option fluently', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result()])->preventStrayProcesses();
    $executable = realpath(PHP_BINARY) ?: PHP_BINARY;

    $build = (new Packer($process, executablePath: PHP_BINARY))->build('/workspace/image.pkr.hcl');

    expect($build)->toBeInstanceOf(PendingBuildCommand::class);
    $process->assertNothingRan();

    $configured = $build
        ->workingDirectory('/workspace')
        ->environment(['PACKER_LOG' => '0', 'REMOVE_ME' => false])
        ->environmentVariable('PACKER_LOG', '1')
        ->timeout(123)
        ->variables(['region' => 'us-east-1', 'family' => 'ubuntu'])
        ->variable('region', 'eu-west-1')
        ->variableFiles('/workspace/common.pkrvars.hcl')
        ->variableFile('/workspace/prod.pkrvars.hcl')
        ->only('amazon-ebs.main', 'docker.app')
        ->only('null.final')
        ->except('null.skip')
        ->force()
        ->onError(OnError::Abort)
        ->parallelBuilds(2)
        ->warnOnUndeclaredVariables()
        ->skipEnforcement();

    expect($configured)->toBe($build);

    iterator_to_array($build->execute());

    $process->assertRan(function (PendingProcess $pending) use ($executable): bool {
        return $pending->command === [
            $executable,
            'build',
            '-machine-readable',
            '-force',
            '-on-error',
            'abort',
            '-parallel-builds',
            '2',
            '-only',
            'amazon-ebs.main,docker.app,null.final',
            '-except',
            'null.skip',
            '-var',
            'region=eu-west-1',
            '-var',
            'family=ubuntu',
            '-var-file',
            '/workspace/common.pkrvars.hcl',
            '-var-file',
            '/workspace/prod.pkrvars.hcl',
            '-warn-on-undeclared-var',
            '-skip-enforcement',
            '/workspace/image.pkr.hcl',
        ]
            && $pending->path === '/workspace'
            && $pending->environment === ['PACKER_LOG' => '1', 'REMOVE_ME' => false]
            && $pending->timeout === 123
            && $pending->tty === false;
    });
});

it('rejects invalid build configuration as it is supplied', function () {
    $packer = new Packer(executablePath: PHP_BINARY);

    expect(fn () => $packer->build(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->build('image.pkr.hcl')->workingDirectory('  '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->build('image.pkr.hcl')->timeout(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->build('image.pkr.hcl')->environment([0 => 'value']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->build('image.pkr.hcl')->environmentVariable('', 'value'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->build('image.pkr.hcl')->variables(['region' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->build('image.pkr.hcl')->variable('', 'value'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->build('image.pkr.hcl')->variableFile(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->build('image.pkr.hcl')->only(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->build('image.pkr.hcl')->except(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->build('image.pkr.hcl')->parallelBuilds(-1))->toThrow(InvalidArgumentException::class);
});

it('locks configuration and only executes once', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result()])->preventStrayProcesses();
    $build = (new Packer($process, executablePath: PHP_BINARY))->build('image.pkr.hcl');

    $events = $build->execute();

    expect(fn () => $build->force())->toThrow(PackerCommandAlreadyExecutedException::class)
        ->and(fn () => $build->execute())->toThrow(PackerCommandAlreadyExecutedException::class);

    iterator_to_array($events);
});
