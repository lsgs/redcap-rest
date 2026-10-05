<?php
/**
 * Tests for REDCapREST::pipeApiToken()
 *
 * Exercises the REAL pipeApiToken method (the OAuth2 suite mocks it) to verify:
 *  - ALL [token-ref:...] occurrences in a single string are resolved against
 *    their own matching system token entry, each resolved value is recorded
 *    for log masking, and an unresolved reference throws; and
 *  - the optional $targetURL argument scopes the token-url check independently
 *    from the resource destURL.
 *
 * All token values here are placeholders; no real secrets are used.
 */
namespace MCRI\REDCapREST\Tests;

use PHPUnit\Framework\TestCase;
use MCRI\REDCapREST\REDCapREST;

require_once __DIR__ . '/../REDCapREST.php';

class PipeApiTokenTest extends TestCase
{
    private const DEST_URL = 'https://jktdf45kye.execute-api.us-east-1.amazonaws.com/echo';
    private const CLIENT_ID_REF = 'echo-test-api-client-id';
    private const CLIENT_ID_VALUE = 'client-id-value';
    private const SECRET_REF = 'echo-test-api-secret';
    private const SECRET_VALUE = 'secret-value';

    private const HOST          = 'https://host.example.com';
    private const TOKEN_URL     = 'https://host.example.com/token';
    private const RESOURCE_URL  = 'https://host.example.com/echo';

    /**
     * Two 'specify' system token entries scoped to the same destination URL.
     */
    private function tokenManagementFixture(): array
    {
        $tokenUrl = 'https://jktdf45kye.execute-api.us-east-1.amazonaws.com';
        return [
            [
                'token-ref'           => self::CLIENT_ID_REF,
                'token-url'           => $tokenUrl,
                'token-lookup-option' => 'specify',
                'token-specified'     => self::CLIENT_ID_VALUE,
            ],
            [
                'token-ref'           => self::SECRET_REF,
                'token-url'           => $tokenUrl,
                'token-lookup-option' => 'specify',
                'token-specified'     => self::SECRET_VALUE,
            ],
        ];
    }

