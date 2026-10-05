<?php
/**
 * Tests for Instruction config-validation severity, non-blocking behaviour,
 * and secret non-exposure (Increment D — config-validation-warnings).
 *
 * Covers Requirements 4 and 6:
 *  - Adding advisory warnings does not change the set of existing errors
 *    (Req 4.2, 4.3): a config that triggers warnings but no NEW error retains
 *    exactly the existing errors it would have had with no system-token list.
 *  - The unresolved-reference error (Req 3.1) is the ONLY new error type this
 *    increment introduces into config_errors (Req 4.1).
 *  - No resolved token/secret value ever appears in any error or warning
 *    message (Req 6.1, 6.2): a sentinel value placed in a systemTokens entry's
 *    secret-ish fields must never surface in getConfigErrors()/getConfigWarnings().
 *
 * The Instruction constructor never resolves tokens, so the sentinel is
 * structurally unavailable to messages; these tests prove that guarantee holds.
 *
 * All token values here are placeholders / sentinels; no real secrets are used.
 */
namespace MCRI\REDCapREST\Tests;

use PHPUnit\Framework\TestCase;
use MCRI\REDCapREST\Instruction;

require_once __DIR__ . '/../Instruction.php';

// Instruction::__construct references \LogicTester::isValid() for trigger-logic.
// Provide a permissive stub if the real/other-test stub isn't already loaded.
if (!class_exists('LogicTester')) {
    class LogicTester {
        public static function isValid($logic) { return true; }
    }
}

class InstructionWarningSeverityTest extends TestCase
{
    /** Sentinel values that must NEVER appear in any error/warning message. */
    private const SENTINEL          = 'SENTINEL-SECRET-VALUE';
    private const SENTINEL_ALT      = 'SENTINEL-SECRET-VALUE-ALT';

    private const DEST_URL          = 'https://api.example.com/echo';
    private const DEST_SCOPE        = 'https://api.example.com';
    private const AUTH_URL          = 'https://auth.example.com/oauth2/token';
    private const AUTH_SCOPE        = 'https://auth.example.com';
    private const OTHER_SCOPE       = 'https://other.example.com';

    protected function setUp(): void
    {
        parent::setUp();
        // Instruction::__construct reads `global $Proj` and inspects
        // ->forms / ->metadata. Supply a project stub with empty maps so that
        // trigger-form / result-field checks pass for our base instructions.
        $GLOBALS['Proj'] = new class {
            public $forms = [];
            public $metadata = [];
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['Proj']);
        parent::tearDown();
    }

