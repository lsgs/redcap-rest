<?php
/**
 * Tests for Instruction::validateTokenRefScopes() (Requirement 3).
 *
 * Verifies the advisory configuration-time scope validation of
 * [token-ref:NAME] references injected via the constructor's third
 * $systemTokens argument:
 *
 *   - Unknown reference name -> config error (Req 3.1).
 *   - Known name, out of scope -> config warning (Req 3.2).
 *   - oauth2-config refs are scoped against the config's auth-url and
 *     payload/dest-url refs against dest-url, independently (Req 3.3).
 *   - All refs resolve + in scope -> no scope message (Req 3.4).
 *   - Empty $systemTokens -> no scope error/warning (Req 3.5).
 *
 * The Instruction constructor reads `global $Proj` for form/metadata checks,
 * so a minimal stub project is installed. All token values here are
 * placeholders; no real secrets are used.
 */
namespace MCRI\REDCapREST\Tests;

use PHPUnit\Framework\TestCase;
use MCRI\REDCapREST\Instruction;

require_once __DIR__ . '/../Instruction.php';

class InstructionTokenRefScopeTest extends TestCase
{
    private const DEST_URL = 'https://api.example.com/data';
    private const AUTH_URL = 'https://auth.example.com/token';

    protected function setUp(): void
    {
        // Instruction's constructor validates trigger-form/result fields against
        // $Proj->forms and $Proj->metadata. The base instruction below leaves
        // those empty, so an empty-but-present project keeps the base clean.
        $GLOBALS['Proj'] = (object) [
            'forms'    => ['form_a' => []],
            'metadata' => [],
        ];
    }

    /**
     * A minimal, fully valid instruction so that the only messages produced by
     * the constructor are the token-ref scope checks under test. Callers pass
     * overrides (payload, oauth2 options, dest-url) to inject references.
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

    /** A system token-management entry (name + scope prefix). */
    private function tokenEntry(string $ref, string $tokenUrl): array
    {
        return [
            'token-ref'           => $ref,
            'token-url'           => $tokenUrl,
            'token-lookup-option' => 'specify',
            'token-specified'     => 'placeholder-value',
        ];
    }

    private function oauth2Config(string $authUrl): string
    {
        return json_encode([
            'auth-url'      => $authUrl,
            'client-id'     => 'cid',
            'client-secret' => 'csecret',
        ]);
    }

    // ---------------------------------------------------------------
    // 3.1 Unknown reference name -> error
    // ---------------------------------------------------------------

    public function testUnknownRefInPayloadProducesError(): void
    {
        $instruction = $this->baseInstruction([
            'payload' => '{"id":"[token-ref:does-not-exist]"}',
        ]);

        // System list contains a differently-named entry, so the ref name is unknown.
        $systemTokens = [$this->tokenEntry('some-other-ref', 'https://api.example.com')];

        $inst = new Instruction($instruction, 0, $systemTokens);

        $errors = $inst->getConfigErrors();
        $joined = implode("\n", $errors);
        $this->assertStringContainsString('does-not-exist', $joined);
        $this->assertStringContainsString('no system token entry with that name', $joined);

        // An unknown reference is an error, not merely a warning.
        $this->assertStringNotContainsString('does-not-exist', implode("\n", $inst->getConfigWarnings()));
    }

    // ---------------------------------------------------------------
    // 3.2 Known name but out of scope -> warning
    // ---------------------------------------------------------------

    public function testKnownRefOutOfScopeProducesWarning(): void
    {
        $instruction = $this->baseInstruction([
            'payload' => '{"id":"[token-ref:my-ref]"}',
        ]);

        // Name matches, but the scope prefix does not cover the dest-url.
        $systemTokens = [$this->tokenEntry('my-ref', 'https://other.example.com')];

        $inst = new Instruction($instruction, 0, $systemTokens);

        $warnings = implode("\n", $inst->getConfigWarnings());
        $this->assertStringContainsString('my-ref', $warnings);
        $this->assertStringContainsString('out of scope', $warnings);
        $this->assertStringContainsString(self::DEST_URL, $warnings);

        // A name match that is merely out of scope is a warning, not an error.
        $this->assertStringNotContainsString('my-ref', implode("\n", $inst->getConfigErrors()));
    }

    // ---------------------------------------------------------------
    // 3.3 Independent scoping of oauth2-config (auth-url) vs payload (dest-url)
    // ---------------------------------------------------------------

