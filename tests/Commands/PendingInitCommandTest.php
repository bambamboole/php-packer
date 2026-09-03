<?php
declare(strict_types=1);

use Bambamboole\Packer\Commands\PendingCommand;
use Bambamboole\Packer\Commands\PendingInitCommand;
use Bambamboole\Packer\Exceptions\PackerCommandAlreadyExecutedException;
use Bambamboole\Packer\Exceptions\PackerCommandFailedException;
use Bambamboole\Packer\Packer;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;

it('configures and executes an init command', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result()])->preventStrayProcesses();
    $executable = realpath(PHP_BINARY) ?: PHP_BINARY;

    $init = (new Packer($process, executablePath: PHP_BINARY))->init('/workspace/image.pkr.hcl');

    expect($init)
        ->toBeInstanceOf(PendingCommand::class)
        ->toBeInstanceOf(PendingInitCommand::class);
    $process->assertNothingRan();

    $configured = $init
        ->workingDirectory('/workspace')
        ->environment(['PACKER_LOG' => '0', 'REMOVE_ME' => false])
        ->environmentVariable('PACKER_LOG', '1')
        ->timeout(123)
        ->force()
        ->upgrade();

    expect($configured)->toBe($init);

    iterator_to_array($init->execute());

    $process->assertRan(function (PendingProcess $pending) use ($executable): bool {
        return $pending->command === [
            $executable,
            'init',
            '-machine-readable',
            '-force',
            '-upgrade',
            '/workspace/image.pkr.hcl',
        ]
            && $pending->path === '/workspace'
            && $pending->environment === ['PACKER_LOG' => '1', 'REMOVE_ME' => false]
            && $pending->timeout === 123
            && $pending->tty === false;
    });
});

it('validates and locks init configuration', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result()])->preventStrayProcesses();
    $packer = new Packer($process, executablePath: PHP_BINARY);

    expect(fn () => $packer->init(''))->toThrow(InvalidArgumentException::class);

    $init = $packer->init('image.pkr.hcl');
    $events = $init->execute();

    expect(fn () => $init->upgrade())->toThrow(PackerCommandAlreadyExecutedException::class)
        ->and(fn () => $init->execute())->toThrow(PackerCommandAlreadyExecutedException::class);

    iterator_to_array($events);
});

it('reports an unsuccessful init as a command failure', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result(exitCode: 1)])->preventStrayProcesses();
    $init = (new Packer($process, executablePath: PHP_BINARY))->init('image.pkr.hcl');

    iterator_to_array($init->execute());

    expect(fn () => $init->result()->throw())
        ->toThrow(PackerCommandFailedException::class, 'Packer command failed with exit code 1.');
});
