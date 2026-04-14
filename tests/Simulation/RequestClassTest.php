<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\RequestInterface as Request;
use Hibla\HttpClient\Interfaces\ResponseInterface as Response;

beforeEach(function () {
    Http::startTesting();
    Http::mock()->url('*')->persistent()->respondWith('OK')->register();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Request Class Configuration', function () {

    it('sets basic options like timeout and user agent', function () {
        Http::client()
            ->timeout(15)
            ->connectTimeout(5)
            ->withUserAgent('MyTestAgent/1.0')
            ->get('/test')
            ->wait()
        ;

        $request = Http::getLastRequest();
        $options = $request->getOptions();

        expect($options[CURLOPT_TIMEOUT])->toBe(15);
        expect($options[CURLOPT_CONNECTTIMEOUT])->toBe(5);
        expect($options[CURLOPT_USERAGENT])->toBe('MyTestAgent/1.0');
    });

    it('configures SSL verification', function () {
        Http::client()->verifySSL(false)->get('/test')->wait();
        $options = Http::getLastRequest()->getOptions();

        expect($options[CURLOPT_SSL_VERIFYPEER])->toBeFalse();
        expect($options[CURLOPT_SSL_VERIFYHOST])->toBe(0);
    });

    it('configures HTTP protocol version', function () {
        Http::client()->http1()->get('/test')->wait();
        expect(Http::getLastRequest()->getOptions()[CURLOPT_HTTP_VERSION])->toBe(CURL_HTTP_VERSION_1_1);

        Http::client()->http2()->get('/test')->wait();
        expect(Http::getLastRequest()->getOptions()[CURLOPT_HTTP_VERSION])->toBe(CURL_HTTP_VERSION_2TLS);
    });
});

describe('Request Body Helpers', function () {

    it('correctly sets a raw string body', function () {
        Http::client()->body('<xml>data</xml>')->contentType('application/xml')->post('/test')->wait();

        $request = Http::getLastRequest();
        expect($request->getBody())->toBe('<xml>data</xml>');
        expect($request->getHeaderLine('Content-Type'))->toBe('application/xml');
    });

    it('correctly sets a JSON body', function () {
        Http::client()->withJson(['user' => 'test'])->post('/test')->wait();

        $request = Http::getLastRequest();
        expect($request->getBody())->toBe(json_encode(['user' => 'test']));
        expect($request->getHeaderLine('Content-Type'))->toBe('application/json');
    });

    it('correctly sets a URL-encoded form body', function () {
        Http::client()->withForm(['user' => 'test', 'pass' => '123'])->post('/test')->wait();

        $request = Http::getLastRequest();
        expect($request->getBody())->toBe('user=test&pass=123');
        expect($request->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded');
    });
});

describe('Authentication Methods', function () {

    it('configures a bearer token', function () {
        Http::client()->withToken('my-secret-token')->get('/test')->wait();
        Http::assertBearerTokenSent('my-secret-token');
        expect(true)->toBeTrue();
    });

    it('configures basic authentication', function () {
        Http::client()->withBasicAuth('user', 'pass')->get('/test')->wait();
        $options = Http::getLastRequest()->getOptions();

        expect($options[CURLOPT_HTTPAUTH])->toBe(CURLAUTH_BASIC);
        expect($options[CURLOPT_USERPWD])->toBe('user:pass');
    });

    it('configures digest authentication', function () {
        Http::client()->withDigestAuth('user', 'pass')->get('/test')->wait();
        $options = Http::getLastRequest()->getOptions();

        expect($options[CURLOPT_HTTPAUTH])->toBe(CURLAUTH_DIGEST);
        expect($options[CURLOPT_USERPWD])->toBe('user:pass');
    });
});

describe('Cookie Helpers', function () {

    it('adds a single cookie via withCookie', function () {
        Http::client()->withCookie('name', 'value')->get('/test')->wait();
        Http::assertHeaderSent('Cookie', 'name=value');
        expect(true)->toBeTrue();
    });

    it('chains multiple cookies correctly', function () {
        Http::client()
            ->withCookie('c1', 'v1')
            ->withCookie('c2', 'v2')
            ->get('/test')
            ->wait()
        ;

        Http::assertHeaderSent('Cookie', 'c1=v1; c2=v2');
        expect(true)->toBeTrue();
    });
});

describe('Interceptors', function () {

    it('applies request interceptors before sending', function () {
        Http::client()
            ->withRequestInterceptor(function (Request $request) {
                return $request->withHeader('X-Interceptor', 'Applied');
            })
            ->get('/test')
            ->wait()
        ;

        Http::assertHeaderSent('X-Interceptor', 'Applied');
        expect(true)->toBeTrue();
    });

    it('applies response interceptors after receiving', function () {
        $response = Http::client()
            ->withResponseInterceptor(function (Response $response) {
                return $response->withHeader('X-Response-Interceptor', 'Modified');
            })
            ->get('/test')
            ->wait()
        ;

        expect($response->header('X-Response-Interceptor'))->toBe('Modified');
    });
});
