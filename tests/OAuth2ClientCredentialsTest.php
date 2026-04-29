<?php
/**
 * Tests for OAuth2ClientCredentials
 *
 * These tests mock REDCapREST::curlCall() and REDCapREST::pipeApiToken()
 * to verify the OAuth2 Client Credentials flow in isolation from REDCap.
 */
namespace MCRI\REDCapREST\Tests;

use PHPUnit\Framework\TestCase;
use MCRI\REDCapREST\REDCapREST;
use MCRI\REDCapREST\OAuth2ClientCredentials;

require_once __DIR__ . '/../OAuth2.php';
require_once __DIR__ . '/../OAuth2ClientCredentials.php';
require_once __DIR__ . '/../REDCapREST.php';

class OAuth2ClientCredentialsTest extends TestCase
{
    private function makeInstruction(array $overrides = []): array
    {
        $config = json_encode([
            'auth-url'      => 'https://auth.example.com/token',
            'client-id'     => 'test-client-id',
            'client-secret' => 'test-client-secret',
        ]);

        return array_merge([
            'oauth2-config' => $config,
            'oauth2-cache'  => '{}',
        ], $overrides);
    }

    private function makeTokenResponse(string $token = 'fresh-token', int $expiresIn = 3600): string
    {
        return json_encode([
            'access_token' => $token,
            'expires_in'   => $expiresIn,
        ]);
    }

    private function makeMockModule(): REDCapREST
    {
        $mock = $this->getMockBuilder(REDCapREST::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['curlCall', 'pipeApiToken', 'getProjectSetting', 'setProjectSetting', 'log'])
            ->getMock();

        // pipeApiToken returns the string unchanged by default (no token-ref placeholders in tests)
        $mock->method('pipeApiToken')
            ->willReturnArgument(0);

        // getProjectSetting returns an empty array for oauth2-cache
        $mock->method('getProjectSetting')
            ->willReturn([]);

        return $mock;
    }

    // ---------------------------------------------------------------
    // Token acquisition
    // ---------------------------------------------------------------

    public function testFetchesNewTokenWhenCacheIsEmpty(): void
    {
        $module = $this->makeMockModule();

        // First call: token endpoint returns a token
        // Second call: the actual API call succeeds
        $module->expects($this->exactly(2))
            ->method('curlCall')
            ->willReturnCallback(function ($method, $url) {
                if ($url === 'https://auth.example.com/token') {
                    return [$this->makeTokenResponse('new-token', 3600), ['http_code' => 200]];
                }
                return ['{"result":"ok"}', ['http_code' => 200]];
            });

        $module->expects($this->once())
            ->method('setProjectSetting');

        $oauth2 = new OAuth2ClientCredentials($module, $this->makeInstruction(), 0);
        $oauth2->oauth2Call('POST', 'https://api.example.com/data', 'application/json', [], [], '{"key":"value"}');

        $this->assertEquals(200, $oauth2->getInfo()['http_code']);
        $this->assertEquals('{"result":"ok"}', $oauth2->getResponse());
    }

