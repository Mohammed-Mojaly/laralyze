<?php

use Laralyze\Facades\Laralyze;

it('records by default', function () {
    expect(Laralyze::isEnabled())->toBeTrue()
        ->and(Laralyze::isRecording())->toBeTrue();
});

it('stops and starts recording on demand', function () {
    Laralyze::stopRecording();

    expect(Laralyze::isRecording())->toBeFalse();

    Laralyze::startRecording();

    expect(Laralyze::isRecording())->toBeTrue();
});

it('pauses recording inside ignore, including nested calls', function () {
    $recordingInside = null;

    Laralyze::ignore(function () use (&$recordingInside) {
        Laralyze::ignore(fn () => null);

        $recordingInside = Laralyze::isRecording();
    });

    expect($recordingInside)->toBeFalse()
        ->and(Laralyze::isRecording())->toBeTrue();
});

it('resumes recording when an ignored callback throws', function () {
    expect(fn () => Laralyze::ignore(fn () => throw new RuntimeException))
        ->toThrow(RuntimeException::class);

    expect(Laralyze::isRecording())->toBeTrue();
});

it('returns the value of the ignored callback', function () {
    expect(Laralyze::ignore(fn () => 42))->toBe(42);
});

it('returns the callback value from rescue when nothing fails', function () {
    expect(Laralyze::rescue(fn () => 'ok', 'fallback'))->toBe('ok');
});

it('swallows exceptions in rescue and passes them to the handler', function () {
    $caught = null;
    Laralyze::handleExceptionsUsing(function (Throwable $e) use (&$caught) {
        $caught = $e;
    });

    $result = Laralyze::rescue(fn () => throw new RuntimeException('boom'), 'fallback');

    expect($result)->toBe('fallback')
        ->and($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toBe('boom');
});

it('never throws from rescue, even when the handler throws', function () {
    Laralyze::handleExceptionsUsing(fn () => throw new LogicException);

    $result = Laralyze::rescue(fn () => throw new RuntimeException, fn () => 'lazy default');

    expect($result)->toBe('lazy default');
});

it('stays silent by default when rescue catches an exception', function () {
    expect(Laralyze::rescue(fn () => throw new RuntimeException))->toBeNull();
});