    /**
     * Build a module whose REDCap-dependent helpers are stubbed while the real
     * pipeApiToken runs, with $destURL set to the in-scope destination.
     */
    private function makeModule(array $tokenManagement): REDCapREST
    {
        $module = $this->getMockBuilder(REDCapREST::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSubSettings', 'escape', 'query'])
            ->getMock();

        $module->method('getSubSettings')
            ->willReturnCallback(function ($key) use ($tokenManagement) {
                return ($key === 'token-management') ? $tokenManagement : [];
            });

        // escape returns its argument unchanged (no DB layer in tests)
        $module->method('escape')->willReturnArgument(0);

        $this->setProtected($module, 'destURL', self::DEST_URL);

        return $module;
    }

    private function setProtected(object $obj, string $prop, $value): void
    {
        $ref = new \ReflectionProperty(REDCapREST::class, $prop);
        $ref->setValue($obj, $value);
    }

    private function getProtected(object $obj, string $prop)
    {
        $ref = new \ReflectionProperty(REDCapREST::class, $prop);
        return $ref->getValue($obj);
    }

    private function setDestURL(REDCapREST $module, string $url): void
    {
        $this->setProtected($module, 'destURL', $url);
    }

    private function tokenEntry(string $tokenUrl, string $tokenValue): array
    {
        return [
            'token-ref'           => 'ref',
            'token-url'           => $tokenUrl,
            'token-lookup-option' => 'specify',
            'token-specified'     => $tokenValue,
        ];
    }

    public function testResolvesBothTokenReferencesInOneString(): void
    {
        $module = $this->makeModule($this->tokenManagementFixture());

        $input = '{"client-id":"[token-ref:' . self::CLIENT_ID_REF . ']",'
               . '"client-secret":"[token-ref:' . self::SECRET_REF . ']"}';

        $result = $module->pipeApiToken($input);

        // Neither placeholder should survive, and both resolved values present.
        $this->assertStringNotContainsString('[token-ref:', $result);
        $this->assertStringContainsString(self::CLIENT_ID_VALUE, $result);
        $this->assertStringContainsString(self::SECRET_VALUE, $result);

        $expected = '{"client-id":"' . self::CLIENT_ID_VALUE . '",'
                  . '"client-secret":"' . self::SECRET_VALUE . '"}';
        $this->assertSame($expected, $result);
    }

    public function testRecordsBothResolvedTokensForMasking(): void
    {
        $module = $this->makeModule($this->tokenManagementFixture());

        $input = '{"client-id":"[token-ref:' . self::CLIENT_ID_REF . ']",'
               . '"client-secret":"[token-ref:' . self::SECRET_REF . ']"}';

        $module->pipeApiToken($input);

        $resolved = $this->getProtected($module, 'resolvedTokens');
        $this->assertIsArray($resolved);
        $this->assertArrayHasKey(self::CLIENT_ID_REF, $resolved);
        $this->assertArrayHasKey(self::SECRET_REF, $resolved);
        $this->assertSame(self::CLIENT_ID_VALUE, $resolved[self::CLIENT_ID_REF]);
        $this->assertSame(self::SECRET_VALUE, $resolved[self::SECRET_REF]);

        // Masking (as performed in redcap_save_record) must hide every value
        // from the resolved payload that would otherwise be logged.
        $payload = $module->pipeApiToken($input);
        foreach ($resolved as $ref => $value) {
            $payload = str_replace($value, '|||Token ' . $ref . ' removed|||', $payload);
        }
        $this->assertStringNotContainsString(self::CLIENT_ID_VALUE, $payload);
        $this->assertStringNotContainsString(self::SECRET_VALUE, $payload);
    }

    public function testThrowsWhenReferenceHasNoInScopeEntry(): void
    {
        // The entry exists but is scoped to a different destination URL.
        $tokenManagement = [
            [
                'token-ref'           => self::CLIENT_ID_REF,
                'token-url'           => 'https://other.example.com',
                'token-lookup-option' => 'specify',
                'token-specified'     => self::CLIENT_ID_VALUE,
            ],
        ];
        $module = $this->makeModule($tokenManagement);

        $input = '{"client-id":"[token-ref:' . self::CLIENT_ID_REF . ']"}';

        try {
            $module->pipeApiToken($input);
            $this->fail('Expected exception for unresolved token reference was not thrown.');
        } catch (\Exception $e) {
            $this->assertStringContainsString(self::CLIENT_ID_REF, $e->getMessage());
            $this->assertStringContainsString(self::DEST_URL, $e->getMessage());
        }
    }

    public function testTargetUrlResolvesWhenScopedToTokenEndpoint(): void
    {
        // Resource call targets the /echo URL, but the token entry is scoped to /token.
        $module = $this->makeModule([$this->tokenEntry(self::TOKEN_URL, 'secret-value')]);
        $this->setDestURL($module, self::RESOURCE_URL);

        $result = $module->pipeApiToken('[token-ref:ref]', self::TOKEN_URL);

        $this->assertEquals('secret-value', $result);
    }

    public function testTokenUrlAndDestUrlAreIndependent(): void
    {
        // Same /token-scoped entry, but no target: the scope falls back to the
        // /echo destURL, which does not start with /token, so it must NOT resolve.
        $module = $this->makeModule([$this->tokenEntry(self::TOKEN_URL, 'secret-value')]);
        $this->setDestURL($module, self::RESOURCE_URL);

        $this->expectException(\Exception::class);
        $module->pipeApiToken('[token-ref:ref]');
    }

    public function testResourceCallStillUsesDestUrlWhenNoTarget(): void
    {
        // Regression guard: with no target, the scope check uses destURL as before.
        $module = $this->makeModule([$this->tokenEntry(self::HOST, 'resource-token')]);
        $this->setDestURL($module, self::RESOURCE_URL);

        $result = $module->pipeApiToken('[token-ref:ref]');

        $this->assertEquals('resource-token', $result);
    }
}
