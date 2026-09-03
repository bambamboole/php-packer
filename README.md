# PHP Packer

A small PHP API for running [HashiCorp Packer](https://developer.hashicorp.com/packer) commands and consuming their output as a stream of typed events.

## Requirements

- PHP 8.4 or newer
- Packer on `PATH`, or an explicit path to its executable

## Installation

```bash
composer require bambamboole/php-packer
```

## Usage

Packer commands are configured before execution. The process starts when `execute()` is called and yields events as output arrives.

Initialize the plugins required by a template:

```php
use Bambamboole\Packer\Packer;

$packer = new Packer;
$init = $packer->init('/workspace')->upgrade();

foreach ($init->execute() as $event) {
    // Handle the event while Packer is running.
}

$init->result()->throw();
```

Build an image:

```php
use Bambamboole\Packer\Events\UiEvent;

$build = $packer->build('/workspace/image.pkr.hcl')
    ->workingDirectory('/workspace')
    ->variables([
        'region' => 'eu-west-1',
        'environment' => 'production',
    ])
    ->only('amazon-ebs.main')
    ->timeout(3_500);

foreach ($build->execute() as $event) {
    if ($event instanceof UiEvent) {
        echo $event->message;
    }
}

$result = $build->result()->throw();
```

Each pending command can be executed once. Machine-readable output is always enabled.

## Configuration

Use a custom executable when Packer is not available on `PATH`. The cancellation grace period defaults to five seconds.

```php
$packer = new Packer(
    executablePath: '/opt/packer/bin/packer',
    cancellationGraceSeconds: 10,
);

echo $packer->version();
```

Every pending command provides `workingDirectory()`, `environment()`, `environmentVariable()`, and `timeout()`. Init commands add `force()` and `upgrade()`.

Build commands additionally provide:

- `variable()`, `variables()`, `variableFile()`, and `variableFiles()`
- `only()` and `except()`
- `force()`, `onError()`, `parallelBuilds()`, `warnOnUndeclaredVariables()`, and `skipEnforcement()`

`onError()` accepts a case from `Enums\OnError`.

## Events

`execute()` returns a `Traversable` of `Events\Event` instances:

- `UiEvent` for Packer messages
- `ArtifactEvent` and `ArtifactCountEvent` for artifact data
- `BuildErrorEvent` and `ErrorCountEvent` for build errors
- `DiagnosticEvent` for standard error
- `UnknownEvent` for unsupported record types
- `MalformedLineEvent` for invalid machine-readable lines

## Results and failures

After execution completes, `result()` returns a `Data\PackerResult` with the exit code, duration, artifacts, build errors, and timeout or interruption state. Use `successful()`, `failed()`, or `throw()` to inspect the outcome.

An unsuccessful result throws a `PackerResultException` and remains available on the exception:

```php
use Bambamboole\Packer\Exceptions\PackerResultException;

try {
    $result = $build->result()->throw();
} catch (PackerResultException $exception) {
    $result = $exception->result;
}
```

Calling `result()` too early throws `PackerResultNotReadyException`. A missing default executable throws from `init()`, `build()`, or `version()`, while a process start failure throws from `execute()`.

## Cancellation

`cancel()` is idempotent. If iteration stops early, cancel explicitly because a retained generator remains active after `break`:

```php
$events = $build->execute();

try {
    foreach ($events as $event) {
        if (shouldStop($event)) {
            break;
        }
    }
} finally {
    $build->cancel();
}
```

## License

PHP Packer is licensed under the [MIT license](LICENSE.md).