    public function testUsesClientCredentialsForTokenRequest(): void
    {
        $module = $this->makeMockModule();

        $capturedCalls = [];
        $module->method('curlCall')
            ->willReturnCallback(function ($method, $url, $contentType, $headers, $curlOptions, $payload) use (&$capturedCalls) {
                $capturedCalls[] = compact('method', 'url', 'contentType', 'headers', 'curlOptions', 'payload');
                if ($url === 'https://auth.example.com/token') {
                    return [$this->makeTokenResponse(), ['http_code' => 200]];
                }
                return ['ok', ['http_code' => 200]];
            });

        $oauth2 = new OAuth2ClientCredentials($module, $this->makeInstruction(), 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/resource', 'application/json', [], [], '');

        // Verify the token request
        $tokenCall = $capturedCalls[0];
        $this->assertEquals('POST', $tokenCall['method']);
        $this->assertEquals('https://auth.example.com/token', $tokenCall['url']);
        $this->assertEquals('application/x-www-form-urlencoded', $tokenCall['contentType']);
        $this->assertStringContainsString('grant_type=client_credentials', $tokenCall['payload']);

        // Verify CURLOPT_USERPWD is set correctly as nested array
        $this->assertIsArray($tokenCall['curlOptions']);
        $this->assertCount(1, $tokenCall['curlOptions']);
        $this->assertEquals(CURLOPT_USERPWD, $tokenCall['curlOptions'][0][0]);
        $this->assertEquals('test-client-id:test-client-secret', $tokenCall['curlOptions'][0][1]);
    }

    public function testBearerTokenIncludedInApiRequest(): void
    {
        $module = $this->makeMockModule();

        $capturedCalls = [];
        $module->method('curlCall')
            ->willReturnCallback(function ($method, $url, $contentType, $headers, $curlOptions, $payload) use (&$capturedCalls) {
                $capturedCalls[] = compact('method', 'url', 'headers');
                if ($url === 'https://auth.example.com/token') {
                    return [$this->makeTokenResponse('my-bearer-token'), ['http_code' => 200]];
                }
                return ['ok', ['http_code' => 200]];
            });

        $oauth2 = new OAuth2ClientCredentials($module, $this->makeInstruction(), 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/data', 'application/json', ['X-Custom: foo'], [], '');

        // The API call should include the Bearer token and preserve existing headers
        $apiCall = $capturedCalls[1];
        $this->assertContains('X-Custom: foo', $apiCall['headers']);
        $this->assertContains('Authorization: Bearer my-bearer-token', $apiCall['headers']);
    }

    // ---------------------------------------------------------------
    // Token caching
    // ---------------------------------------------------------------

    public function testSkipsFetchWhenCachedTokenIsValid(): void
    {
        $module = $this->makeMockModule();

        $futureExpiry = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');
        $cache = json_encode([
            'access_token'        => 'cached-token',
            'access_token_expiry' => $futureExpiry,
        ]);

        // Only one curlCall expected — the API call, no token fetch
        $module->expects($this->once())
            ->method('curlCall')
            ->willReturnCallback(function ($method, $url, $contentType, $headers) {
                $this->assertContains('Authorization: Bearer cached-token', $headers);
                return ['cached-response', ['http_code' => 200]];
            });

        // setProjectSetting should NOT be called (no new token to save)
        $module->expects($this->never())
            ->method('setProjectSetting');

        $instruction = $this->makeInstruction(['oauth2-cache' => $cache]);
        $oauth2 = new OAuth2ClientCredentials($module, $instruction, 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/data', 'application/json', [], [], '');

        $this->assertEquals('cached-response', $oauth2->getResponse());
    }

    public function testRefreshesTokenWhenExpiringSoon(): void
    {
        $module = $this->makeMockModule();

        // Token expires in 2 minutes — within the 5-minute refresh window
        $soonExpiry = (new \DateTime('+2 minutes'))->format('Y-m-d H:i:s');
        $cache = json_encode([
            'access_token'        => 'expiring-token',
            'access_token_expiry' => $soonExpiry,
        ]);

        // Two calls expected: token fetch + API call
        $module->expects($this->exactly(2))
            ->method('curlCall')
            ->willReturnCallback(function ($method, $url) {
                if ($url === 'https://auth.example.com/token') {
                    return [$this->makeTokenResponse('refreshed-token'), ['http_code' => 200]];
                }
                return ['ok', ['http_code' => 200]];
            });

        $instruction = $this->makeInstruction(['oauth2-cache' => $cache]);
        $oauth2 = new OAuth2ClientCredentials($module, $instruction, 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/data', 'application/json', [], [], '');
    }

    public function testRefreshesTokenWhenExpired(): void
    {
        $module = $this->makeMockModule();

        $pastExpiry = (new \DateTime('-10 minutes'))->format('Y-m-d H:i:s');
        $cache = json_encode([
            'access_token'        => 'expired-token',
            'access_token_expiry' => $pastExpiry,
        ]);

        $module->expects($this->exactly(2))
            ->method('curlCall')
            ->willReturnCallback(function ($method, $url) {
                if ($url === 'https://auth.example.com/token') {
                    return [$this->makeTokenResponse('new-token'), ['http_code' => 200]];
                }
                return ['ok', ['http_code' => 200]];
            });

        $instruction = $this->makeInstruction(['oauth2-cache' => $cache]);
        $oauth2 = new OAuth2ClientCredentials($module, $instruction, 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/data', 'application/json', [], [], '');
    }

    // ---------------------------------------------------------------
    // 401 retry logic
    // ---------------------------------------------------------------

    public function testRetriesOnceOn401WithFreshToken(): void
    {
        $module = $this->makeMockModule();

        $callCount = 0;
        // Expect 4 calls: token fetch, API (401), token fetch again, API (200)
        $module->expects($this->exactly(4))
            ->method('curlCall')
            ->willReturnCallback(function ($method, $url, $contentType, $headers) use (&$callCount) {
                $callCount++;
                if ($url === 'https://auth.example.com/token') {
                    $token = ($callCount <= 2) ? 'first-token' : 'second-token';
                    return [$this->makeTokenResponse($token), ['http_code' => 200]];
                }
                // First API call returns 401, second succeeds
                if ($callCount === 2) {
                    return ['Unauthorized', ['http_code' => 401]];
                }
                $this->assertContains('Authorization: Bearer second-token', $headers);
                return ['{"success":true}', ['http_code' => 200]];
            });

        $oauth2 = new OAuth2ClientCredentials($module, $this->makeInstruction(), 0);
        $oauth2->oauth2Call('POST', 'https://api.example.com/data', 'application/json', [], [], '{}');

        $this->assertEquals(200, $oauth2->getInfo()['http_code']);
        $this->assertEquals('{"success":true}', $oauth2->getResponse());
    }

    public function testDoesNotRetryWhenRetryDisabled(): void
    {
        $module = $this->makeMockModule();

        // Expect 2 calls: token fetch, API (401) — no retry
        $module->expects($this->exactly(2))
            ->method('curlCall')
            ->willReturnCallback(function ($method, $url) {
                if ($url === 'https://auth.example.com/token') {
                    return [$this->makeTokenResponse(), ['http_code' => 200]];
                }
                return ['Unauthorized', ['http_code' => 401]];
            });

        $oauth2 = new OAuth2ClientCredentials($module, $this->makeInstruction(), 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/data', 'application/json', [], [], '', false);

        $this->assertEquals(401, $oauth2->getInfo()['http_code']);
    }

    public function testDoesNotRetryOnNon401Errors(): void
    {
        $module = $this->makeMockModule();

        // Expect 2 calls: token fetch, API (500) — no retry
        $module->expects($this->exactly(2))
            ->method('curlCall')
            ->willReturnCallback(function ($method, $url) {
                if ($url === 'https://auth.example.com/token') {
                    return [$this->makeTokenResponse(), ['http_code' => 200]];
                }
                return ['Internal Server Error', ['http_code' => 500]];
            });

        $oauth2 = new OAuth2ClientCredentials($module, $this->makeInstruction(), 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/data', 'application/json', [], [], '');

        $this->assertEquals(500, $oauth2->getInfo()['http_code']);
    }

    // ---------------------------------------------------------------
    // Error handling
    // ---------------------------------------------------------------

    public function testThrowsWhenTokenEndpointReturnsError(): void
    {
        $module = $this->makeMockModule();

        $module->method('curlCall')
            ->willReturn(['{"error":"invalid_client"}', ['http_code' => 401]]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unable to obtain access token');

        $oauth2 = new OAuth2ClientCredentials($module, $this->makeInstruction(), 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/data', 'application/json', [], [], '');
    }

    public function testThrowsWhenTokenResponseMissingFields(): void
    {
        $module = $this->makeMockModule();

        $module->method('curlCall')
            ->willReturnCallback(function ($method, $url) {
                if ($url === 'https://auth.example.com/token') {
                    // Missing expires_in
                    return [json_encode(['access_token' => 'tok']), ['http_code' => 200]];
                }
                return ['ok', ['http_code' => 200]];
            });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unexpected access token response');

        $oauth2 = new OAuth2ClientCredentials($module, $this->makeInstruction(), 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/data', 'application/json', [], [], '');
    }

    public function testThrowsWhenTokenEndpointReturnsNoHttpCode(): void
    {
        $module = $this->makeMockModule();

        $module->method('curlCall')
            ->willReturn(['', []]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unable to obtain access token');

        $oauth2 = new OAuth2ClientCredentials($module, $this->makeInstruction(), 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/data', 'application/json', [], [], '');
    }

    // ---------------------------------------------------------------
    // Token persistence
    // ---------------------------------------------------------------

    public function testSavesTokenToProjectSettings(): void
    {
        $module = $this->makeMockModule();

        $module->method('curlCall')
            ->willReturnCallback(function ($method, $url) {
                if ($url === 'https://auth.example.com/token') {
                    return [$this->makeTokenResponse('saved-token', 7200), ['http_code' => 200]];
                }
                return ['ok', ['http_code' => 200]];
            });

        $module->expects($this->once())
            ->method('setProjectSetting')
            ->with(
                'oauth2-cache',
                $this->callback(function ($value) {
                    $cached = json_decode($value[0], true);
                    return $cached['access_token'] === 'saved-token'
                        && !empty($cached['access_token_expiry']);
                })
            );

        $oauth2 = new OAuth2ClientCredentials($module, $this->makeInstruction(), 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/data', 'application/json', [], [], '');
    }

    // ---------------------------------------------------------------
    // Headers isolation
    // ---------------------------------------------------------------

    public function testOriginalHeadersNotMutatedByBearerToken(): void
    {
        $module = $this->makeMockModule();

        $capturedApiHeaders = [];
        $module->method('curlCall')
            ->willReturnCallback(function ($method, $url, $contentType, $headers) use (&$capturedApiHeaders) {
                if ($url === 'https://auth.example.com/token') {
                    return [$this->makeTokenResponse(), ['http_code' => 200]];
                }
                $capturedApiHeaders = $headers;
                return ['ok', ['http_code' => 200]];
            });

        $originalHeaders = ['Accept: application/json'];
        $oauth2 = new OAuth2ClientCredentials($module, $this->makeInstruction(), 0);
        $oauth2->oauth2Call('GET', 'https://api.example.com/data', 'application/json', $originalHeaders, [], '');

        // Original array should not have been modified (PHP passes arrays by value)
        $this->assertCount(1, $originalHeaders);
        $this->assertEquals('Accept: application/json', $originalHeaders[0]);

        // But the API call should have received both headers
        $this->assertCount(2, $capturedApiHeaders);
        $this->assertContains('Accept: application/json', $capturedApiHeaders);
        $this->assertContains('Authorization: Bearer fresh-token', $capturedApiHeaders);
    }
}