    /**
     * An oauth2-config ref must be scoped against auth-url, NOT dest-url.
     * The entry's prefix covers auth-url but not dest-url: it should resolve
     * in scope (no message). If the code wrongly checked it against dest-url,
     * a warning would appear.
     */
    public function testOauth2ConfigRefScopedAgainstAuthUrlNotDestUrl(): void
    {
        // Embed the token-ref inside the oauth2-config JSON (e.g. a scoped secret).
        $config = json_encode([
            'auth-url'      => self::AUTH_URL,
            'client-id'     => 'cid',
            'client-secret' => '[token-ref:auth-ref]',
        ]);

        $instruction = $this->baseInstruction([
            'oauth2-option' => '1',
            'oauth2-config' => $config,
            'dest-url'      => self::DEST_URL,
        ]);

        // Prefix covers auth-url (https://auth.example.com) but NOT dest-url.
        $systemTokens = [$this->tokenEntry('auth-ref', 'https://auth.example.com')];

        $inst = new Instruction($instruction, 0, $systemTokens);

        $this->assertEmpty($inst->getConfigErrors(), 'auth-ref should resolve against auth-url, no error expected');
        $this->assertSame(
            [],
            array_values(array_filter($inst->getConfigWarnings(), function ($w) {
                return strpos($w, 'auth-ref') !== false;
            })),
            'auth-ref is in scope for auth-url; no scope warning expected'
        );
    }

    /**
     * A payload ref must be scoped against dest-url, NOT auth-url.
     * The entry's prefix covers auth-url but not dest-url, so a payload ref
     * using it is out of scope -> warning. This proves the payload ref is
     * NOT checked against auth-url (which would have resolved in scope).
     */
    public function testPayloadRefScopedAgainstDestUrlNotAuthUrl(): void
    {
        $instruction = $this->baseInstruction([
            'oauth2-option' => '1',
            'oauth2-config' => $this->oauth2Config(self::AUTH_URL),
            'payload'       => '{"id":"[token-ref:dest-ref]"}',
            'dest-url'      => self::DEST_URL,
        ]);

        // Prefix covers auth-url but NOT dest-url. If the payload ref were
        // (incorrectly) scoped against auth-url it would resolve; against
        // dest-url it is out of scope -> warning.
        $systemTokens = [$this->tokenEntry('dest-ref', 'https://auth.example.com')];

        $inst = new Instruction($instruction, 0, $systemTokens);

        $warnings = implode("\n", $inst->getConfigWarnings());
        $this->assertStringContainsString('dest-ref', $warnings);
        $this->assertStringContainsString('out of scope', $warnings);
        $this->assertStringContainsString(self::DEST_URL, $warnings);
    }

    /**
     * Both references in a single instruction, each scoped to its own target.
     * The auth entry covers only auth-url; the dest entry covers only dest-url.
     * Correct independent scoping => both resolve, no messages at all.
     */
    public function testBothRefsResolveWhenEachScopedToItsOwnTarget(): void
    {
        $config = json_encode([
            'auth-url'      => self::AUTH_URL,
            'client-id'     => 'cid',
            'client-secret' => '[token-ref:auth-ref]',
        ]);

        $instruction = $this->baseInstruction([
            'oauth2-option' => '1',
            'oauth2-config' => $config,
            'payload'       => '{"id":"[token-ref:dest-ref]"}',
            'dest-url'      => self::DEST_URL,
        ]);

        $systemTokens = [
            $this->tokenEntry('auth-ref', 'https://auth.example.com'),
            $this->tokenEntry('dest-ref', 'https://api.example.com'),
        ];

        $inst = new Instruction($instruction, 0, $systemTokens);

        $this->assertEmpty($inst->getConfigErrors());
        $this->assertEmpty($inst->getConfigWarnings());
    }

    // ---------------------------------------------------------------
    // 3.4 All refs resolve + in scope -> no scope message
    // ---------------------------------------------------------------

    public function testInScopeRefProducesNoMessage(): void
    {
        $instruction = $this->baseInstruction([
            'payload'  => '{"id":"[token-ref:my-ref]"}',
            'dest-url' => self::DEST_URL,
        ]);

        // Scope prefix covers the dest-url.
        $systemTokens = [$this->tokenEntry('my-ref', 'https://api.example.com')];

        $inst = new Instruction($instruction, 0, $systemTokens);

        $this->assertEmpty($inst->getConfigErrors());
        $this->assertEmpty($inst->getConfigWarnings());
    }

    // ---------------------------------------------------------------
    // 3.5 Empty systemTokens -> no scope error/warning
    // ---------------------------------------------------------------

    public function testEmptySystemTokensEmitsNoScopeMessage(): void
    {
        // A ref that would be "unknown" if any list were present; with an empty
        // list the scope check is skipped entirely.
        $instruction = $this->baseInstruction([
            'payload' => '{"id":"[token-ref:anything]"}',
        ]);

        $inst = new Instruction($instruction, 0, []);

        $this->assertEmpty($inst->getConfigErrors());
        $this->assertEmpty($inst->getConfigWarnings());
    }

    public function testDefaultSystemTokensEmitsNoScopeMessage(): void
    {
        // Omitting the third argument entirely must behave like an empty list.
        $instruction = $this->baseInstruction([
            'payload' => '{"id":"[token-ref:anything]"}',
        ]);

        $inst = new Instruction($instruction, 0);

        $this->assertEmpty($inst->getConfigErrors());
        $this->assertEmpty($inst->getConfigWarnings());
    }
}
