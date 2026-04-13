<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Factories\SSE;

use Exception;
use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\ClientException;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Exceptions\ServerException;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Testing\Exceptions\MockException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\HttpClient\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Throwable;

use function Hibla\delay;

class RetryableSSEResponseFactory
{
    private NetworkSimulationHandler $networkHandler;

    private DelayCalculator $delayCalculator;

    private PeriodicSSEEmitter $periodicEmitter;

    public function __construct(NetworkSimulationHandler $networkHandler)
    {
        $this->networkHandler = $networkHandler;
        $this->delayCalculator = new DelayCalculator();
        $this->periodicEmitter = new PeriodicSSEEmitter();
    }

    /**
     * Creates a retryable SSE response with the given configuration.
     *
     * @return PromiseInterface<SSEResponse>
     */
    public function create(
        SSEReconnectConfig $reconnectConfig,
        callable $mockProvider,
        ?callable $onEvent,
        ?callable $onError,
        ?callable $onReconnect = null
    ): PromiseInterface {
        /** @var Promise<SSEResponse> $promise */
        $promise = new Promise();
        $attempt = 0;

        /** @var PromiseInterface<mixed>|null $activeDelayPromise */
        $activeDelayPromise = null;
        /** @var string|null $periodicTimerId */
        $periodicTimerId = null;
        $lastEventId = null;
        $retryInterval = null;

        $promise->onCancel(function () use (&$activeDelayPromise, &$periodicTimerId) {
            if ($activeDelayPromise !== null) {
                $activeDelayPromise->cancel();
            }
            if ($periodicTimerId !== null) {
                Loop::cancelTimer($periodicTimerId);
                $periodicTimerId = null;
            }
        });

        $executeAttempt = null;
        $executeAttempt = function () use (
            $reconnectConfig,
            $promise,
            $mockProvider,
            $onEvent,
            $onError,
            $onReconnect,
            &$attempt,
            &$activeDelayPromise,
            &$executeAttempt,
            &$lastEventId,
            &$retryInterval,
            &$periodicTimerId
        ) {
            if ($promise->isCancelled()) {
                return;
            }

            $currentAttempt = $attempt + 1;

            try {
                $mock = $mockProvider($currentAttempt, $lastEventId);
                if (! $mock instanceof MockedRequest) {
                    throw new MockException('Mock provider must return a MockedRequest instance');
                }
            } catch (Exception $e) {
                if ($promise->isPending()) {
                    $promise->reject(new MockException('Mock provider error: ' . $e->getMessage()));
                } elseif ($onError !== null) {
                    $onError('Mock provider error: ' . $e->getMessage());
                }

                return;
            }

            $networkConditions = $this->networkHandler->simulate();
            $globalDelay = $this->networkHandler->generateGlobalrandomLatency();
            $delay = $this->delayCalculator->calculateTotalDelay(
                $mock,
                $networkConditions,
                $globalDelay
            );

            $activeDelayPromise = delay($delay);

            $activeDelayPromise->then(function () use (
                $reconnectConfig,
                $promise,
                $mock,
                $networkConditions,
                $onEvent,
                $onError,
                $onReconnect,
                &$attempt,
                $currentAttempt,
                &$activeDelayPromise,
                &$executeAttempt,
                &$lastEventId,
                &$retryInterval,
                &$periodicTimerId
            ) {
                if ($promise->isCancelled()) {
                    return;
                }

                $result = $this->evaluateAttempt($mock, $networkConditions, $reconnectConfig);

                // Handle Handshake Failure (connection never established)
                if ($result['is_handshake_failure']) {
                    if ($result['is_retryable'] && $attempt < $reconnectConfig->maxAttempts) {
                        $attempt++;

                        $retryDelay = $retryInterval !== null
                            ? ($retryInterval / 1000.0)
                            : $reconnectConfig->calculateDelay($attempt);

                        if ($onReconnect !== null) {
                            $onReconnect($attempt, $retryDelay, $result['error_message']);
                        }

                        if ($onError !== null) {
                            $onError($result['error_message']);
                        }

                        $activeDelayPromise = delay($retryDelay);
                        $activeDelayPromise->then($executeAttempt);
                    } else {
                        if ($onError !== null) {
                            $onError($result['error_message']);
                        }
                        if ($promise->isPending()) {
                            $promise->reject(new NetworkException(
                                "SSE connection failed after {$currentAttempt} attempt(s): {$result['error_message']}"
                            ));
                        }
                    }
                } else {
                    // Handle Mid-Stream Drop Logic
                    $onMidStreamError = function (string $errorMsg) use ($reconnectConfig, $onError, $onReconnect, &$attempt, &$activeDelayPromise, &$executeAttempt, &$retryInterval, $mock) {
                        $exception = new Exception($errorMsg);
                        $isRetryable = $reconnectConfig->isRetryableError($exception) || $mock->isRetryableFailure();

                        if ($isRetryable && $attempt < $reconnectConfig->maxAttempts) {
                            $attempt++;

                            $retryDelay = $retryInterval !== null
                                ? ($retryInterval / 1000.0)
                                : $reconnectConfig->calculateDelay($attempt);

                            if ($onReconnect !== null) {
                                $onReconnect($attempt, $retryDelay, $errorMsg);
                            }

                            if ($onError !== null) {
                                $onError($errorMsg);
                            }

                            $activeDelayPromise = delay($retryDelay);
                            $activeDelayPromise->then($executeAttempt);
                        } else {
                            if ($onError !== null) {
                                $onError($errorMsg);
                            }
                        }
                    };

                    try {
                        if ($mock->hasStreamConfig()) {
                            $this->periodicEmitter->emit($promise, $mock, $onEvent, $onError, $periodicTimerId, $onMidStreamError);
                        } else {
                            $immediateEmitter = new ImmediateSSEEmitter();
                            $immediateEmitter->emit($promise, $mock, $onEvent, $lastEventId, $retryInterval, $onMidStreamError);
                        }

                        $attempt = 0;
                    } catch (Throwable $e) {
                        if ($onError !== null) {
                            $onError($e->getMessage());
                        }
                        if ($promise->isPending()) {
                            $promise->reject($e);
                        }
                    }
                }
            });
        };

        $activeDelayPromise = delay(0);
        $activeDelayPromise->then($executeAttempt);

        return $promise;
    }