    /**
     * A fully-valid base instruction that produces NO existing errors:
     * all expected simple settings present, valid dest-url + http method,
     * message enabled, and no trigger/result/map fields to validate.
     */
    private function baseInstruction(array $overrides = []): array
    {
        return array_merge([
            'instruction-description' => 'test',
            'message-enabled'         => 1,
            'trigger-form'            => [],
            'trigger-logic'           => '',
            'dest-url'                => self::DEST_URL,
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

    /** A systemTokens entry carrying sentinel secret values in secret-ish fields. */
    private function tokenEntry(string $ref, string $scope): array
    {
        return [
            'token-ref'           => $ref,
            'token-url'           => $scope,
            'token-lookup-option' => 'specify',
            // Secret-ish fields — these must never leak into messages.
            'token-specified'     => self::SENTINEL,
            'token-value'         => self::SENTINEL_ALT,
        ];
    }

    private function allMessages(Instruction $instruction): array
    {
        return array_merge(
            $instruction->getConfigErrors(),
            $instruction->getConfigWarnings()
        );
    }

    // -----------------------------------------------------------------
    // Req 4.2 / 4.3 — adding warnings does not alter existing errors
    // -----------------------------------------------------------------

    /**
     * A config whose only new signal is a scope-mismatch WARNING must have the
     * exact same set of existing errors it would have had with no token list.
     * The warning is purely additive to config_warnings; config_errors is
     * untouched.
     */
    public function testWarningsDoNotChangeExistingErrors(): void
    {
        // oauth2-config ref whose matching entry is scoped to a DIFFERENT host
        // than the auth-url -> produces a scope-mismatch WARNING, not an error.
        $config = json_encode([
            'auth-url'      => self::AUTH_URL,
            'client-id'     => 'cid',
            'client-secret' => 'csecret',
            'extra'         => '[token-ref:svc]',
        ]);
        $instructionData = $this->baseInstruction([
            'oauth2-option' => 'client_credentials',
            'oauth2-config' => $config,
        ]);

        // Same config evaluated WITHOUT a system-token list (scope checks skipped).
        $errorsOnly = (new Instruction($instructionData))->getConfigErrors();

        // Same config WITH a token list that triggers a scope-mismatch warning.
        $withWarnings = new Instruction(
            $instructionData,
            null,
            [$this->tokenEntry('svc', self::OTHER_SCOPE)]
        );

        // The warning path must have produced at least one warning...
        $this->assertNotEmpty(
            $withWarnings->getConfigWarnings(),
            'Expected a scope-mismatch warning for the out-of-scope oauth2-config ref'
        );
        // ...but the errors must be identical to the warning-free evaluation.
        $this->assertSame(
            $errorsOnly,
            $withWarnings->getConfigErrors(),
            'Adding warnings must not change the set/count of existing errors'
        );
    }

    /**
     * When warnings are added on top of a config that ALSO has pre-existing
     * errors, those existing errors remain exactly as they were (unchanged in
     * count and content).
     */
    public function testExistingErrorsRemainUnchangedWhenWarningsAdded(): void
    {
        // Invalid dest-url -> existing error; incomplete oauth2 -> R1 warning.
        $config = json_encode(['auth-url' => self::AUTH_URL]); // missing client-id/secret
        $instructionData = $this->baseInstruction([
            'dest-url'      => 'not-a-valid-url',
            'oauth2-option' => 'client_credentials',
            'oauth2-config' => $config,
        ]);

        $errorsOnly = (new Instruction($instructionData))->getConfigErrors();
        $withWarnings = new Instruction($instructionData, null, []);

        $this->assertcontains(
            'a valid destination url is required',
            $errorsOnly,
            'Sanity: the invalid dest-url must be an existing error'
        );
        $this->assertSame(
            $errorsOnly,
            $withWarnings->getConfigErrors(),
            'Existing error checks must remain unchanged'
        );
        $this->assertNotEmpty(
            $withWarnings->getConfigWarnings(),
            'Expected an incomplete-oauth2 warning alongside the existing error'
        );
    }

    // -----------------------------------------------------------------
    // Req 4.1 — unresolved reference is the ONLY new error type
    // -----------------------------------------------------------------

    /**
     * An unknown token-ref name (no system entry with that name) is the single
     * new error this increment adds. With a token list present, it appears in
     * config_errors; without the list it does not (scope checks skipped).
     */
    public function testUnresolvedReferenceIsTheOnlyNewError(): void
    {
        $instructionData = $this->baseInstruction([
            'payload' => '{"k":"[token-ref:unknown-ref]"}',
        ]);

        // No system-token list -> no new error (and no existing errors here).
        $baseline = new Instruction($instructionData);
        $this->assertSame([], $baseline->getConfigErrors());

        // With a token list that lacks the referenced name -> one new error.
        $withTokens = new Instruction(
            $instructionData,
            null,
            [$this->tokenEntry('some-other-ref', self::DEST_SCOPE)]
        );

        $newErrors = array_values(array_diff(
            $withTokens->getConfigErrors(),
            $baseline->getConfigErrors()
        ));

        $this->assertCount(1, $newErrors, 'Exactly one new error should be introduced');
        $this->assertStringContainsString('unresolved token reference', $newErrors[0]);
        $this->assertStringContainsString('unknown-ref', $newErrors[0]);
    }

    /**
     * A merely out-of-scope reference (name exists) must NOT add any error —
     * it is a warning only. This confirms the severity split (Req 3.1 vs 3.2).
     */
    public function testOutOfScopeReferenceDoesNotAddError(): void
    {
        $instructionData = $this->baseInstruction([
            'payload' => '{"k":"[token-ref:svc]"}',
        ]);

        $baseline = new Instruction($instructionData);
        $withTokens = new Instruction(
            $instructionData,
            null,
            [$this->tokenEntry('svc', self::OTHER_SCOPE)] // name matches, wrong scope
        );

        $this->assertSame(
            $baseline->getConfigErrors(),
            $withTokens->getConfigErrors(),
            'An out-of-scope (but known) ref must not add an error'
        );
        $this->assertNotEmpty(
            $withTokens->getConfigWarnings(),
            'An out-of-scope ref must produce a warning'
        );
    }

    // -----------------------------------------------------------------
    // Req 6.1 / 6.2 — no secret value appears in any message
    // -----------------------------------------------------------------

    /**
     * Place sentinel secret values in a systemTokens entry's secret-ish fields,
     * construct an instruction that triggers a scope-mismatch WARNING, and
     * assert neither sentinel appears in any error or warning message.
     */
    public function testSentinelNeverAppearsInWarningMessages(): void
    {
        $instructionData = $this->baseInstruction([
            'payload' => '{"k":"[token-ref:svc]"}',
        ]);

        // Entry name matches but scope is wrong -> scope-mismatch warning.
        $instruction = new Instruction(
            $instructionData,
            null,
            [$this->tokenEntry('svc', self::OTHER_SCOPE)]
        );

        $this->assertNotEmpty(
            $instruction->getConfigWarnings(),
            'Expected a scope-mismatch warning for this fixture'
        );

        foreach ($this->allMessages($instruction) as $message) {
            $this->assertStringNotContainsString(self::SENTINEL, $message, 'Secret value leaked into a message');
            $this->assertStringNotContainsString(self::SENTINEL_ALT, $message, 'Secret value leaked into a message');
        }
    }

    /**
     * Same guarantee for the ERROR path: an unresolved reference produces an
     * error, and even entries carrying sentinel secrets must not leak them.
     */
    public function testSentinelNeverAppearsInErrorMessages(): void
    {
        $instructionData = $this->baseInstruction([
            'payload' => '{"k":"[token-ref:unknown-ref]"}',
        ]);

        $instruction = new Instruction(
            $instructionData,
            null,
            [$this->tokenEntry('some-other-ref', self::DEST_SCOPE)]
        );

        $this->assertNotEmpty(
            $instruction->getConfigErrors(),
            'Expected an unresolved-reference error for this fixture'
        );

        foreach ($this->allMessages($instruction) as $message) {
            $this->assertStringNotContainsString(self::SENTINEL, $message, 'Secret value leaked into a message');
            $this->assertStringNotContainsString(self::SENTINEL_ALT, $message, 'Secret value leaked into a message');
        }
    }

    /**
     * Secret non-exposure must also hold across BOTH scope targets at once:
     * an oauth2-config ref (checked against auth-url) and a payload ref (checked
     * against dest-url), each matching a sentinel-carrying out-of-scope entry.
     */
    public function testSentinelNeverAppearsAcrossBothScopeTargets(): void
    {
        $config = json_encode([
            'auth-url'      => self::AUTH_URL,
            'client-id'     => 'cid',
            'client-secret' => 'csecret',
            'extra'         => '[token-ref:auth-svc]',
        ]);
        $instructionData = $this->baseInstruction([
            'oauth2-option' => 'client_credentials',
            'oauth2-config' => $config,
            'payload'       => '{"k":"[token-ref:data-svc]"}',
        ]);

        $instruction = new Instruction(
            $instructionData,
            null,
            [
                // auth-svc known but scoped to the resource host (wrong for auth-url)
                $this->tokenEntry('auth-svc', self::DEST_SCOPE),
                // data-svc known but scoped to the auth host (wrong for dest-url)
                $this->tokenEntry('data-svc', self::AUTH_SCOPE),
            ]
        );

        $messages = $this->allMessages($instruction);
        $this->assertNotEmpty($messages, 'Expected scope-mismatch warnings for both refs');

        foreach ($messages as $message) {
            $this->assertStringNotContainsString(self::SENTINEL, $message, 'Secret value leaked into a message');
            $this->assertStringNotContainsString(self::SENTINEL_ALT, $message, 'Secret value leaked into a message');
        }
    }
}
