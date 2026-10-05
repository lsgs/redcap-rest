<?php
/**
 * Tests for Instruction::validateOAuth2Config()
 *
 * Covers Requirements 1 (oauth2-config completeness) and 2 (auth-url
 * token-endpoint path heuristic) of the config-validation-warnings spec.
 *
 * These tests construct an Instruction from plain setting arrays and assert on
 * getConfigWarnings()/getConfigErrors(). A minimal valid base instruction is
 * used so the ONLY warnings under test are the OAuth2 ones. The constructor
 * reads a global $Proj for form/metadata validation, so a lightweight stub is
 * installed. No system token list is injected, so scope checks (Req 3) never run.
 *
 * All credential-ish values here are placeholders; no real secrets are used.
 */
namespace MCRI\REDCapREST\Tests;

use PHPUnit\Framework\TestCase;
use MCRI\REDCapREST\Instruction;

require_once __DIR__ . '/../Instruction.php';

/**
 * Minimal stand-in for REDCap's $Proj object. Instruction only reads ->forms
 * (keyed by form name) and ->metadata (keyed by field name) during construction.
 */
class FakeProjForOAuth2Config
{
    public array $forms = ['form_a' => [], 'form_b' => []];
    public array $metadata = ['field_a' => [], 'field_b' => []];
}

