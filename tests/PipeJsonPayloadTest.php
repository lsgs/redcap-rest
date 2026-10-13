<?php
/**
 * Integration tests for the application/json branch of REDCapREST::pipe().
 *
 * Exercises the per-value JSON escaping path by mocking the plain-text piping
 * seam (pipePlainText) so canned record values can be returned without a REDCap
 * install. Verifies that piped values are escaped for JSON INNER content without
 * the module adding or removing author-controlled quotes, that the ______
 * missing-value sentinels still normalize to "" / null, that {{...}} skip-piping
 * bypasses piping+escaping and is reinstated verbatim, and that [token-ref:...]
 * resolves to valid, un-double-escaped JSON.
 *
 * All token values here are placeholders; no real secrets are used.
 */
namespace MCRI\REDCapREST\Tests;

use PHPUnit\Framework\TestCase;
use MCRI\REDCapREST\REDCapREST;

require_once __DIR__ . '/../REDCapREST.php';

class PipeJsonPayloadTest extends TestCase
{
    private const DEST_URL = 'https://host.example.com/echo';

    /**
     * Canned placeholder -> resolved value map used by the pipePlainText mock.
     * A bare placeholder passed to pipePlainText returns its mapped value; the
     * special [empty] maps to the REDCap missing-value sentinel ______ .
     */
    private function resolvedValues(): array
    {
        return [
            '[name]'  => 'O\'Brien "Bob"',
            '[note]'  => "line1\nline2\tcol",
            '[path]'  => 'C:\\temp',
            '[u]'     => 'café ☕',
            '[plain]' => 'abc',
            '[empty]' => '______',
            // Changes if piped — used to prove {{...}} skip-piping bypassed piping.
            '[consentdt]' => 'PIPED-SHOULD-NOT-APPEAR',
        ];
    }

    private function makeModule(array $tokenManagement = []): REDCapREST
    {
        $values = $this->resolvedValues();

        $module = $this->getMockBuilder(REDCapREST::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['pipePlainText', 'getSubSettings', 'escape', 'query'])
            ->getMock();

        $module->method('pipePlainText')
            ->willReturnCallback(function ($string) use ($values) {
                return array_key_exists($string, $values) ? $values[$string] : $string;
            });

        $module->method('getSubSettings')
            ->willReturnCallback(function ($key) use ($tokenManagement) {
                return ($key === 'token-management') ? $tokenManagement : [];
            });

        $module->method('escape')->willReturnArgument(0);

        $this->setProtected($module, 'destURL', self::DEST_URL);
        $this->setProtected($module, 'record', '1');
        $this->setProtected($module, 'event_id', 1);
        $this->setProtected($module, 'instance', 1);
        $this->setProtected($module, 'resolvedTokens', []);

        return $module;
    }

    private function setProtected(object $obj, string $prop, $value): void
    {
        $ref = new \ReflectionProperty(REDCapREST::class, $prop);
        $ref->setValue($obj, $value);
    }

    private function pipe(REDCapREST $module, string $string, string $contentType): string
    {
        $ref = new \ReflectionMethod(REDCapREST::class, 'pipe');
        return $ref->invoke($module, $string, $contentType);
    }

    private function formatPayload(REDCapREST $module, string $raw, string $contentType): string
    {
        $ref = new \ReflectionMethod(REDCapREST::class, 'formatPayload');
        return $ref->invoke($module, $raw, $contentType);
    }

    public function testQuotedPlaceholderWithDoubleQuotesStaysValidJson(): void
    {
        $module = $this->makeModule();
        $result = $this->pipe($module, '{"a":"[name]"}', 'application/json');

        $this->assertStringContainsString('\\"', $result);
        $decoded = \json_decode($result, true);
        $this->assertSame('O\'Brien "Bob"', $decoded['a']);
    }

    public function testBackslashEscaped(): void
    {
        $module = $this->makeModule();
        $result = $this->pipe($module, '{"p":"[path]"}', 'application/json');

        $decoded = \json_decode($result, true);
        $this->assertSame('C:\\temp', $decoded['p']);
    }

    public function testNewlineAndTabEscaped(): void
    {
        $module = $this->makeModule();
        $result = $this->pipe($module, '{"n":"[note]"}', 'application/json');

        $this->assertStringContainsString('\\n', $result);
        $this->assertStringContainsString('\\t', $result);
        $decoded = \json_decode($result, true);
        $this->assertSame("line1\nline2\tcol", $decoded['n']);
    }

    public function testUnicodeSurvives(): void
    {
        $module = $this->makeModule();
        $result = $this->pipe($module, '{"u":"[u]"}', 'application/json');

        $decoded = \json_decode($result, true);
        $this->assertSame('café ☕', $decoded['u']);
    }

    public function testEmptyQuotedBecomesEmptyString(): void
    {
        $module = $this->makeModule();
        // Hard-coded missing-value sentinel inside author quotes.
        $result = $this->pipe($module, '{"a":"______"}', 'application/json');

        $decoded = \json_decode($result, true);
        $this->assertSame('', $decoded['a']);
    }

    public function testEmptyBareBecomesNull(): void
    {
        $module = $this->makeModule();
        $result = $this->pipe($module, '{"b":______}', 'application/json');

        $decoded = \json_decode($result, true);
        $this->assertArrayHasKey('b', $decoded);
        $this->assertNull($decoded['b']);
    }

    public function testSkipPipingBypassesPipingAndEscaping(): void
    {
        $module = $this->makeModule();
        // {{...}} content must be reinstated verbatim (unpiped, unescaped) while
        // [name] is piped and escaped.
        $result = $this->formatPayload(
            $module,
            '{"logic":"{{[consentdt]}}","a":"[name]"}',
            'application/json'
        );

        // The {{...}} content was reinstated verbatim: the raw placeholder text
        // survives and the piped value never appeared.
        $this->assertStringContainsString('[consentdt]', $result);
        $this->assertStringNotContainsString('PIPED-SHOULD-NOT-APPEAR', $result);

        $decoded = \json_decode($result, true);
        $this->assertSame('[consentdt]', $decoded['logic']);
        $this->assertSame('O\'Brien "Bob"', $decoded['a']);
    }

    public function testTokenRefResolvesToValidJson(): void
    {
        $tokenManagement = [
            [
                'token-ref'           => 'ref',
                'token-url'           => 'https://host.example.com',
                'token-lookup-option' => 'specify',
                'token-specified'     => 'tokenvalue123',
            ],
        ];
        $module = $this->makeModule($tokenManagement);

        $result = $this->pipe($module, '{"t":"[token-ref:ref]"}', 'application/json');

        $decoded = \json_decode($result, true);
        // Resolves to the token value, not double-escaped, appearing once.
        $this->assertSame('tokenvalue123', $decoded['t']);
        $this->assertSame(1, \substr_count($result, 'tokenvalue123'));
    }

    public function testNoSpecialCharPayloadUnchanged(): void
    {
        $module = $this->makeModule();
        // Quoted placeholder stays a string; bare numeric stays bare — the module
        // adds no quotes (author-controlled quoting).
        $result = $this->pipe($module, '{"x":"[plain]","y":42}', 'application/json');

        $decoded = \json_decode($result, true);
        $this->assertSame('abc', $decoded['x']);
        $this->assertSame(42, $decoded['y']);
    }
}