    /**
     * @param array{should_fail: bool, error_message?: string} $networkConditions
     *
     * @return array{is_handshake_failure: bool, is_retryable: bool, error_message: string, exception: Exception|null}
     */
    private function evaluateAttempt(
        MockedRequest $mock,
        array $networkConditions,
        SSEReconnectConfig $reconnectConfig
    ): array {
        $isHandshakeFailure = false;
        $isRetryable = false;
        $errorMessage = '';
        /** @var Exception|null $exception */
        $exception = null;

        if ($networkConditions['should_fail']) {
            $isHandshakeFailure = true;
            $errorMessage = $networkConditions['error_message'] ?? 'Network failure';
            $exception = new Exception($errorMessage);
            $isRetryable = $reconnectConfig->isRetryableError($exception);
        } elseif ($mock->shouldFail() && \count($mock->getSSEEvents()) === 0 && ! $mock->hasStreamConfig()) {
            $isHandshakeFailure = true;
            $errorMessage = $mock->getError() ?? 'SSE connection failed';
            $exception = new Exception($errorMessage);
            $isRetryable = $reconnectConfig->isRetryableError($exception) || $mock->isRetryableFailure();
        } elseif ($mock->getStatusCode() >= 400) {
            $isHandshakeFailure = true;
            $errorMessage = "HTTP {$mock->getStatusCode()} Failure";

            if ($mock->getStatusCode() >= 500) {
                $exception = new ServerException($errorMessage, 0, null, null, $mock->getStatusCode());
            } else {
                $exception = new ClientException($errorMessage, 0, null, null, $mock->getStatusCode());
            }

            $isRetryable = $reconnectConfig->isRetryableError($exception);
        }

        return [
            'is_handshake_failure' => $isHandshakeFailure,
            'is_retryable' => $isRetryable,
            'error_message' => $errorMessage,
            'exception' => $exception,
        ];
    }
}
