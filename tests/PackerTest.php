<?php
declare(strict_types=1);

use Bambamboole\Packer\Packer;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Factory;

it('returns the installed Packer version', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result(output: "Packer v1.16.0\n")])->preventStrayProcesses();
    $executable = realpath(PHP_BINARY) ?: PHP_BINARY;

    $version = (new Packer($process, executablePath: PHP_BINARY))->version();

    expect($version)->toBe('1.16.0');
    $process->assertRan([$executable, '--version']);
});

it('reports a failed Packer invocation', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result(errorOutput: 'packer failed', exitCode: 1)])->preventStrayProcesses();
    $packer = new Packer($process, executablePath: PHP_BINARY);

    expect(fn () => $packer->version())->toThrow(ProcessFailedException::class);
    $process->assertRan([realpath(PHP_BINARY) ?: PHP_BINARY, '--version']);
});

it('rejects unexpected version output', function () {
    $process = new Factory;
    $process->fake(['*' => $process->result(output: 'unknown')])->preventStrayProcesses();
    $packer = new Packer($process, executablePath: PHP_BINARY);

    expect(fn () => $packer->version())
        ->toThrow(UnexpectedValueException::class, 'Unexpected Packer version output: unknown');
    $process->assertRan([realpath(PHP_BINARY) ?: PHP_BINARY, '--version']);
});
