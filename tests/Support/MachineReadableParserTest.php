<?php
declare(strict_types=1);

use Bambamboole\Packer\Events\ArtifactCountEvent;
use Bambamboole\Packer\Events\ArtifactEvent;
use Bambamboole\Packer\Events\BuildErrorEvent;
use Bambamboole\Packer\Events\ErrorCountEvent;
use Bambamboole\Packer\Events\MalformedLineEvent;
use Bambamboole\Packer\Events\UiEvent;
use Bambamboole\Packer\Events\UnknownEvent;
use Bambamboole\Packer\Support\MachineReadableParser;

it('parses multiple LF and CRLF terminated lines from one chunk', function () {
    $parser = new MachineReadableParser;

    $events = $parser->push(
        "1700000000,,ui,say,Starting\n".
        "1700000001,amazon-ebs,ui,message,Building\r\n"
    );

    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(UiEvent::class)
        ->and($events[0]->target)->toBeNull()
        ->and($events[0]->message)->toBe('Starting')
        ->and($events[1])->toBeInstanceOf(UiEvent::class)
        ->and($events[1]->target)->toBe('amazon-ebs')
        ->and($events[1]->rawLine)->toBe('1700000001,amazon-ebs,ui,message,Building');
});

it('buffers a line split across arbitrary chunks', function () {
    $parser = new MachineReadableParser;

    expect($parser->push('1700000000,,ui,mes'))->toBe([]);

    $events = $parser->push("sage,Hello\n");

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(UiEvent::class)
        ->and($events[0]->message)->toBe('Hello');
});

it('flushes a final line without a newline', function () {
    $parser = new MachineReadableParser;

    expect($parser->push('1700000000,,ui,error,Failed'))->toBe([]);

    $events = $parser->finish();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(UiEvent::class)
        ->and($events[0]->subtype)->toBe('error')
        ->and($events[0]->message)->toBe('Failed');
});

it('decodes Packer data escapes without changing the raw line', function () {
    $parser = new MachineReadableParser;
    $line = '1700000000,,ui,message,one%!(PACKER_COMMA)two\\nthree\\rfour';

    $events = $parser->push($line."\n");

    expect($events[0])->toBeInstanceOf(UiEvent::class)
        ->and($events[0]->message)->toBe("one,two\nthree\rfour")
        ->and($events[0]->rawLine)->toBe($line);
});

it('preserves unknown event types and malformed lines as events', function () {
    $parser = new MachineReadableParser;

    $events = $parser->push(
        "1700000000,target,future-event,a%!(PACKER_COMMA)b\n".
        "not-a-timestamp,,ui,say,Still streaming\n".
        "1700000001,,ui,say,Continued\n"
    );

    expect($events)->toHaveCount(3)
        ->and($events[0])->toBeInstanceOf(UnknownEvent::class)
        ->and($events[0]->type)->toBe('future-event')
        ->and($events[0]->data)->toBe(['a,b'])
        ->and($events[1])->toBeInstanceOf(MalformedLineEvent::class)
        ->and($events[1]->rawLine)->toBe('not-a-timestamp,,ui,say,Still streaming')
        ->and($events[2])->toBeInstanceOf(UiEvent::class)
        ->and($events[2]->message)->toBe('Continued');
});

it('parses counts and structured build errors', function () {
    $parser = new MachineReadableParser;

    $events = $parser->push(
        "1700000000,,error-count,1\n".
        "1700000001,broken,error,plugin failed\n".
        "1700000002,docker,artifact-count,2\n"
    );

    expect($events[0])->toBeInstanceOf(ErrorCountEvent::class)
        ->and($events[0]->count)->toBe(1)
        ->and($events[1])->toBeInstanceOf(BuildErrorEvent::class)
        ->and($events[1]->message)->toBe('plugin failed')
        ->and($events[2])->toBeInstanceOf(ArtifactCountEvent::class)
        ->and($events[2]->count)->toBe(2);
});

it('parses every documented artifact line shape', function () {
    $parser = new MachineReadableParser;

    $events = $parser->push(
        "1700000000,docker,artifact,0,builder-id,packer.docker\n".
        "1700000000,docker,artifact,0,id,sha256:123\n".
        "1700000000,docker,artifact,0,string,Docker image\n".
        "1700000000,docker,artifact,0,files-count,1\n".
        "1700000000,docker,artifact,0,file,0,manifest.json\n".
        "1700000000,docker,artifact,1,nil\n".
        "1700000000,docker,artifact,1,end\n"
    );

    expect($events)->toHaveCount(7)
        ->and($events)->each->toBeInstanceOf(ArtifactEvent::class)
        ->and($events[4]->fileIndex)->toBe(0)
        ->and($events[4]->value)->toBe('manifest.json')
        ->and($events[5]->kind)->toBe('nil')
        ->and($events[5]->value)->toBeNull();
});
