<?php
/**
 * Tests for REDCapREST::pipeApiToken()
 *
 * Exercises the REAL pipeApiToken method (the OAuth2 suite mocks it) to verify
 * that ALL [token-ref:...] occurrences in a single string are resolved against
 * their own matching system token entry, that each resolved value is recorded
 * for log masking, and that an unresolved reference throws.
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
}
