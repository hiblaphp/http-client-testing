<?php

declare(strict_types=1);

use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Http;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\Promise\Promise;

use function Hibla\await;
use function Hibla\delay;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Server-Sent Events Features', function () {

    it('attempts to reconnect after a handshake connection failure', function () {
        Http::mock()
            ->url('/sse-stream')
            ->sseFailUntilAttempt(2, [
                ['event' => 'reconnected', 'data' => 'hello again', 'id' => '2'],
            ], 'Connection Refused')
            ->register()
        ;

        $events = [];

        await(
            Http::client()
                ->sse('/sse-stream')
                ->withReconnectConfig(new SSEReconnectConfig(
                    maxAttempts: 2,
                    initialDelay: 0.01,
                ))
                ->onEvent(function (SSEEvent $event) use (&$events) {
                    $events[] = $event;
                })
                ->connect()
        );

        Http::assertSSEConnectionAttempts('/sse-stream', 2);
        expect($events)->toHaveCount(1);
        expect($events[0]->event)->toBe('reconnected');
    });

    it('does not send Last-Event-ID on reconnect if the first connection failed immediately', function () {
        Http::mock()
            ->url('/sse-reconnect')
            ->sseFailWithSequence(
                [['error' => 'Connection lost', 'retryable' => true]],
                [['id' => 'event-2', 'data' => 'reconnected successfully']]
            )
            ->register()
        ;

        $events = [];

        await(
            Http::client()
                ->sse('/sse-reconnect')
                ->withReconnectConfig(new SSEReconnectConfig(
                    maxAttempts: 2,
                    initialDelay: 0.01,
                ))
                ->onEvent(function (SSEEvent $event) use (&$events) {
                    $events[] = $event;
                })
                ->connect()
        );

        Http::assertSSEConnectionAttempts('/sse-reconnect', 2);

        $lastRequest = Http::getLastRequest();
        expect($lastRequest->hasHeader('Last-Event-ID'))->toBeFalse();

        expect($events)->toHaveCount(1);
        expect($events[0]->id)->toBe('event-2');
    });

    it('reconnects with Last-Event-ID after a mid-stream connection drop', function () {
        Http::mock('GET')->url('/mid-stream-drop')
            ->sseDropAfterEvents([
                ['id' => '100', 'data' => 'first message'],
            ], 'Connection lost', true)
            ->register()
        ;

        Http::mock('GET')->url('/mid-stream-drop')
            ->sseExpectLastEventId('100', [
                ['id' => '101', 'data' => 'recovered message'],
            ])
            ->register()
        ;

        $events = [];
        $completed = new Promise();

        await(
            Http::client()
                ->sse('/mid-stream-drop')
                ->withReconnectConfig(new SSEReconnectConfig(
                    maxAttempts: 3,
                    initialDelay: 0.01
                ))
                ->onEvent(function (SSEEvent $event) use (&$events, $completed) {
                    $events[] = $event;
                    if (count($events) === 2) {
                        $completed->resolve(true);
                    }
                })
                ->connect()
        );

        await($completed);

        expect($events)->toHaveCount(2);
        expect($events[0]->data)->toBe('first message');
        expect($events[1]->data)->toBe('recovered message');

        Http::assertSSEReconnectionOccurred('/mid-stream-drop');
    });

    it('handles periodic background events without race conditions', function () {
        Http::mock('GET')->url('/periodic')
            ->sseWithLimitedEvents(3, fn ($i) => ['id' => (string)$i, 'data' => "chunk {$i}"])
            ->dataStreamTransferLatency(0.05)
            ->register()
        ;

        $events = [];
        $completed = new Promise();

        await(
            Http::client()
                ->sse('/periodic')
                ->onEvent(function (SSEEvent $event) use (&$events, $completed) {
                    $events[] = $event;
                    if (count($events) === 3) {
                        $completed->resolve(true);
                    }
                })
                ->connect()
        );

        await($completed);

        expect($events)->toHaveCount(3);
        expect($events[0]->data)->toBe('chunk 0');
        expect($events[2]->data)->toBe('chunk 2');
    });

    it('respects the retry directive sent by the server to override local config', function () {
        $url = '/server-directed-retry';

        Http::mock('GET')->url($url)
            ->sseWithRetryDirective(200, [['data' => 'setting timer']])
            ->retryableFailure('Drop 1')
            ->register()
        ;

        Http::mock('GET')->url($url)
            ->respondWithSSE([['data' => 'resumed']])
            ->register()
        ;

        $events = [];
        $reconnectTimings = [];
        $startTime = microtime(true);
        $completed = new Promise();

        await(
            Http::client()
                ->sse($url)
                ->withReconnectConfig(new SSEReconnectConfig(
                    enabled: true,
                    initialDelay: 5.0,
                    onReconnect: function () use (&$reconnectTimings, $startTime) {
                        $reconnectTimings[] = microtime(true) - $startTime;
                    }
                ))
                ->onEvent(function (SSEEvent $event) use (&$events, $completed) {
                    $events[] = $event->data;
                    if (count($events) === 2) {
                        $completed->resolve(true);
                    }
                })
                ->connect()
        );

        await($completed);

        expect($reconnectTimings[0])->toBeLessThan(1.0);
        expect($events)->toBe(['setting timer', 'resumed']);
    });

    it('exhausts all retry attempts and eventually rejects the promise', function () {
        $url = '/failing-forever';

        Http::mock('GET')->url($url)
            ->sseFailUntilAttempt(5, [], 'Permanent Glitch')
            ->register()
        ;

        $config = new SSEReconnectConfig(
            maxAttempts: 3,
            initialDelay: 0.01,
        );

        $promise = Http::client()
            ->sse($url)
            ->withReconnectConfig($config)
            ->connect()
        ;

        expect(fn () => await($promise))->toThrow(NetworkException::class);

        Http::assertSSEConnectionAttempts($url, 4);
    });

    it('stops receiving events and cleans up after the client cancels the stream', function () {
        $url = '/cancel-test';

        Http::mock('GET')->url($url)
            ->sseInfiniteStream(fn ($i) => ['data' => "msg {$i}"])
            ->dataStreamTransferLatency(0.05)
            ->register()
        ;

        $count = 0;
        $promise = Http::client()
            ->sse($url)
            ->onEvent(function () use (&$count) {
                $count++;
            })
            ->connect()
        ;

        $response = await($promise);

        await(delay(0.2));
        $response->close();

        $snapShot = $count;

        await(delay(0.2));

        expect($snapShot)->toBeGreaterThanOrEqual(3);
        expect($count)->toBe($snapShot);
    });

    it('handles a complex sequence of different failure types correctly', function () {
        $url = '/flaky-server';

        Http::mock('GET')->url($url)
            ->sseFailWithSequence([
                ['status' => 503, 'error' => 'Busy'],
                ['error' => 'Connection timed out', 'delay' => 0.05],
                ['error' => 'Refused'],
            ], [['data' => 'finally connected']])
            ->register()
        ;

        $events = [];
        $completed = new Promise();

        await(
            Http::client()
                ->sse($url)
                ->withReconnectConfig(new SSEReconnectConfig(
                    maxAttempts: 5,
                    initialDelay: 0.01
                ))
                ->onEvent(function (SSEEvent $event) use (&$events, $completed) {
                    $events[] = $event->data;
                    $completed->resolve(true);
                })
                ->connect()
        );

        await($completed);

        expect($events)->toBe(['finally connected']);
        Http::assertSSEConnectionAttempts($url, 4);
    });

    it('preserves multi-type events and comments in a realistic scenario', function () {
        $url = '/rich-content';

        Http::mock('GET')->url($url)
            ->respondWithSSE([
                ['comment' => 'handshake sync'],
                ['event' => 'user_joined', 'data' => 'john', 'id' => '1'],
                ['data' => 'standard message', 'id' => '2'],
                ['event' => 'ping', 'data' => '', 'id' => '3'],
            ])
            ->register()
        ;

        $received = [];
        await(
            Http::client()
                ->sse($url)
                ->onEvent(function (SSEEvent $event) use (&$received) {
                    $received[] = [
                        'type' => $event->event ?? 'message',
                        'data' => $event->data,
                    ];
                })
                ->connect()
        );

        expect($received)->toHaveCount(3);
        expect($received[0])->toBe(['type' => 'user_joined', 'data' => 'john']);
        expect($received[1])->toBe(['type' => 'message', 'data' => 'standard message']);
        expect($received[2]['type'])->toBe('ping');
    });
});
