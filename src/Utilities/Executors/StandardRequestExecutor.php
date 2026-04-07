<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Executors;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Testing\Exceptions\UnexpectedRequestException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\CookieManager;
use Hibla\HttpClient\Testing\Utilities\Handlers\ResponseTypeHandler;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Validators\RequestValidator;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;

class StandardRequestExecutor
{
    private RequestMatcher $requestMatcher;
    private CookieManager $cookieManager;
    private RequestRecorder $requestRecorder;
    private RequestValidator $validator;
    private RetryableRequestExecutor $retryExecutor;
    private ResponseTypeHandler $responseTypeHandler;

    public function __construct(
        RequestMatcher $requestMatcher,
        ResponseFactory $responseFactory,
        CookieManager $cookieManager,
        RequestRecorder $requestRecorder,
        RequestValidator $validator,
        ResponseTypeHandler $responseTypeHandler
    ) {
        $this->requestMatcher = $requestMatcher;
        $this->cookieManager = $cookieManager;
        $this->requestRecorder = $requestRecorder;
        $this->validator = $validator;
        $this->responseTypeHandler = $responseTypeHandler;

        $this->retryExecutor = new RetryableRequestExecutor(
            $requestMatcher,
            $responseFactory,
            $requestRecorder
        );
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @param (callable(string, array<int|string, mixed>, ?RetryConfig): PromiseInterface<Response>)|null $parentSendRequest
     * @return PromiseInterface<Response|StreamingResponse|array<string, mixed>>
     */
    public function execute(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?RetryConfig $retryConfig = null,
        ?callable $parentSendRequest = null
    ): PromiseInterface {
        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        $this->validator->validateNotSSERequest($curlOnlyOptions);
        $this->cookieManager->applyCookiesForRequestOptions($curlOptions, $url);

        $method = $this->extractMethod($curlOptions);

        $matchedMock = $this->requestMatcher->findMatchingMock(
            $mockedRequests,
            $method,
            $url,
            $curlOnlyOptions
        );

        if ($matchedMock === null) {
            $this->requestRecorder->recordRequest($method, $url, $curlOptions);

            return $this->handleNoMatch(
                $url,
                $curlOptions,
                $method,
                $curlOnlyOptions,
                $mockedRequests,
                $globalSettings,
                $retryConfig,
                $parentSendRequest
            );
        }

        $promise = $this->executeMockedRequest(
            $url,
            $curlOptions,
            $matchedMock,
            $mockedRequests,
            $retryConfig
        );

        return $this->applyPostProcessing($promise, $curlOptions, $url);
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     */
    private function extractMethod(array $curlOptions): string
    {
        $method = $curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET';
        return is_string($method) ? $method : 'GET';
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     * @param array<int, mixed> $curlOnlyOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @param (callable(string, array<int|string, mixed>, ?RetryConfig): PromiseInterface<Response>)|null $parentSendRequest
     * @return PromiseInterface<Response>
     */
    private function handleNoMatch(
        string $url,
        array $curlOptions,
        string $method,
        array $curlOnlyOptions,
        array $mockedRequests,
        array $globalSettings,
        ?RetryConfig $retryConfig,
        ?callable $parentSendRequest
    ): PromiseInterface {
        if ((bool)($globalSettings['allow_passthrough'] ?? false)) {
            if ($parentSendRequest === null) {
                throw new \RuntimeException('No parent send request handler available');
            }

            return $parentSendRequest($url, $curlOptions, $retryConfig);
        }

        throw UnexpectedRequestException::noMatchFound(
            $method,
            $url,
            $curlOnlyOptions,
            $mockedRequests
        );
    }

    /**
     * @param PromiseInterface<Response|StreamingResponse|array<string, mixed>> $promise
     * @param array<int|string, mixed> $curlOptions
     * @return PromiseInterface<Response|StreamingResponse|array<string, mixed>>
     */
    private function applyPostProcessing(
        PromiseInterface $promise,
        array $curlOptions,
        string $url
    ): PromiseInterface {
        $mappedPromise = $promise->then(function (mixed $response) use ($curlOptions, $url) {
            if ($response instanceof Response) {
                $this->processCookies($response, $curlOptions, $url);
            }

            return $response;
        });

        $mappedPromise->onCancel($promise->cancel(...));

        return $mappedPromise;
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     */
    private function processCookies(Response $response, array $curlOptions, string $url): void
    {
        $rawHeaders = $response->getHeaders();
        $transformedHeaders = [];

        foreach ($rawHeaders as $key => $value) {
            if (\is_string($key)) {
                $transformedHeaders[$key] = \is_array($value) ? $value : [$value];
            }
        }

        $this->cookieManager->processResponseCookiesForOptions($transformedHeaders, $curlOptions, $url);
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     * @param array{mock: MockedRequest, index: int} $matchedMock
     * @param list<MockedRequest> $mockedRequests
     * @return PromiseInterface<Response|StreamingResponse|array<string, mixed>>
     */
    private function executeMockedRequest(
        string $url,
        array $curlOptions,
        array $matchedMock,
        array &$mockedRequests,
        ?RetryConfig $retryConfig
    ): PromiseInterface {
        $method = $this->extractMethod($curlOptions);

        if ($retryConfig !== null) {
            return $this->retryExecutor->executeWithRetry(
                $url,
                $curlOptions,
                $retryConfig,
                $method,
                $mockedRequests,
                $matchedMock
            );
        }

        $this->requestRecorder->recordRequest($method, $url, $curlOptions);

        /** @var array<string, mixed> $optionsForHandler */
        $optionsForHandler = array_filter($curlOptions, 'is_string', ARRAY_FILTER_USE_KEY);

        return $this->responseTypeHandler->handleMockedResponse(
            $matchedMock,
            $optionsForHandler,
            $mockedRequests,
            $url,
            $method
        );
    }
}