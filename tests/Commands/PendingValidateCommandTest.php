<?php
declare(strict_types=1);

use Bambamboole\Packer\Commands\PendingValidateCommand;
use Bambamboole\Packer\Exceptions\PackerCommandAlreadyExecutedException;
use Bambamboole\Packer\Exceptions\PackerCommandFailedException;
use Bambamboole\Packer\Packer;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;

it('configures every supported validate option fluently', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result()])->preventStrayProcesses();
    $executable = realpath(PHP_BINARY) ?: PHP_BINARY;

    $validate = (new Packer($process, executablePath: PHP_BINARY))->validate('/workspace/image.pkr.hcl');

    expect($validate)->toBeInstanceOf(PendingValidateCommand::class);
    $process->assertNothingRan();

    $configured = $validate
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
        ->syntaxOnly()
        ->evaluateDataSources()
        ->warnOnUndeclaredVariables(false);

    expect($configured)->toBe($validate);

    iterator_to_array($validate->execute());

    $process->assertRan(function (PendingProcess $pending) use ($executable): bool {
        return $pending->command === [
            $executable,
            'validate',
            '-machine-readable',
            '-syntax-only',
            '-evaluate-datasources',
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
            '-no-warn-undeclared-var',
            '/workspace/image.pkr.hcl',
        ]
            && $pending->path === '/workspace'
            && $pending->environment === ['PACKER_LOG' => '1', 'REMOVE_ME' => false]
            && $pending->timeout === 123
            && $pending->tty === false;
    });
});

it('keeps validate warnings enabled by default', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result()])->preventStrayProcesses();
    $executable = realpath(PHP_BINARY) ?: PHP_BINARY;
    $validate = (new Packer($process, executablePath: PHP_BINARY))->validate('image.pkr.hcl');

    iterator_to_array($validate->execute());

    $process->assertRan([
        $executable,
        'validate',
        '-machine-readable',
        'image.pkr.hcl',
    ]);
});

it('rejects invalid validate configuration as it is supplied', function () {
    $packer = new Packer(executablePath: PHP_BINARY);

    expect(fn () => $packer->validate(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->validate('image.pkr.hcl')->variables(['region' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->validate('image.pkr.hcl')->variable('', 'value'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->validate('image.pkr.hcl')->variableFile(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->validate('image.pkr.hcl')->only(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $packer->validate('image.pkr.hcl')->except(''))->toThrow(InvalidArgumentException::class);
});

it('locks validate configuration and only executes once', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result()])->preventStrayProcesses();
    $validate = (new Packer($process, executablePath: PHP_BINARY))->validate('image.pkr.hcl');

    $events = $validate->execute();

    expect(fn () => $validate->syntaxOnly())->toThrow(PackerCommandAlreadyExecutedException::class)
        ->and(fn () => $validate->execute())->toThrow(PackerCommandAlreadyExecutedException::class);

    iterator_to_array($events);
});

it('reports an unsuccessful validation as a command failure', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result(exitCode: 1)])->preventStrayProcesses();
    $validate = (new Packer($process, executablePath: PHP_BINARY))->validate('image.pkr.hcl');

    iterator_to_array($validate->execute());

    expect(fn () => $validate->result()->throw())
        ->toThrow(PackerCommandFailedException::class, 'Packer command failed with exit code 1.');
});
