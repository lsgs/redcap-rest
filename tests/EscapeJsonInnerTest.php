<?php
/**
 * Tests for REDCapREST::escapeJsonInner()
 *
 * Verifies the pure inner-content JSON escaper: it escapes ", \, and control
 * characters (newline/tab) WITHOUT adding surrounding quotes, leaves plain text
 * untouched, and preserves unicode as valid JSON content.
 */
namespace MCRI\REDCapREST\Tests;

use PHPUnit\Framework\TestCase;
use MCRI\REDCapREST\REDCapREST;

require_once __DIR__ . '/../REDCapREST.php';

class EscapeJsonInnerTest extends TestCase
{
    private function makeModule(): REDCapREST
    {
        return $this->getMockBuilder(REDCapREST::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSubSettings', 'escape', 'query'])
            ->getMock();
    }

    private function escapeInner(REDCapREST $module, $value): string
    {
        $ref = new \ReflectionMethod(REDCapREST::class, 'escapeJsonInner');
        return $ref->invoke($module, $value);
    }

    public function testEscapesDoubleQuotes(): void
    {
        $module = $this->makeModule();
        $escaped = $this->escapeInner($module, 'O\'Brien "Bob"');

        $this->assertStringContainsString('\\"', $escaped);
        // No bare (unescaped) double-quote survives.
        $this->assertDoesNotMatchRegularExpression('/(?<!\\\\)"/', $escaped);
        // Placing it inside author-supplied quotes yields valid JSON.
        $decoded = \json_decode('{"k":"' . $escaped . '"}', true);
        $this->assertSame('O\'Brien "Bob"', $decoded['k']);
    }

    public function testEscapesBackslash(): void
    {
        $module = $this->makeModule();
        $escaped = $this->escapeInner($module, 'C:\\temp');

        $this->assertStringContainsString('\\\\', $escaped);
        $decoded = \json_decode('{"k":"' . $escaped . '"}', true);
        $this->assertSame('C:\\temp', $decoded['k']);
    }

    public function testEscapesNewline(): void
    {
        $module = $this->makeModule();
        $escaped = $this->escapeInner($module, "line1\nline2");

        $this->assertStringContainsString('\\n', $escaped);
        $this->assertStringNotContainsString("\n", $escaped);
        $decoded = \json_decode('{"k":"' . $escaped . '"}', true);
        $this->assertSame("line1\nline2", $decoded['k']);
    }

    public function testEscapesTab(): void
    {
        $module = $this->makeModule();
        $escaped = $this->escapeInner($module, "col1\tcol2");

        $this->assertStringContainsString('\\t', $escaped);
        $this->assertStringNotContainsString("\t", $escaped);
        $decoded = \json_decode('{"k":"' . $escaped . '"}', true);
        $this->assertSame("col1\tcol2", $decoded['k']);
    }

    public function testUnicodeSurvivesAsValidJson(): void
    {
        $module = $this->makeModule();
        $input = 'café — naïve ☕';
        $escaped = $this->escapeInner($module, $input);

        $decoded = \json_decode('{"k":"' . $escaped . '"}', true);
        $this->assertSame($input, $decoded['k']);
    }

    public function testPlainTextUnchanged(): void
    {
        $module = $this->makeModule();
        $this->assertSame('abc', $this->escapeInner($module, 'abc'));
    }
}