class InstructionOAuth2ConfigWarningsTest extends TestCase
{
    protected function setUp(): void
    {
        // Instruction::__construct uses `global $Proj`.
        $GLOBALS['Proj'] = new FakeProjForOAuth2Config();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['Proj']);
    }

    /**
     * Minimal valid base instruction: a valid dest-url, valid http-method,
     * message-enabled flag, a trigger-form matching the fake project, and empty
     * trigger-logic (so LogicTester is never invoked). All expected keys are
     * present so no "missing expected instruction property" errors appear.
     * Overrides let each test set the oauth2 fields under test.
     */
    private function baseInstruction(array $overrides = []): array
    {
        return array_merge([
            'instruction-description' => 'test',
            'message-enabled'         => 1,
            'trigger-form'            => ['form_a'],
            'trigger-logic'           => '',
            'dest-url'                => 'https://api.example.com/data',
            'http-method'             => 'POST',
            'payload'                 => '',
            'content-type'            => 'application/json',
            'curl-headers'            => '',
            'curl-options'            => '',
            'oauth2-option'           => '',
            'oauth2-config'           => '',
            'result-field'            => '',
            'result-http-code'        => '',
            'map-to-field'            => [],
        ], $overrides);
    }

    private function oauth2Config(array $fields): string
    {
        return json_encode($fields);
    }

    /** Convenience: build an Instruction with an oauth2 option + config JSON. */
    private function withOAuth2(array $configFields, array $overrides = []): Instruction
    {
        return new Instruction($this->baseInstruction(array_merge([
            'oauth2-option' => 'client_credentials',
            'oauth2-config' => $this->oauth2Config($configFields),
        ], $overrides)));
    }

    private function joinWarnings(Instruction $i): string
    {
        return implode("\n", $i->getConfigWarnings());
    }

    private function incompleteWarnings(Instruction $i): array
    {
        return array_values(array_filter($i->getConfigWarnings(), function ($w) {
            return strpos($w, 'incomplete oauth2 configuration') !== false;
        }));
    }

    // ---------------------------------------------------------------
    // Baseline: a complete, well-formed config emits no R1/R2 warning
    // ---------------------------------------------------------------

    public function testCompleteConfigEmitsNoOAuth2Warning(): void
    {
        $i = $this->withOAuth2([
            'auth-url'      => 'https://auth.example.com/oauth2/token',
            'client-id'     => 'client-id-value',
            'client-secret' => 'client-secret-value',
        ]);

        $this->assertSame([], $i->getConfigWarnings());
    }

    // ---------------------------------------------------------------
    // Req 1: completeness — missing each key names it in the warning
    // ---------------------------------------------------------------

    public function testMissingAuthUrlNamedInWarning(): void
    {
        $i = $this->withOAuth2([
            'client-id'     => 'client-id-value',
            'client-secret' => 'client-secret-value',
        ]);

        $incomplete = $this->incompleteWarnings($i);
        $this->assertCount(1, $incomplete, 'Expected a single combined completeness warning');
        $this->assertStringContainsString('auth-url', $incomplete[0]);
        $this->assertStringNotContainsString('client-id', $incomplete[0]);
        $this->assertStringNotContainsString('client-secret', $incomplete[0]);
    }

    public function testMissingClientIdNamedInWarning(): void
    {
        $i = $this->withOAuth2([
            'auth-url'      => 'https://auth.example.com/oauth2/token',
            'client-secret' => 'client-secret-value',
        ]);

        $incomplete = $this->incompleteWarnings($i);
        $this->assertCount(1, $incomplete);
        $this->assertStringContainsString('client-id', $incomplete[0]);
        $this->assertStringNotContainsString('client-secret', $incomplete[0]);
    }

    public function testMissingClientSecretNamedInWarning(): void
    {
        $i = $this->withOAuth2([
            'auth-url'  => 'https://auth.example.com/oauth2/token',
            'client-id' => 'client-id-value',
        ]);

        $incomplete = $this->incompleteWarnings($i);
        $this->assertCount(1, $incomplete);
        $this->assertStringContainsString('client-secret', $incomplete[0]);
        $this->assertStringNotContainsString('client-id', $incomplete[0]);
    }

    public function testMultipleMissingKeysNamedInSingleCombinedWarning(): void
    {
        $i = $this->withOAuth2([
            'client-id' => 'client-id-value',
            // auth-url and client-secret both missing
        ]);

        $incomplete = $this->incompleteWarnings($i);
        $this->assertCount(1, $incomplete, 'Missing keys collapse into one combined warning');
        $this->assertStringContainsString('auth-url', $incomplete[0]);
        $this->assertStringContainsString('client-secret', $incomplete[0]);
        $this->assertStringNotContainsString('client-id', $incomplete[0]);
    }

    public function testEmptyStringValuesTreatedAsMissing(): void
    {
        $i = $this->withOAuth2([
            'auth-url'      => '',
            'client-id'     => '   ',
            'client-secret' => '',
        ]);

        $incomplete = $this->incompleteWarnings($i);
        $this->assertCount(1, $incomplete);
        $this->assertStringContainsString('auth-url', $incomplete[0]);
        $this->assertStringContainsString('client-id', $incomplete[0]);
        $this->assertStringContainsString('client-secret', $incomplete[0]);
    }

    // ---------------------------------------------------------------
    // Req 1: no oauth2 option selected -> no R1 warning at all
    // ---------------------------------------------------------------

    public function testNoOAuth2OptionProducesNoWarning(): void
    {
        // Default base instruction has an empty oauth2-option.
        $i = new Instruction($this->baseInstruction());

        $this->assertSame([], $i->getConfigWarnings());
    }

    // ---------------------------------------------------------------
    // Req 1: empty / invalid JSON -> no R1 warning, existing error intact
    // ---------------------------------------------------------------

    public function testEmptyConfigJsonProducesExistingErrorAndNoWarning(): void
    {
        $i = new Instruction($this->baseInstruction([
            'oauth2-option' => 'client_credentials',
            'oauth2-config' => '',
        ]));

        // No R1/R2 warnings are emitted for the empty-config case.
        $this->assertSame([], $i->getConfigWarnings());

        // The pre-existing "missing oauth2 configuration" error is unaffected.
        $this->assertContains('missing oauth2 configuration', $i->getConfigErrors());
    }

    public function testInvalidConfigJsonProducesExistingErrorAndNoWarning(): void
    {
        $i = new Instruction($this->baseInstruction([
            'oauth2-option' => 'client_credentials',
            'oauth2-config' => 'this-is-not-json',
        ]));

        $this->assertSame([], $i->getConfigWarnings());
        $this->assertContains('could not parse oauth2 configuration as json', $i->getConfigErrors());
    }

    // ---------------------------------------------------------------
    // Req 2: auth-url token-endpoint path heuristic
    // ---------------------------------------------------------------

    public function testAuthUrlWithNoPathWarns(): void
    {
        $i = $this->withOAuth2([
            'auth-url'      => 'https://host.example.com',
            'client-id'     => 'client-id-value',
            'client-secret' => 'client-secret-value',
        ]);

        $warnings = $this->joinWarnings($i);
        $this->assertStringContainsString('token-endpoint path', $warnings);
        $this->assertStringContainsString('host.example.com', $warnings);
        // Completeness is fine, so no "incomplete" warning here.
        $this->assertSame([], $this->incompleteWarnings($i));
    }

    public function testAuthUrlWithRootPathOnlyWarns(): void
    {
        $i = $this->withOAuth2([
            'auth-url'      => 'https://host.example.com/',
            'client-id'     => 'client-id-value',
            'client-secret' => 'client-secret-value',
        ]);

        $this->assertStringContainsString('token-endpoint path', $this->joinWarnings($i));
    }

    public function testAuthUrlWithTokenPathDoesNotWarn(): void
    {
        $i = $this->withOAuth2([
            'auth-url'      => 'https://host.example.com/oauth2/token',
            'client-id'     => 'client-id-value',
            'client-secret' => 'client-secret-value',
        ]);

        $this->assertSame([], $i->getConfigWarnings());
    }

    public function testAuthUrlWithAlternateTokenPathDoesNotWarn(): void
    {
        $i = $this->withOAuth2([
            'auth-url'      => 'https://host.example.com/oauth/token',
            'client-id'     => 'client-id-value',
            'client-secret' => 'client-secret-value',
        ]);

        $this->assertSame([], $i->getConfigWarnings());
    }
}
