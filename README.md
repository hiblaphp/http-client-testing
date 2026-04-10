# HTTP Client Testing

<p align="center">
  <a href="https://packagist.org/packages/hiblaphp/http-client-testing">
    <img src="https://img.shields.io/packagist/v/hiblaphp/http-client-testing.svg?style=flat-square" alt="Latest Version">
  </a>
  <a href="https://packagist.org/packages/hiblaphp/http-client-testing">
    <img src="https://img.shields.io/packagist/dt/hiblaphp/http-client-testing.svg?style=flat-square" alt="Total Downloads">
  </a>
  <a href="https://github.com/hiblaphp/http-client-testing/blob/main/LICENSE">
    <img src="https://img.shields.io/packagist/l/hiblaphp/http-client-testing.svg?style=flat-square" alt="License">
  </a>
  <a href="https://github.com/hiblaphp/http-client-testing/actions">
    <img src="https://img.shields.io/github/actions/workflow/status/hiblaphp/http-client-testing/tests.yml?branch=main&style=flat-square" alt="Build Status">
  </a>
</p>

---

**A full HTTP request simulation framework for [Hibla HTTP Client](https://github.com/hiblaphp/http-client).** Mock responses, simulate network conditions, record every request, and assert on exactly what your application sent, without changing a single line of production code.

Whether you are unit testing a service in isolation, running integration tests against a staging environment, or simulating catastrophic network failures, the testing plugin gives you the tools to do it cleanly and expressively in both [Pest](https://pestphp.com) and [PHPUnit](https://phpunit.de).

```php
Http::startTesting();

Http::mock('POST')
    ->url('https://api.example.com/orders')
    ->expectHeader('Authorization', 'Bearer secret')
    ->expectJson(['item' => 'book', 'qty' => 2])
    ->respondWithStatus(201)
    ->respondJson(['id' => 'ord-001', 'status' => 'confirmed'])
    ->register();

$order = $service->placeOrder(item: 'book', qty: 2);

expect($order->status)->toBe('confirmed');

Http::assertRequestMade('POST', 'https://api.example.com/orders');
Http::assertBearerTokenSent('secret');
Http::assertRequestJsonContains('POST', 'https://api.example.com/orders', ['item' => 'book']);

Http::stopTesting();
```

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Getting Started](#getting-started)
  - [Option 1: Static Facade](#option-1-static-facade-recommended-for-most-projects)
  - [Option 2: Direct Dependency Injection](#option-2-direct-dependency-injection)
  - [Choosing Between the Two](#choosing-between-the-two)
- [Mocking Requests](#mocking-requests)
  - [Method Matching](#method-matching)
  - [URL Patterns](#url-patterns)
  - [Response Bodies](#response-bodies)
  - [Response Headers](#response-headers)
  - [Streaming Body Chunks](#streaming-body-chunks)
  - [Persistent Mocks](#persistent-mocks)
  - [Latency Simulation](#latency-simulation)
- [Matching Request Expectations](#matching-request-expectations)
  - [Header Matching](#header-matching)
  - [JSON Body Matching](#json-body-matching)
  - [Body Pattern Matching](#body-pattern-matching)
  - [Cookie Matching](#cookie-matching)
  - [Custom Closure Matcher](#custom-closure-matcher)
- [Simulating Failures](#simulating-failures)
- [Retry Sequences](#retry-sequences)
- [File Operations](#file-operations)
  - [Downloads](#mocking-file-downloads)
  - [Uploads](#mocking-file-uploads)
- [Cookie Mocking](#cookie-mocking)
- [Server-Sent Events](#server-sent-events-sse)
  - [Basic SSE](#basic-sse-response)
  - [Streaming SSE](#streaming-sse-periodic-emitter)
  - [SSE Retry Sequences](#sse-retry-sequences)
- [Network Simulation](#network-simulation)
- [Passthrough Mode](#passthrough-mode)
- [Assertions](#assertions)
  - [Request Assertions](#request-assertions)
  - [Header Assertions](#header-assertions)
  - [Request Body Assertions](#request-body-assertions)
  - [Cookie Assertions](#cookie-assertions)
  - [Download Assertions](#download-assertions)
  - [Upload Assertions](#upload-assertions)
  - [Stream Assertions](#stream-assertions)
  - [SSE Assertions](#sse-assertions)
- [Inspecting Recorded Requests](#inspecting-recorded-requests)
- [Debugging](#debugging)
- [API Reference](#api-reference)
- [Full Test Examples](#full-test-examples)
- [Development](#development)
- [Credits](#credits)
- [License](#license)

---

## Requirements

- PHP **8.3** or higher
- `hiblaphp/http-client`

---

## Installation

Install as a development dependency via Composer:

```bash
composer require --dev hiblaphp/http-client-testing
```

No additional service providers, configuration files, or boot steps are required. The plugin integrates with the HTTP client automatically once `Http::startTesting()` is called.

---

## Getting Started

### Option 1: Static Facade (Recommended for Most Projects)

`Http::startTesting()` swaps the real cURL handler for the testing handler globally. Every `Http::` call your application makes is intercepted automatically, with no changes to application code required.

```php
use Hibla\HttpClient\Http;

// Pest
beforeEach(fn () => Http::startTesting());
afterEach(fn () => Http::stopTesting());

// PHPUnit
protected function setUp(): void    { Http::startTesting(); }
protected function tearDown(): void { Http::stopTesting(); }
```

Use `Http::resetTesting()` between tests when you want to clear recorded requests and mocks without fully disabling testing mode:

```php
// Pest — useful in a single describe block with many cases
afterEach(fn () => Http::resetTesting());
```

### Option 2: Direct Dependency Injection

If your application wires HTTP clients through a service container or constructor injection, use `TestingHttpHandler` directly. Because it extends `HttpHandler`, it can be swapped in anywhere a real handler is expected.

```php
use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\Testing\TestingHttpHandler;

$handler = new TestingHttpHandler();
$client  = (new HttpClient())->withHandler($handler);

// Inject $client into the service under test
$service = new UserService($client);
```

Configure mocks and run assertions on the handler instance:

```php
$handler->mock('GET')
    ->url('https://api.example.com/users')
    ->respondJson(['users' => []])
    ->register();

$service->listUsers();

$handler->assertRequestMade('GET', 'https://api.example.com/users');
$handler->assertBearerTokenSent('my-token');
```

With Pest:

```php
use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\Testing\TestingHttpHandler;

beforeEach(function () {
    $this->handler = new TestingHttpHandler();
    $this->client  = (new HttpClient())->withHandler($this->handler);
    $this->service = new UserService($this->client);
});

afterEach(fn () => $this->handler->reset());

it('fetches users', function () {
    $this->handler->mock('GET')
        ->url('https://api.example.com/users')
        ->respondJson(['users' => [['id' => 1]]])
        ->register();

    $users = $this->service->listUsers();

    expect($users)->toHaveCount(1);
    $this->handler->assertRequestMade('GET', 'https://api.example.com/users');
});
```

### Choosing Between the Two

| | Facade (`Http::`) | Direct DI |
|---|---|---|
| Setup effort | Minimal | Requires wiring |
| Works with static `Http::` calls | Yes | Only injected client |
| Works with DI containers | Yes | Yes |
| Multiple independent handlers | No | Yes |
| Isolates only one service | No | Yes |

All mock builder methods and every assertion available on `Http::` are equally available on the `TestingHttpHandler` instance. There is no difference in capability between the two approaches.

---

## Mocking Requests

All mocking is done through `Http::mock()` (or `$handler->mock()` in DI mode), which returns a fluent `MockRequestBuilder`. You must call `->register()` at the end of every chain to activate the mock. A builder that is not registered has no effect and will not intercept any requests.

```php
Http::mock('GET')
    ->url('https://api.example.com/users')
    ->respondWithStatus(200)
    ->respondJson(['users' => []])
    ->register();
```


### Method Matching

```php
Http::mock('GET')->url('...')->respondJson([...])->register();
Http::mock('POST')->url('...')->respondWithStatus(201)->register();
Http::mock('PUT')->url('...')->respondWithStatus(200)->register();
Http::mock('PATCH')->url('...')->respondWithStatus(200)->register();
Http::mock('DELETE')->url('...')->respondWithStatus(204)->register();

// Match any HTTP method
Http::mock('*')->url('https://api.example.com/*')->respondWithStatus(200)->register();
```

### URL Patterns

URL patterns support `fnmatch` wildcards. Trailing slashes are handled leniently; a pattern without one matches URLs with or without.

```php
Http::mock('GET')->url('https://api.example.com/users/*')->respondJson([...])->register();
Http::mock('GET')->url('https://api.example.com/*/profile')->respondJson([...])->register();
Http::mock('GET')->url('https://*.example.com/*')->respondJson([...])->register();
```

### Response Bodies

```php
// Plain string
Http::mock()->url('...')->respondWith('hello world')->register();

// JSON — automatically sets Content-Type: application/json
Http::mock()->url('...')->respondJson(['id' => 1, 'name' => 'Alice'])->register();

// XML — automatically sets Content-Type: application/xml
Http::mock()->url('...')->respondXml('<user><name>Alice</name></user>')->register();

// Status only
Http::mock()->url('...')->respondWithStatus(204)->register();

// Status with body
Http::mock()->url('...')->respondWithStatus(422)->respondJson(['error' => 'Invalid input'])->register();
```

### Response Headers

```php
Http::mock()
    ->url('...')
    ->respondWithStatus(200)
    ->respondWithHeader('X-Request-Id', 'abc-123')
    ->respondWithHeaders([
        'Cache-Control'         => 'no-cache',
        'X-RateLimit-Remaining' => '99',
    ])
    ->respondJson([...])
    ->register();
```

### Streaming Body Chunks

```php
Http::mock('GET')
    ->url('https://api.example.com/stream')
    ->respondWithChunks([
        '{"type":"start"}' . "\n",
        '{"type":"data","value":1}' . "\n",
        '{"type":"data","value":2}' . "\n",
        '{"type":"end"}' . "\n",
    ])
    ->register();
```

### Persistent Mocks

By default a mock is consumed after being matched once. Use `->persistent()` to reuse it across multiple requests:

```php
Http::mock('GET')
    ->url('https://api.example.com/health')
    ->respondWithStatus(200)
    ->respondJson(['status' => 'ok'])
    ->persistent()
    ->register();
```

### Latency Simulation

All latency methods are non-blocking. Delays are applied asynchronously, so concurrent requests are interleaved rather than queuing behind one another. This lets you simulate realistic concurrent workloads without artificially serialising your test requests.

```php
// Fixed delay before responding
Http::mock()->url('...')->latency(0.5)->respondJson([...])->register();

// Slow response alias
Http::mock()->url('...')->slowResponse(2.0)->respondJson([...])->register();

// Random delay chosen once at registration time
Http::mock()->url('...')->randomLatency(0.1, 0.8)->respondJson([...])->register();

// Persistent mock: fresh random delay on every match
Http::mock()->url('...')->randomPersistentLatency(0.05, 0.3)->respondJson([...])->register();

// Per-chunk latency for downloads, streams, and SSE (seconds per 8KB chunk)
// jitter adds a random ±percentage variation to each chunk delay, simulating an unsteady connection
Http::mock()->url('...')->downloadLargeFile(sizeInKB: 512)->dataStreamTransferLatency(0.05, jitter: 0.2)->register();
```


---

## Matching Request Expectations

Constraints make a mock only match requests that satisfy specific criteria. Unmatched mocks remain in the queue.

### Header Matching

```php
Http::mock('POST')
    ->url('https://api.example.com/orders')
    ->expectHeader('Authorization', 'Bearer my-token')
    ->expectHeader('X-Tenant-Id', 'acme')
    ->respondWithStatus(201)
    ->register();

// Multiple headers at once
Http::mock('POST')
    ->url('...')
    ->expectHeaders([
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
    ])
    ->respondWithStatus(200)
    ->register();
```

### JSON Body Matching

```php
Http::mock('POST')
    ->url('https://api.example.com/users')
    ->expectJson(['name' => 'Alice', 'role' => 'admin'])
    ->respondWithStatus(201)
    ->register();
```

### Body Pattern Matching

```php
Http::mock('POST')
    ->url('...')
    ->expectBody('*"action":"checkout"*')
    ->respondWithStatus(200)
    ->register();
```

### Cookie Matching

```php
Http::mock('GET')
    ->url('...')
    ->expectCookies(['session' => 'abc123', 'theme' => 'dark'])
    ->respondJson([...])
    ->register();
```

### Custom Closure Matcher

```php
use Hibla\HttpClient\Testing\Utilities\RecordedRequest;

Http::mock('POST')
    ->url('...')
    ->expect(function (RecordedRequest $request): bool {
        $json = $request->getJson();
        return isset($json['amount']) && $json['amount'] > 0;
    })
    ->respondWithStatus(200)
    ->register();
```

---

## Simulating Failures

### Hard Failures

```php
Http::mock()->url('...')->fail('Service unavailable')->register();
```

### Timeouts

```php
Http::mock()->url('...')->timeout(30.0)->register();

// Retryable timeout
Http::mock()->url('...')->timeoutFailure(timeoutAfter: 5.0)->register();
```

### Network Errors

```php
Http::mock()->url('...')->networkError('connection')->register(); // Connection failed
Http::mock()->url('...')->networkError('timeout')->register();    // Connection timed out
Http::mock()->url('...')->networkError('resolve')->register();    // DNS failure
Http::mock()->url('...')->networkError('ssl')->register();        // SSL handshake failure
```

### Retryable Failures

```php
Http::mock()->url('...')->retryableFailure('Connection reset by peer')->register();
```

---

## Retry Sequences

These helpers automatically register a series of mocks simulating failures before eventual success, making it straightforward to test retry logic without manually registering each mock.

### Fail Until Attempt N

```php
Http::mock('POST')
    ->url('https://api.example.com/submit')
    ->failUntilAttempt(3) // fails on attempts 1 and 2, succeeds on 3
    ->register();
```

### Timeout Until Attempt N

```php
Http::mock()->url('...')->timeoutUntilAttempt(3, timeoutAfter: 5.0)->register();
```

### HTTP Status Failures Until Success

```php
Http::mock()->url('...')->statusFailuresUntilAttempt(4, failureStatus: 503)->register();
```

### Custom Failure Sequence

```php
Http::mock()
    ->url('...')
    ->failWithSequence(
        failures: [
            'Connection refused',
            ['error' => 'Gateway timeout', 'retryable' => true, 'delay' => 0.2],
            ['status' => 429],
        ],
        successResponse: ['ok' => true]
    )
    ->register();
```

### Mixed and Intermittent Failures

```php
// Cycles through timeout, connection, DNS, SSL failures until success
Http::mock()->url('...')->mixedFailuresUntilAttempt(5)->register();

// Explicit pattern — true = fail, false = succeed
Http::mock()->url('...')->intermittentFailures([true, false, true, true, false])->register();
```

### Rate Limiting with Exponential Backoff

```php
// Returns 429 with Retry-After on attempts 1–3, 200 on attempt 4
Http::mock()->url('...')->rateLimitedUntilAttempt(4)->register();
```

### Gradually Improving Network

```php
// Simulates network recovery: severe delays early, success eventually
Http::mock()->url('...')->slowlyImproveUntilAttempt(5, maxDelay: 10.0)->register();
```

---

## File Operations

### Mocking File Downloads

```php
Http::mock('GET')
    ->url('https://files.example.com/report.pdf')
    ->downloadFile(
        content:     $pdfContent,
        filename:    'report.pdf',
        contentType: 'application/pdf'
    )
    ->register();
```

Large file simulation with generated content:

```php
Http::mock('GET')
    ->url('...')
    ->downloadLargeFile(sizeInKB: 512, filename: 'archive.zip')
    ->register();
```

Slow transfer with per-chunk latency:

```php
Http::mock('GET')
    ->url('...')
    ->downloadLargeFile(sizeInKB: 1024)
    ->dataStreamTransferLatency(seconds: 0.05, jitter: 0.2) // ~50ms ± 20% per 8KB chunk
    ->register();
```

### Mocking File Uploads

Mock the endpoint that receives the upload. The testing handler records the upload source path for assertion:

```php
Http::mock('PUT')
    ->url('https://storage.example.com/uploads/*')
    ->respondWithStatus(200)
    ->respondJson(['uploaded' => true])
    ->register();
```

---

## Cookie Mocking

Use `->setCookie()` or `->setCookies()` to add `Set-Cookie` headers to a mock response. The handler parses and stores them in the cookie jar automatically, exactly as a real response would:

```php
Http::mock('POST')
    ->url('https://auth.example.com/login')
    ->respondWithStatus(200)
    ->setCookie(
        name:     'session',
        value:    'tok-abc123',
        path:     '/',
        secure:   true,
        httpOnly: true,
        sameSite: 'Strict',
        expires:  time() + 3600
    )
    ->register();

// Multiple cookies at once
Http::mock('GET')
    ->url('...')
    ->setCookies([
        'theme' => ['value' => 'dark', 'path' => '/'],
        'lang'  => ['value' => 'en', 'secure' => true],
    ])
    ->register();
```

---

## Server-Sent Events (SSE)

> **Important:** Every SSE mock must use one of the SSE builder methods such as `respondWithSSE()`, `sseWithEventIds()`, `sseInfiniteStream()`, etc. To register itself as an SSE response. Using `respondWith()` or `respondJson()` alone on a mock matched by `Http::sse()` will cause a runtime error. All SSE builder methods call `asSSE()` internally.

### Basic SSE Response

```php
Http::mock('GET')
    ->url('https://api.example.com/events')
    ->respondWithSSE([
        ['event' => 'connected', 'data' => '{"status":"ready"}', 'id' => '1'],
        ['event' => 'update',    'data' => '{"value":42}',        'id' => '2'],
        ['event' => 'done',      'data' => ''],
    ])
    ->register();
```

### Adding Events Individually

```php
Http::mock('GET')
    ->url('...')
    ->respondWithSSE([]) // initialise as SSE
    ->addSSEEvent(data: '{"status":"ready"}', event: 'connected', id: '1')
    ->addSSEEvent(data: '{"value":42}',       event: 'update',    id: '2')
    ->register();
```

### SSE with Retry Interval

```php
Http::mock('GET')
    ->url('...')
    ->sseWithRetry(events: [['data' => '{"connected":true}']], retryMs: 5000)
    ->register();
```

### SSE with Retry Directive Only

```php
Http::mock('GET')
    ->url('...')
    ->sseWithRetryDirective(retryMs: 3000, events: [['data' => '{"connected":true}']])
    ->register();
```

### SSE with Event IDs

```php
Http::mock('GET')
    ->url('...')
    ->sseWithEventIds([
        ['id' => '1', 'data' => '{"msg":"first"}'],
        ['id' => '2', 'data' => '{"msg":"second"}'],
        ['id' => '3', 'data' => '{"msg":"third"}'],
    ])
    ->register();
```

### SSE with Keepalive Heartbeats

```php
Http::mock('GET')
    ->url('...')
    ->sseWithKeepalive(
        dataEvents:     [
            ['data' => '{"status":"processing"}'],
            ['data' => '{"status":"done"}'],
        ],
        keepaliveCount: 5
    )
    ->register();
```

### SSE — Heartbeat Only

```php
Http::mock('GET')
    ->url('...')
    ->sseHeartbeatOnly(heartbeatCount: 10)
    ->register();
```

### SSE with Multiple Event Types

```php
Http::mock('GET')
    ->url('...')
    ->sseMultipleTypes([
        'price_update' => ['{"symbol":"BTC","price":60000}', '{"symbol":"ETH","price":3000}'],
        'alert'        => [['level' => 'info', 'msg' => 'Market open']],
    ])
    ->register();
```

### SSE with Comment Lines

```php
Http::mock('GET')
    ->url('...')
    ->sseWithComments(
        events:   [['data' => '{"payload":1}'], ['data' => '{"payload":2}']],
        comments: ['keep-alive', 'heartbeat']
    )
    ->register();
```

### SSE — Expect Last-Event-ID (Resumption)

Matches only a reconnection request that carries the specified `Last-Event-ID` header:

```php
Http::mock('GET')
    ->url('...')
    ->sseExpectLastEventId('42', eventsAfterResume: [
        ['id' => '43', 'data' => '{"msg":"resumed"}'],
        ['id' => '44', 'data' => '{"msg":"continued"}'],
    ])
    ->register();
```

### SSE Disconnection

```php
Http::mock('GET')
    ->url('...')
    ->sseDisconnectAfter(eventsBeforeDisconnect: 3, disconnectError: 'Connection reset')
    ->register();
```

---

## Streaming SSE (Periodic Emitter)

For realistic time-based streams the periodic emitter sends events one by one on a timer. Control the interval between events using `->dataStreamTransferLatency()`.

### Finite Event List

```php
Http::mock('GET')
    ->url('...')
    ->sseWithPeriodicEvents([
        ['event' => 'tick', 'data' => '{"n":1}', 'id' => '1'],
        ['event' => 'tick', 'data' => '{"n":2}', 'id' => '2'],
        ['event' => 'tick', 'data' => '{"n":3}', 'id' => '3'],
    ])
    ->dataStreamTransferLatency(0.1) // 100ms between events
    ->register();
```

### Limited Events with Generator

```php
Http::mock('GET')
    ->url('...')
    ->sseWithLimitedEvents(
        eventCount:     10,
        eventGenerator: fn (int $i) => [
            'data'  => json_encode(['index' => $i, 'ts' => time()]),
            'id'    => (string) $i,
            'event' => 'message',
        ]
    )
    ->dataStreamTransferLatency(0.05)
    ->register();
```

### Infinite Stream

Runs until the client cancels. Optionally capped with `maxEvents`:

```php
Http::mock('GET')
    ->url('...')
    ->sseInfiniteStream(
        eventGenerator: fn (int $i) => [
            'event' => 'price',
            'data'  => json_encode(['value' => rand(100, 999)]),
            'id'    => (string) $i,
        ],
        maxEvents: 100
    )
    ->dataStreamTransferLatency(0.2)
    ->register();
```

### Periodic Events then Network Drop

```php
Http::mock('GET')
    ->url('...')
    ->ssePeriodicThenDisconnect(
        eventCount:      5,
        disconnectError: 'Connection lost',
        eventGenerator:  fn (int $i) => [
            'data' => json_encode(['index' => $i]),
            'id'   => (string) $i,
        ]
    )
    ->dataStreamTransferLatency(0.1)
    ->register();
```

---

## SSE Retry Sequences

These helpers simulate connection instability for testing SSE reconnection logic. All of them register the mock as an SSE response internally, so you do not need to call `respondWithSSE()` additionally.

### Fail Until Attempt N

```php
Http::mock('GET')
    ->url('...')
    ->sseFailUntilAttempt(
        successAttempt: 3,
        successEvents:  [['event' => 'connected', 'data' => '{}']],
        failureError:   'Connection refused'
    )
    ->register();
```

### Timeout Until Attempt N

```php
Http::mock('GET')
    ->url('...')
    ->sseTimeoutUntilAttempt(successAttempt: 3, timeoutAfter: 5.0)
    ->register();
```

### Custom Failure Sequence

```php
Http::mock('GET')
    ->url('...')
    ->sseFailWithSequence(
        failures: [
            'Connection refused',
            ['error' => 'SSL handshake failed', 'retryable' => true, 'delay' => 0.2],
        ],
        successEvents: [['data' => '{"ready":true}']]
    )
    ->register();
```

### Drop After Receiving Events

```php
Http::mock('GET')
    ->url('...')
    ->sseDropAfterEvents(
        eventsBeforeDrop: [
            ['id' => '1', 'data' => '{"msg":"first"}'],
            ['id' => '2', 'data' => '{"msg":"second"}'],
        ],
        dropError: 'Connection lost'
    )
    ->register();
```

### Reconnect from a Specific Event ID

```php
Http::mock('GET')
    ->url('...')
    ->sseReconnectFromEventId(
        lastEventId:      '2',
        eventsAfterResume: [
            ['id' => '3', 'data' => '{"msg":"resumed"}'],
            ['id' => '4', 'data' => '{"msg":"continued"}'],
        ]
    )
    ->register();
```

### Rate Limited Until Success (429)

```php
Http::mock('GET')
    ->url('...')
    ->sseRateLimitedUntilAttempt(successAttempt: 4)
    ->register();
```

### Network Errors Until Success

```php
// Cycles: Connection refused → Connection reset → Timed out → success
Http::mock('GET')
    ->url('...')
    ->sseNetworkErrorsUntilAttempt(successAttempt: 4)
    ->register();
```

### Gradually Improving Connection

```php
Http::mock('GET')
    ->url('...')
    ->sseSlowlyImproveUntilAttempt(successAttempt: 5, maxDelay: 10.0)
    ->register();
```

### Mixed Failure Types

```php
// Cycles: timeout → connection error → DNS → SSL → success
Http::mock('GET')
    ->url('...')
    ->sseMixedFailuresUntilAttempt(successAttempt: 5)
    ->register();
```

### Intermittent Failures

```php
// true = fail, false = succeed
Http::mock('GET')
    ->url('...')
    ->sseIntermittentFailures([true, false, true, true, false])
    ->register();
```

---

## Network Simulation

The testing handler can inject realistic network conditions globally across all requests, layered on top of per-mock delays.

```php
// Presets
Http::startTesting()->withFastNetwork();      // sub-100ms, ~0% failure
Http::startTesting()->withMobileNetwork();    // 0.5–3s latency, 8% failure rate
Http::startTesting()->withPoorNetwork();      // 1–5s latency, 15% failure rate
Http::startTesting()->withUnstableNetwork();  // high variability, 20% failure rate

// Custom configuration
Http::startTesting()->enableNetworkSimulation([
    'random_delay'            => [0.2, 1.5],  // seconds
    'failure_rate'            => 0.05,         // 5% of requests fail outright
    'timeout_rate'            => 0.03,         // 3% time out
    'connection_failure_rate' => 0.02,         // 2% connection refused
    'retryable_failure_rate'  => 0.08,         // 8% retryable errors
]);

// Global random latency applied on top of per-mock delays
Http::startTesting()->withGlobalRandomLatencey(minSeconds: 0.05, maxSeconds: 0.3);

// Remove global random latency
Http::getTestingHandler()->withoutGlobalrandomLatency();

// Disable all simulation
Http::getTestingHandler()->disableNetworkSimulation();
```

---

## Passthrough Mode

By default the testing handler throws an `UnexpectedRequestException` whenever a request is made that does not match any registered mock. This prevents tests from silently hitting a real network.

In some scenarios you may want to mock only specific endpoints while letting everything else go through to the real network. `enablePassthrough()` enables this:

```php
Http::startTesting()->enablePassthrough();
```

Or in DI mode:

```php
$handler = new TestingHttpHandler();
$handler->enablePassthrough();

$client = (new HttpClient())->withHandler($handler);
```

### How It Works

When passthrough is enabled the handler resolves requests in this order:

1. Check registered mocks, if one matches, return the mock response as normal.
2. If no mock matches, forward the request to the real network using the underlying cURL handler.

The mock queue is consumed exactly as it would be without passthrough. A non-persistent mock is removed after being matched, and a persistent mock stays. Only genuinely unmatched requests reach the network.

### Recorded Requests and Assertions on Real Requests

Real passthrough requests are recorded to history just like mocked ones. Every assertion and inspection method works on them without any special handling:

```php
Http::startTesting()->enablePassthrough();

// No mock registered — goes to the real network
$response = await Http::get('https://api.example.com/users');

// Still recorded and assertable
Http::assertRequestMade('GET', 'https://api.example.com/users');
Http::assertHeaderSent('Accept', 'application/json');
Http::assertRequestCount(1);

$last = Http::getLastRequest();
echo $last->getUrl();    // https://api.example.com/users
echo $last->getMethod(); // GET
```

### Mixing Mocks and Real Requests

```php
Http::startTesting()->enablePassthrough();

// Mocked — no real request made
Http::mock('POST')
    ->url('https://mailer.example.com/send')
    ->respondWithStatus(200)
    ->register();

// Real network request
$users = await Http::get('https://api.staging.example.com/users');

// Mocked response
$mail = await Http::post('https://mailer.example.com/send', ['to' => 'alice@example.com']);

// Both are in history
Http::assertRequestCount(2);
Http::assertRequestMade('GET',  'https://api.staging.example.com/users');
Http::assertRequestMade('POST', 'https://mailer.example.com/send');
```

### Disabling Passthrough

```php
Http::getTestingHandler()->disablePassthrough();
```

### Things to Be Aware Of

**Passthrough makes tests non-deterministic.** Real network calls depend on external services being available, which can cause intermittent failures in CI. Use passthrough deliberately — for integration or contract tests — rather than as a default.

**Real requests are not retried by the mock handler.** Retry logic is handled by the real `HttpHandler` pipeline, the same as in production.

**Passthrough is disabled by default.** You must explicitly call `enablePassthrough()`. This ensures accidentally unmatched mocks always fail loudly in unit tests.

**`throwOnUnexpected` is automatically disabled** when you call `enablePassthrough()`, since throwing on an unmatched request would contradict letting it through. Calling `disablePassthrough()` re-enables it.

---

## Assertions

All assertion methods are available as static calls on `Http::` or directly on the `TestingHttpHandler` instance when using DI.

### Request Assertions

```php
Http::assertRequestMade('POST', 'https://api.example.com/users');
Http::assertNoRequestsMade();
Http::assertRequestCount(3);
Http::assertRequestNotMade('DELETE', 'https://api.example.com/users/1');
Http::assertSingleRequestTo('https://api.example.com/login');
Http::assertRequestCountTo('https://api.example.com/search', maxCount: 5);

// URL pattern (fnmatch)
Http::assertRequestMatchingUrl('GET', 'https://api.example.com/users/*');

// Ordered sequence
Http::assertRequestSequence([
    ['method' => 'POST', 'url' => 'https://auth.example.com/login'],
    ['method' => 'GET',  'url' => 'https://api.example.com/users'],
]);

// By position in history
Http::assertRequestAtIndex('GET', 'https://api.example.com/users', index: 0);
```

### Header Assertions

All header assertions accept an optional `$requestIndex` parameter to target a specific request in history.

```php
Http::assertHeaderSent('Authorization');
Http::assertHeaderSent('Authorization', 'Bearer my-token');
Http::assertHeaderSent('X-Tenant-Id', 'acme', requestIndex: 1); // second request
Http::assertHeaderNotSent('X-Debug');
Http::assertHeadersSent(['Content-Type' => 'application/json', 'Accept' => 'application/json']);
Http::assertHeaderMatches('Authorization', '/^Bearer [a-z0-9\-]+$/i');
Http::assertBearerTokenSent('my-token');
Http::assertBearerTokenSent('my-token', requestIndex: 0);
Http::assertContentType('application/json');
Http::assertAcceptHeader('application/json');
Http::assertUserAgent('MyApp/1.0');
```

### Request Body Assertions

```php
Http::assertRequestWithBody('POST', 'https://api.example.com/submit', '{"action":"go"}');
Http::assertRequestBodyContains('POST', 'https://api.example.com/submit', '"action"');
Http::assertRequestBodyMatches('POST', '...', '/^\{"action":\s*"[a-z]+"\}$/');
Http::assertRequestWithEmptyBody('GET', 'https://api.example.com/ping');
Http::assertRequestHasBody('POST', 'https://api.example.com/data');
Http::assertRequestIsJson('POST', 'https://api.example.com/data');

Http::assertRequestWithJson('POST', 'https://api.example.com/users', [
    'name' => 'Alice',
    'role' => 'admin',
]);

Http::assertRequestJsonContains('POST', 'https://api.example.com/users', [
    'role' => 'admin',
]);

// Dot-notation path
Http::assertRequestJsonPath('POST', 'https://api.example.com/orders', 'items.0.sku', 'ABC-001');
```

### Cookie Assertions

```php
// What was sent in requests
Http::assertCookieSent('session');
Http::assertCookieNotSent('tracking');
Http::assertCookieSentToUrl('session', 'https://api.example.com/*');
Http::assertCookieNotSentToUrl('admin_token', 'https://public.example.com/*');

// What is stored in the cookie jar
Http::assertCookieExists('session');
Http::assertCookieValue('theme', 'dark');
Http::assertCookieExpired('old_token');
Http::assertCookieNotExpired('session');
Http::assertCookieIsSecure('session');
Http::assertCookieIsHttpOnly('session');
Http::assertCookieIsHostOnly('tracking');
Http::assertCookieHasAttributes('session', [
    'secure'   => true,
    'httpOnly' => true,
    'path'     => '/',
    'sameSite' => 'Strict',
]);
```

### Download Assertions

```php
Http::assertDownloadMade('https://files.example.com/report.pdf', '/tmp/report.pdf');
Http::assertDownloadMadeToUrl('https://files.example.com/report.pdf');
Http::assertFileDownloaded('/tmp/report.pdf');
Http::assertNoDownloadsMade();
Http::assertDownloadCount(2);
Http::assertDownloadWithMethod('https://files.example.com/report.pdf', 'GET');
Http::assertDownloadWithHeaders('https://files.example.com/report.pdf', [
    'Authorization' => 'Bearer my-token',
]);

Http::assertDownloadedFileExists('/tmp/report.pdf');
Http::assertDownloadedFileContains('/tmp/report.pdf', '%PDF-1.4');
Http::assertDownloadedFileContainsString('/tmp/report.pdf', 'Summary');
Http::assertDownloadedFileSize('/tmp/report.pdf', 204800);
Http::assertDownloadedFileSizeBetween('/tmp/report.pdf', minSize: 100_000, maxSize: 500_000);
```

### Upload Assertions

```php
Http::assertUploadMade('https://storage.example.com/files', '/local/path/file.csv');
Http::assertUploadMadeToUrl('https://storage.example.com/files');
Http::assertNoUploadsMade();
Http::assertUploadCount(1);
```

### Stream Assertions

```php
Http::assertStreamMade('https://api.example.com/stream');
Http::assertStreamWithCallback('https://api.example.com/stream');
Http::assertStreamWithMethod('https://api.example.com/stream', 'POST');
Http::assertStreamWithHeaders('https://api.example.com/stream', ['Accept' => 'text/plain']);
Http::assertNoStreamsMade();
Http::assertStreamCount(1);
```

### SSE Assertions

```php
// Connection presence
Http::assertSSEConnectionMade('https://api.example.com/events');
Http::assertNoSSEConnections();
Http::assertSSEConnectionCount('https://api.example.com/events', expectedCount: 3);
Http::assertSSEConnectionAttempts('https://api.example.com/events', expectedAttempts: 3);
Http::assertSSEConnectionAttemptsAtLeast('https://api.example.com/events', minAttempts: 2);
Http::assertSSEConnectionAttemptsAtMost('https://api.example.com/events', maxAttempts: 5);

// Reconnection behaviour
Http::assertSSEReconnectionOccurred('https://api.example.com/events');
Http::assertFirstSSEConnectionHasNoLastEventId('https://api.example.com/events');
Http::assertSSELastEventId('42');                   // last request
Http::assertSSELastEventId('42', requestIndex: 2);  // specific attempt
Http::assertSSEReconnectionProgression('https://api.example.com/events'); // IDs monotonically increasing

// Headers on SSE connections
Http::assertSSEConnectionAuthenticated('https://api.example.com/events', expectedToken: 'my-token');
Http::assertSSEConnectionHasHeader('https://api.example.com/events', 'X-Tenant-Id', 'acme');
Http::assertSSEConnectionMissingHeader('https://api.example.com/events', 'X-Debug');
Http::assertSSEConnectionRequestedWithProperHeaders('https://api.example.com/events');

// Multiple and ordered connections
Http::assertSSEConnectionsMadeToMultipleUrls([
    'https://api.example.com/events/prices',
    'https://api.example.com/events/alerts',
]);
Http::assertSSEConnectionsInOrder([
    'https://api.example.com/events/auth',
    'https://api.example.com/events/stream',
]);
```

---

## Inspecting Recorded Requests

```php
$last    = Http::getLastRequest();
$first   = Http::getRequest(0);
$history = Http::getRequestHistory();

// RecordedRequest API
$last->getMethod();                // 'POST'
$last->getUrl();                   // 'https://api.example.com/users'
$last->getHeaders();               // ['content-type' => 'application/json', ...]
$last->getHeader('authorization'); // 'Bearer token' or array for multi-value headers
$last->getHeaderLine('accept');    // 'application/json'
$last->hasHeader('x-debug');       // false
$last->getBody();                  // raw body string
$last->getJson();                  // parsed array or null
$last->isJson();                   // bool
$last->getOptions();               // raw cURL options array

// Filtered views
Http::getRequestsByMethod('POST');
Http::getRequestsTo('https://api.example.com/users');
Http::getDownloadRequests();
Http::getLastDownload();
Http::getFirstDownload();
Http::getDownloadDestination('https://files.example.com/report.pdf');
Http::getUploadRequests();
Http::getLastUpload();
Http::getStreamRequests();
Http::getLastStream();
Http::getFirstStream();
Http::streamHasCallback($request); // bool
Http::getSSEConnectionAttempts('https://api.example.com/events');
```

---

## Debugging

```php
Http::dumpLastRequest();            // prints method, URL, headers, body
Http::dumpRequestsByMethod('POST');
Http::dumpDownloads();
Http::dumpLastDownload();
Http::dumpStreams();
Http::dumpLastStream();
```

---

## API Reference

### `TestingHttpHandler` / `Http::` — Testing Lifecycle

| Method | Description |
|---|---|
| `Http::startTesting()` | Enable testing mode. Returns the `TestingHttpHandler` instance. |
| `Http::stopTesting()` | Disable testing mode and clear all state. |
| `Http::resetTesting()` | Clear mocks and history without disabling testing mode. |
| `Http::getTestingHandler()` | Return the active testing handler. Throws if not in testing mode. |
| `Http::mock(string $method)` | Create a new `MockRequestBuilder`. |
| `$handler->reset()` | Clear all mocks, history, cookies, and temp files (DI mode). |
| `$handler->enablePassthrough()` | Allow unmatched requests to reach the real network. |
| `$handler->disablePassthrough()` | Restore strict matching — unmatched requests throw. |
| `$handler->enableNetworkSimulation(array $settings)` | Enable global network simulation. |
| `$handler->disableNetworkSimulation()` | Disable network simulation. |
| `$handler->withFastNetwork()` | Preset: sub-100ms, near-zero failure rate. |
| `$handler->withMobileNetwork()` | Preset: 0.5–3s latency, 8% failure rate. |
| `$handler->withPoorNetwork()` | Preset: 1–5s latency, 15% failure rate. |
| `$handler->withUnstableNetwork()` | Preset: high variability, 20% failure rate. |
| `$handler->withGlobalRandomLatencey(float $min, float $max)` | Add a global random latency range to all requests. |
| `$handler->withoutGlobalrandomLatency()` | Remove global random latency. |

### `MockRequestBuilder` — Request Matching

| Method | Description |
|---|---|
| `->url(string $pattern)` | URL pattern to match using `fnmatch` wildcards. |
| `->expect(callable $callback)` | Custom closure matcher receiving a `RecordedRequest`. |
| `->expectHeader(string $name, string $value)` | Require a specific header in the request. |
| `->expectHeaders(array $headers)` | Require multiple headers. |
| `->expectBody(string $pattern)` | Require the request body to match a pattern. |
| `->expectJson(array $data)` | Require the request body to match exact JSON. |
| `->expectCookies(array $cookies)` | Require specific cookies to be present. |

### `MockRequestBuilder` — Response Configuration

| Method | Description |
|---|---|
| `->respondWithStatus(int $status)` | Set the HTTP response status code. |
| `->status(int $status)` | Alias for `respondWithStatus()`. |
| `->respondWith(string $body)` | Set the response body as a plain string. |
| `->respondJson(array $data)` | Set the response body as JSON. Sets `Content-Type: application/json`. |
| `->respondXml(string\|\SimpleXMLElement $xml)` | Set the response body as XML. Sets `Content-Type: application/xml`. |
| `->respondWithHeader(string $name, string\|array $value)` | Add a response header. |
| `->respondWithHeaders(array $headers)` | Add multiple response headers. |
| `->respondWithChunks(array $chunks)` | Set a sequence of body chunks to simulate streaming. |
| `->persistent()` | Make this mock reusable for multiple requests. |
| `->register()` | Activate the mock. Must be called at the end of every chain. |

### `MockRequestBuilder` — Latency and Failures

| Method | Description |
|---|---|
| `->latency(float $seconds)` | Fixed delay before responding. |
| `->slowResponse(float $seconds)` | Alias for `latency()`. |
| `->randomLatency(float $min, float $max)` | Random delay chosen once at registration. |
| `->randomPersistentLatency(float $min, float $max)` | Fresh random delay on every match (implies persistent). |
| `->dataStreamTransferLatency(float $seconds, float $jitter)` | Per-chunk delay for downloads, streams, and SSE. |
| `->fail(string $error)` | Make the mock fail with a hard error. |
| `->timeout(float $seconds)` | Simulate a timeout. |
| `->timeoutFailure(float $timeoutAfter, ?string $message)` | Retryable timeout failure. |
| `->retryableFailure(string $error)` | Fail with a retryable error. |
| `->networkError(string $type)` | Simulate a specific network error type (`connection`, `timeout`, `resolve`, `ssl`). |

### `MockRequestBuilder` — Retry Sequences

| Method | Description |
|---|---|
| `->failUntilAttempt(int $successAttempt, string $error)` | Fail N−1 times, succeed on attempt N. |
| `->timeoutUntilAttempt(int $successAttempt, float $timeoutAfter)` | Timeout N−1 times, succeed on attempt N. |
| `->statusFailuresUntilAttempt(int $successAttempt, int $failureStatus)` | Return error status N−1 times, succeed on attempt N. |
| `->failWithSequence(array $failures, mixed $successResponse)` | Custom sequence of failure types then success. |
| `->mixedFailuresUntilAttempt(int $successAttempt)` | Cycle through timeout, connection, DNS, SSL failures until success. |
| `->intermittentFailures(array $pattern)` | Explicit boolean pattern of fails and successes. |
| `->rateLimitedUntilAttempt(int $successAttempt)` | Return 429 with `Retry-After` until success. |
| `->slowlyImproveUntilAttempt(int $successAttempt, float $maxDelay)` | Simulate network recovery with decreasing delays. |

### `MockRequestBuilder` — File Operations

| Method | Description |
|---|---|
| `->downloadFile(string $content, ?string $filename, string $contentType)` | Mock a file download response. |
| `->downloadLargeFile(int $sizeInKB, ?string $filename)` | Mock a large file download with generated content. |

### `MockRequestBuilder` — Cookie Mocking

| Method | Description |
|---|---|
| `->setCookie(string $name, string $value, ...)` | Add a `Set-Cookie` header to the response. |
| `->setCookies(array $cookies)` | Add multiple `Set-Cookie` headers. |

### `MockRequestBuilder` — SSE

| Method | Description |
|---|---|
| `->respondWithSSE(array $events)` | Configure as an SSE response with a list of events. |
| `->addSSEEvent(?string $data, ?string $event, ?string $id, ?int $retry)` | Add a single SSE event. |
| `->sseWithKeepalive(array $dataEvents, int $keepaliveCount)` | SSE with keepalive events between data events. |
| `->sseHeartbeatOnly(int $heartbeatCount)` | SSE that sends only heartbeat (empty data) events. |
| `->sseWithRetry(array $events, int $retryMs)` | SSE with a custom `retry` interval field. |
| `->sseWithRetryDirective(int $retryMs, array $events)` | SSE that sends only a retry directive. |
| `->sseWithEventIds(array $events)` | SSE with event IDs for reconnection scenarios. |
| `->sseMultipleTypes(array $eventsByType)` | SSE with multiple named event types. |
| `->sseWithComments(array $events, array $comments)` | SSE with comment lines interspersed. |
| `->sseExpectLastEventId(string $id, array $eventsAfterResume)` | Match only requests that carry a specific `Last-Event-ID`. |
| `->sseDisconnectAfter(int $count, string $error)` | Send N events then simulate a network drop. |
| `->sseWithPeriodicEvents(array $events)` | Emit events one by one via a timer (use with `dataStreamTransferLatency`). |
| `->sseWithLimitedEvents(int $count, ?callable $generator)` | Emit N generated events then close. |
| `->sseInfiniteStream(callable $generator, ?int $maxEvents)` | Emit events indefinitely until the client cancels. |
| `->ssePeriodicThenDisconnect(int $count, string $error, ?callable $generator)` | Emit N events then simulate a network drop. |

### `MockRequestBuilder` — SSE Retry Sequences

| Method | Description |
|---|---|
| `->sseFailUntilAttempt(int $successAttempt, array $successEvents, string $error)` | Fail N−1 SSE connections, succeed on attempt N. |
| `->sseTimeoutUntilAttempt(int $successAttempt, array $successEvents, float $timeout)` | Timeout N−1 SSE connections, succeed on attempt N. |
| `->sseFailWithSequence(array $failures, array $successEvents)` | Custom sequence of SSE failures then success. |
| `->sseDropAfterEvents(array $events, string $error, bool $retryable)` | Send events then drop the connection. |
| `->sseReconnectFromEventId(string $id, array $eventsAfterResume)` | Match reconnection request with a specific `Last-Event-ID`. |
| `->sseRateLimitedUntilAttempt(int $successAttempt, array $successEvents)` | Return 429 until success. |
| `->sseNetworkErrorsUntilAttempt(int $successAttempt, array $successEvents)` | Cycle through network errors until success. |
| `->sseSlowlyImproveUntilAttempt(int $successAttempt, array $successEvents, float $maxDelay)` | Simulate network recovery for SSE connections. |
| `->sseMixedFailuresUntilAttempt(int $successAttempt)` | Cycle through mixed failure types until success. |
| `->sseIntermittentFailures(array $pattern)` | Explicit boolean pattern of SSE fails and successes. |

---

Here's the updated section with the name swapped throughout:

---

## Full Test Examples

### Pest — Standard HTTP

```php
use Hibla\HttpClient\Http;

beforeEach(fn () => Http::startTesting());
afterEach(fn () => Http::stopTesting());

it('creates a user and returns the new resource', function () {
    Http::mock('POST')
        ->url('https://api.example.com/users')
        ->expectHeader('Authorization', 'Bearer secret')
        ->expectJson(['name' => 'Reymart Calicdan', 'role' => 'admin'])
        ->respondWithStatus(201)
        ->respondJson(['id' => 1, 'name' => 'Reymart Calicdan'])
        ->register();

    $service = new UserService(token: 'secret');
    $user    = $service->create(name: 'Reymart Calicdan', role: 'admin');

    expect($user->id)->toBe(1)
        ->and($user->name)->toBe('Reymart Calicdan');

    Http::assertRequestCount(1);
    Http::assertRequestMade('POST', 'https://api.example.com/users');
    Http::assertBearerTokenSent('secret');
    Http::assertContentType('application/json');
    Http::assertRequestJsonContains('POST', 'https://api.example.com/users', ['role' => 'admin']);
});

it('retries on 503 and eventually succeeds', function () {
    Http::mock('POST')
        ->url('https://api.example.com/orders')
        ->statusFailuresUntilAttempt(successAttempt: 3, failureStatus: 503)
        ->register();

    $result = (new OrderService())->placeOrder(['item' => 'book']);

    expect($result)->toBeTrue();
    Http::assertRequestCount(3);
});

it('downloads a report and writes it to disk', function () {
    Http::mock('GET')
        ->url('https://files.example.com/report.pdf')
        ->downloadFile(content: '%PDF-1.4 fake content', filename: 'report.pdf', contentType: 'application/pdf')
        ->register();

    $destination = Http::getTempPath('report.pdf');
    (new ReportService())->download(destination: $destination);

    Http::assertDownloadMade('https://files.example.com/report.pdf', $destination);
    Http::assertDownloadedFileContainsString($destination, '%PDF-1.4');
});
```

### Pest — SSE

```php
use Hibla\HttpClient\Http;

beforeEach(fn () => Http::startTesting());
afterEach(fn () => Http::stopTesting());

it('receives SSE events and processes them', function () {
    Http::mock('GET')
        ->url('https://api.example.com/events')
        ->respondWithSSE([
            ['event' => 'connected', 'data' => '{"status":"ready"}', 'id' => '1'],
            ['event' => 'update',    'data' => '{"value":42}',        'id' => '2'],
        ])
        ->register();

    $received = [];
    (new EventService())->listen(
        url:     'https://api.example.com/events',
        onEvent: fn ($event) => $received[] = $event
    );

    expect($received)->toHaveCount(2)
        ->and($received[0]->event)->toBe('connected')
        ->and($received[1]->event)->toBe('update');

    Http::assertSSEConnectionMade('https://api.example.com/events');
    Http::assertSSEConnectionRequestedWithProperHeaders('https://api.example.com/events');
});

it('reconnects after a dropped SSE connection using Last-Event-ID', function () {
    Http::mock('GET')
        ->url('https://api.example.com/events')
        ->sseDropAfterEvents(
            eventsBeforeDrop: [
                ['id' => '1', 'data' => '{"msg":"first"}'],
                ['id' => '2', 'data' => '{"msg":"second"}'],
            ],
            dropError: 'Connection lost'
        )
        ->register();

    Http::mock('GET')
        ->url('https://api.example.com/events')
        ->sseExpectLastEventId('2', eventsAfterResume: [
            ['id' => '3', 'data' => '{"msg":"resumed"}'],
        ])
        ->register();

    (new EventService())->listenWithReconnect('https://api.example.com/events');

    Http::assertSSEConnectionAttempts('https://api.example.com/events', expectedAttempts: 2);
    Http::assertSSEReconnectionOccurred('https://api.example.com/events');
    Http::assertFirstSSEConnectionHasNoLastEventId('https://api.example.com/events');
    Http::assertSSELastEventId('2', requestIndex: 1);
    Http::assertSSEReconnectionProgression('https://api.example.com/events');
});

it('streams a periodic price feed', function () {
    Http::mock('GET')
        ->url('https://api.example.com/prices')
        ->sseInfiniteStream(
            eventGenerator: fn (int $i) => [
                'event' => 'price',
                'data'  => json_encode(['tick' => $i, 'value' => 100 + $i]),
                'id'    => (string) $i,
            ],
            maxEvents: 5
        )
        ->dataStreamTransferLatency(0.01)
        ->register();

    $ticks = [];
    (new PriceFeedService())->subscribe(
        url:      'https://api.example.com/prices',
        onPrice:  fn ($event) => $ticks[] = json_decode($event->data, true)
    );

    expect($ticks)->toHaveCount(5)
        ->and($ticks[0]['tick'])->toBe(0)
        ->and($ticks[4]['tick'])->toBe(4);

    Http::assertSSEConnectionMade('https://api.example.com/prices');
});
```

### Pest — DI Mode

```php
use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\Testing\TestingHttpHandler;

beforeEach(function () {
    $this->handler = new TestingHttpHandler();
    $this->client  = (new HttpClient())->withHandler($this->handler);
    $this->service = new UserService($this->client);
});

afterEach(fn () => $this->handler->reset());

it('fetches a list of users', function () {
    $this->handler->mock('GET')
        ->url('https://api.example.com/users')
        ->respondJson(['users' => [['id' => 1, 'name' => 'Reymart Calicdan']]])
        ->register();

    $users = $this->service->list();

    expect($users)->toHaveCount(1);
    $this->handler->assertRequestMade('GET', 'https://api.example.com/users');
    $this->handler->assertHeaderSent('Accept', 'application/json');
});

it('sends cookies after login', function () {
    $this->handler->mock('POST')
        ->url('https://api.example.com/login')
        ->respondWithStatus(200)
        ->setCookie(name: 'session', value: 'tok-abc', secure: true, httpOnly: true)
        ->register();

    $this->handler->mock('GET')
        ->url('https://api.example.com/profile')
        ->expectCookies(['session' => 'tok-abc'])
        ->respondJson(['name' => 'Reymart Calicdan'])
        ->register();

    $this->service->login('reymart@example.com', 'password');
    $this->service->profile();

    $this->handler->assertCookieIsSecure('session');
    $this->handler->assertCookieIsHttpOnly('session');
    $this->handler->assertCookieSentToUrl('session', 'https://api.example.com/profile');
});
```

### Pest — Passthrough Mode

```php
use Hibla\HttpClient\Http;

beforeEach(fn () => Http::startTesting()->enablePassthrough());
afterEach(fn () => Http::stopTesting());

it('mocks the payment gateway but hits staging for order data', function () {
    Http::mock('POST')
        ->url('https://payments.example.com/charge')
        ->respondWithStatus(200)
        ->respondJson(['charged' => true, 'id' => 'ch_001'])
        ->register();

    $result = (new CheckoutService())->checkout(orderId: 'ord-123');

    expect($result->charged)->toBeTrue();

    Http::assertRequestMade('POST', 'https://payments.example.com/charge');
    Http::assertRequestJsonContains('POST', 'https://payments.example.com/charge', ['order_id' => 'ord-123']);

    // Real request to staging — still recorded and assertable
    Http::assertRequestMade('GET', 'https://api.staging.example.com/orders/ord-123');
    Http::assertBearerTokenSent('staging-token');
});
```

### PHPUnit

```php
use Hibla\HttpClient\Http;
use PHPUnit\Framework\TestCase;

class OrderServiceTest extends TestCase
{
    protected function setUp(): void    { Http::startTesting(); }
    protected function tearDown(): void { Http::stopTesting(); }

    public function test_places_order_and_sends_confirmation(): void
    {
        Http::mock('POST')
            ->url('https://api.example.com/orders')
            ->expectHeader('Authorization', 'Bearer secret')
            ->expectJson(['item' => 'book', 'qty' => 2])
            ->respondWithStatus(201)
            ->respondJson(['id' => 'ord-001', 'status' => 'confirmed'])
            ->register();

        Http::mock('POST')
            ->url('https://mailer.example.com/send')
            ->respondWithStatus(200)
            ->register();

        $service = new OrderService(token: 'secret');
        $service->placeOrder(item: 'book', qty: 2, email: 'reymart@example.com');

        Http::assertRequestCount(2);
        Http::assertRequestMade('POST', 'https://api.example.com/orders');
        Http::assertRequestJsonContains('POST', 'https://api.example.com/orders', ['item' => 'book']);
        Http::assertBearerTokenSent('secret', requestIndex: 0);
        Http::assertRequestMade('POST', 'https://mailer.example.com/send');
    }
}
```

---

## PHPUnit and Pest Integration

When PHPUnit is available, all `assert*` methods register themselves with PHPUnit's assertion counter automatically, so they appear in test output and contribute to the assertion count. No extra configuration is required.

When PHPUnit is not present (for example, in standalone PHP projects without PHPUnit), failed assertions throw `MockAssertionError` instead.

---

## Development
```bash
git clone https://github.com/hiblaphp/http-client-testing.git
cd http-client-testing
composer install
```
```bash
./vendor/bin/pest
```
```bash
./vendor/bin/phpstan analyse
```

---

## Credits

- **API Design:** Inspired by Laravel Http::fake() api.

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.