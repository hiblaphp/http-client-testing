<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Factories\SSE;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\HttpClient\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Throwable;

class SSEResponseFactory
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
     * Creates an SSE response with the given configuration.
     *
     * @return PromiseInterface<SSEResponse>
     */
    public function create(
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError
    ): PromiseInterface {
        /** @var Promise<SSEResponse> $promise */
        $promise = new Promise();

        $networkConditions = $this->networkHandler->simulate();
        $globalDelay = $this->networkHandler->generateGlobalrandomLatency();
        $totalDelay = $this->delayCalculator->calculateTotalDelay(
            $mock,
            $networkConditions,
            $globalDelay
        );

        /** @var string|null $timerId */
        $timerId = null;
        /** @var string|null $periodicTimerId */
        $periodicTimerId = null;

        $promise->onCancel(function () use (&$timerId, &$periodicTimerId) {
            if ($timerId !== null) {
                Loop::cancelTimer($timerId);
                $timerId = null;
            }
            if ($periodicTimerId !== null) {
                Loop::cancelTimer($periodicTimerId);
                $periodicTimerId = null;
            }
        });

        // Determine if this is a handshake failure (fails before establishing connection)
        $isHandshakeFailure = false;
        $errorMsg = '';

        if ($networkConditions['should_fail']) {
            $isHandshakeFailure = true;
            $errorMsg = $networkConditions['error_message'] ?? 'Network failure';
        } elseif ($mock->getStatusCode() >= 400) {
            $isHandshakeFailure = true;
            $errorMsg = $mock->getError() ?? "HTTP {$mock->getStatusCode()} Failure";
        } elseif ($mock->shouldFail()) {
            if ($mock->hasStreamConfig()) {
                $config = $mock->getSSEStreamConfig();
                $autoClose = isset($config['auto_close']) && is_bool($config['auto_close']) ? $config['auto_close'] : false;
                if (! $autoClose) {
                    $isHandshakeFailure = true;
                    $errorMsg = $mock->getError() ?? 'Mocked SSE failure';
                }
            } else {
                if (count($mock->getSSEEvents()) === 0) {
                    $isHandshakeFailure = true;
                    $errorMsg = $mock->getError() ?? 'Mocked SSE failure';
                }
            }
        }

        if ($isHandshakeFailure) {
            $timerId = Loop::addTimer($totalDelay, function () use (
                $promise,
                $errorMsg,
                $onError
            ) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($onError !== null) {
                    $onError($errorMsg);
                }
                $promise->reject(new NetworkException($errorMsg));
            });

            return $promise;
        }

        // If not a handshake failure, proceed to open the stream
        $timerId = Loop::addTimer($totalDelay, function () use (
            $promise,
            $mock,
            $onEvent,
            $onError,
            &$periodicTimerId,
        ) {
            if ($promise->isCancelled()) {
                return;
            }

            try {
                if ($mock->hasStreamConfig()) {
                    $this->periodicEmitter->emit($promise, $mock, $onEvent, $onError, $periodicTimerId);
                } else {
                    $lastEventId = null;
                    $retryInterval = null;
                    $immediateEmitter = new ImmediateSSEEmitter();

                    // For non-retryable SSE, if it drops mid-stream, trigger the user's onError
                    $onMidStreamError = function (string $err) use ($onError) {
                        if ($onError !== null) {
                            $onError($err);
                        }
                    };

                    $immediateEmitter->emit($promise, $mock, $onEvent, $lastEventId, $retryInterval, $onMidStreamError);
                }
            } catch (Throwable $e) {
                if ($onError !== null) {
                    $onError($e->getMessage());
                }
                if ($promise->isPending()) {
                    $promise->reject($e);
                }
            }
        });

        return $promise;
    }
}
