<?php
/**
 * Tests for REDCapREST::buildTokenRefHelpText() (surface-token-ref-keys, Increment E).
 *
 * buildTokenRefHelpText() is the pure, unit-testable Name_Injection_Helper. Given the
 * system `token-management` sub-settings array, it extracts the distinct, non-empty,
 * trimmed `token-ref` names (dropping malformed/empty entries, preserving first-seen
 * order), HTML-escapes each, and returns muted Help_Text listing each name as
 * `[token-ref:NAME]`. On an empty name list it returns a neutral no-references note.
 * It NEVER includes any `token-specified`/token value.
 *
 * The helper is private and side-effect free, so these tests instantiate the module
 * without its constructor (matching PipeApiTokenTest) and invoke the method via
 * reflection (matching how existing tests reach private/protected members).
 *
 * All token values used here are placeholders; no real secrets are present.
 *
 * Covers Requirements: 2.1, 2.2, 2.3, 4.1, 7.1, 7.2, 7.3.
 */
namespace MCRI\REDCapREST\Tests;

use PHPUnit\Framework\TestCase;
use MCRI\REDCapREST\REDCapREST;

require_once __DIR__ . '/../REDCapREST.php';

class InstructionTokenRefHelpTest extends TestCase
{
    /** A sentinel secret value that must never appear in any Help_Text. */
    private const SECRET_VALUE = 'super-secret-token-value-must-not-leak';

    /**
     * Build a module instance without running the (REDCap-dependent) constructor,
     * matching the pattern used by PipeApiTokenTest.
     */
    private function makeModule(): REDCapREST
    {
        return $this->getMockBuilder(REDCapREST::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /** Invoke the private buildTokenRefHelpText() via reflection. */
    private function buildHelp(array $systemTokens): string
    {
        $module = $this->makeModule();
        $method = new \ReflectionMethod(REDCapREST::class, 'buildTokenRefHelpText');
        return $method->invoke($module, $systemTokens);
    }

    /** The neutral no-references note the helper must return for an empty name list. */
    private function neutralNote(): string
    {
        return '<div class="text-muted" style="font-size:85%;">'
             . 'No system token references are defined. '
             . 'Ask your administrator to configure token references at the system level.'
             . '</div>';
    }

    /** A full system token-management entry (name + scope prefix + secret value). */
    private function tokenEntry(string $ref, string $tokenUrl = 'https://api.example.com', ?string $secret = self::SECRET_VALUE): array
    {
        return [
            'token-ref'           => $ref,
            'token-url'           => $tokenUrl,
            'token-lookup-option' => 'specify',
            'token-specified'     => $secret,
        ];
    }

    // ---------------------------------------------------------------
    // Example: names present -> each [token-ref:NAME] listed, no values
    // ---------------------------------------------------------------

    public function testNamesPresentListsEachReferenceAndNoValues(): void
    {
        $systemTokens = [
            $this->tokenEntry('alpha-ref'),
            $this->tokenEntry('beta-ref'),
            $this->tokenEntry('gamma-ref'),
        ];

        $help = $this->buildHelp($systemTokens);

        $this->assertStringContainsString('<code>[token-ref:alpha-ref]</code>', $help);
        $this->assertStringContainsString('<code>[token-ref:beta-ref]</code>', $help);
        $this->assertStringContainsString('<code>[token-ref:gamma-ref]</code>', $help);

        // Names only: no token-specified value, nor the sub-setting key itself.
        $this->assertStringNotContainsString(self::SECRET_VALUE, $help);
        $this->assertStringNotContainsString('token-specified', $help);
    }

    // ---------------------------------------------------------------
    // Example: duplicate token-ref values deduped, first-seen order
    // ---------------------------------------------------------------

    public function testDuplicateReferencesAppearExactlyOnceInFirstSeenOrder(): void
    {
        $systemTokens = [
            $this->tokenEntry('dup-ref'),
            $this->tokenEntry('other-ref'),
            $this->tokenEntry('dup-ref', 'https://other.example.com', 'another-secret'),
        ];

        $help = $this->buildHelp($systemTokens);

        // Exactly one occurrence of the duplicated reference.
        $this->assertSame(
            1,
            substr_count($help, '<code>[token-ref:dup-ref]</code>'),
            'Duplicate token-ref should be listed exactly once'
        );

        // First-seen order: dup-ref precedes other-ref.
        $posDup   = strpos($help, '[token-ref:dup-ref]');
        $posOther = strpos($help, '[token-ref:other-ref]');
        $this->assertNotFalse($posDup);
        $this->assertNotFalse($posOther);
        $this->assertLessThan($posOther, $posDup, 'Names should preserve first-seen order');
    }

    // ---------------------------------------------------------------
    // Example: empty list -> neutral no-references note
    // ---------------------------------------------------------------

    public function testEmptyListReturnsNeutralNote(): void
    {
        $help = $this->buildHelp([]);
        $this->assertSame($this->neutralNote(), $help);
    }

    // ---------------------------------------------------------------
    // Example: all-malformed list -> neutral no-references note
    // ---------------------------------------------------------------

    public function testAllMalformedEntriesReturnNeutralNote(): void
    {
        $systemTokens = [
            'not-an-array',                                  // not an array
            ['token-url' => 'https://x.example.com'],        // lacks token-ref
            ['token-ref' => 123],                            // non-string token-ref
            ['token-ref' => ''],                             // empty token-ref
            ['token-ref' => '   '],                          // whitespace-only token-ref
            ['token-ref' => null],                           // non-string token-ref
        ];

        $help = $this->buildHelp($systemTokens);
        $this->assertSame($this->neutralNote(), $help);
    }

    // ---------------------------------------------------------------
    // Example: HTML special characters in a name are escaped
    // ---------------------------------------------------------------

    public function testNameWithHtmlSpecialCharactersIsEscaped(): void
    {
        $rawName = 'a<b>&"ref"';
        $systemTokens = [$this->tokenEntry($rawName)];

        $help = $this->buildHelp($systemTokens);

        // The escaped form (ENT_QUOTES) must be present; the raw unescaped markup absent.
        $escaped = htmlspecialchars($rawName, ENT_QUOTES);
        $this->assertStringContainsString('<code>[token-ref:' . $escaped . ']</code>', $help);

        // The dangerous raw sequence (an actual <b> tag) must not be injected verbatim.
        $this->assertStringNotContainsString('[token-ref:a<b>', $help);
    }

    // ===============================================================
    // Property 1: Extracted names are exactly the distinct non-empty
    //             references (trimmed), in first-seen order.
    //             Validates Req 1.1
    // ===============================================================

    public function testPropertyExtractedNamesAreExactlyDistinctNonEmptyReferences(): void
    {
        foreach ($this->propertyCases() as $label => $case) {
            [$systemTokens, $expectedNames] = $case;

            $help = $this->buildHelp($systemTokens);

            if (empty($expectedNames)) {
                // No valid names -> neutral note, and no <code>[token-ref: items.
                $this->assertSame($this->neutralNote(), $help, "case: $label");
                $this->assertStringNotContainsString('<code>[token-ref:', $help, "case: $label");
                continue;
            }

            // Each expected (escaped) name appears exactly once...
            foreach ($expectedNames as $name) {
                $needle = '<code>[token-ref:' . htmlspecialchars($name, ENT_QUOTES) . ']</code>';
                $this->assertSame(1, substr_count($help, $needle), "case: $label name: $name");
            }

            // ...and the total count of listed items equals the number of distinct names
            // (nothing extra extracted).
            $this->assertSame(
                count($expectedNames),
                substr_count($help, '<code>[token-ref:'),
                "case: $label (item count matches distinct name count)"
            );

            // First-seen order is preserved across the full ordered expected list.
            $lastPos = -1;
            foreach ($expectedNames as $name) {
                $pos = strpos($help, '[token-ref:' . htmlspecialchars($name, ENT_QUOTES) . ']');
                $this->assertNotFalse($pos, "case: $label name: $name present");
                $this->assertGreaterThan($lastPos, $pos, "case: $label order of $name");
                $lastPos = $pos;
            }
        }
    }

    // ===============================================================
    // Property 2: Names only, never values. For any entry list -
    //             including entries carrying token-url scope context and
    //             token-specified secrets - the output contains every
    //             included name and NO token value.
    //             Validates Req 2.1, 2.2, 2.3, 7.2
    // ===============================================================

    public function testPropertyNamesOnlyNeverValues(): void
    {
        foreach ($this->propertyCases() as $label => $case) {
            [$systemTokens, $expectedNames] = $case;

            $help = $this->buildHelp($systemTokens);

            // Every valid, included name is present.
            foreach ($expectedNames as $name) {
                $this->assertStringContainsString(
                    htmlspecialchars($name, ENT_QUOTES),
                    $help,
                    "case: $label expected name present: $name"
                );
            }

            // No token-specified secret value from any entry leaks into the output,
            // even when token-url scope context exists on the entry.
            foreach ($systemTokens as $entry) {
                if (is_array($entry)
                    && isset($entry['token-specified'])
                    && is_string($entry['token-specified'])
                    && $entry['token-specified'] !== ''
                ) {
                    $this->assertStringNotContainsString(
                        $entry['token-specified'],
                        $help,
                        "case: $label secret value must not appear"
                    );
                }
            }

            // The 'token-specified' sub-setting key name is never rendered either.
            $this->assertStringNotContainsString('token-specified', $help, "case: $label");
        }
    }

    /**
     * Representative input/expected-name cases shared by both property tests.
     * Each case is [systemTokens, expectedDistinctNamesInFirstSeenOrder].
     * The cases span the input space: single/multiple names, duplicates,
     * surrounding whitespace, malformed/partial entries interleaved with valid
     * ones, scope context present, and the fully-empty / all-malformed cases.
     *
     * @return array<string, array{0: array, 1: array<int,string>}>
     */
    private function propertyCases(): array
    {
        return [
            'single name' => [
                [$this->tokenEntry('solo')],
                ['solo'],
            ],
            'multiple distinct names' => [
                [
                    $this->tokenEntry('one'),
                    $this->tokenEntry('two'),
                    $this->tokenEntry('three'),
                ],
                ['one', 'two', 'three'],
            ],
            'duplicates deduped, first-seen order' => [
                [
                    $this->tokenEntry('b'),
                    $this->tokenEntry('a'),
                    $this->tokenEntry('b', 'https://other.example.com', 'secret-2'),
                    $this->tokenEntry('a', 'https://third.example.com', 'secret-3'),
                ],
                ['b', 'a'],
            ],
            'surrounding whitespace trimmed' => [
                [
                    $this->tokenEntry('  padded  '),
                    $this->tokenEntry("tabbed\t"),
                ],
                ['padded', 'tabbed'],
            ],
            'malformed interleaved with valid' => [
                [
                    'not-an-array',
                    $this->tokenEntry('valid-1'),
                    ['token-url' => 'https://x.example.com'],   // lacks token-ref
                    ['token-ref' => 42],                         // non-string
                    $this->tokenEntry('valid-2'),
                    ['token-ref' => '   '],                      // whitespace only
                ],
                ['valid-1', 'valid-2'],
            ],
            'scope context present, still names only' => [
                [
                    $this->tokenEntry('scoped-ref', 'https://scoped.example.com', 'scoped-secret-xyz'),
                ],
                ['scoped-ref'],
            ],
            'empty list' => [
                [],
                [],
            ],
            'all malformed' => [
                [
                    'x',
                    ['token-ref' => ''],
                    ['token-ref' => null],
                    ['no-token-ref' => 'y'],
                ],
                [],
            ],
        ];
    }

    // ===============================================================
    // injectTokenRefHelp() — recursive structure walk.
    //
    // injectTokenRefHelp(array $settings, string $helpText): array
    // recursively finds the three Token_Ref_Fields (payload, curl-headers,
    // oauth2-config) anywhere in the $settings structure (descending into any
    // sub_settings) and APPENDS $helpText to each matched field's 'name',
    // leaving everything else byte-identical. It mutates only 'name', never
    // 'key', 'type', 'choices', or any editable value.
    //
    // Covers Requirements: 1.2, 3.1, 3.2, 3.3, 3.4, 4.2.
    // ===============================================================

    /** Invoke the private injectTokenRefHelp() via reflection. */
    private function inject(array $settings, string $helpText): array
    {
        $module = $this->makeModule();
        $method = new \ReflectionMethod(REDCapREST::class, 'injectTokenRefHelp');
        return $method->invoke($module, $settings, $helpText);
    }

    /** The three keys that must receive the Help_Text. */
    private const TARGET_KEYS = ['payload', 'curl-headers', 'oauth2-config'];

    /**
     * A $settings fixture mirroring the real config.json shape: a top-level
     * `summary-page` descriptive setting plus a repeatable `message-config`
     * sub_settings container whose nested sub_settings include the three target
     * fields interleaved with several non-target fields.
     *
     * @return array<int, array<string, mixed>>
     */
    private function settingsFixture(): array
    {
        return [
            [
                'key'  => 'summary-page',
                'name' => 'Summary page: <a href="#">view</a>',
                'type' => 'descriptive',
            ],
            [
                'key'  => 'section-header-1',
                'name' => 'Message configuration',
                'type' => 'descriptive',
            ],
            [
                'key'     => 'message-config',
                'name'    => 'Message configuration',
                'type'    => 'sub_settings',
                'repeatable' => true,
                'sub_settings' => [
                    [
                        'key'  => 'title',
                        'name' => 'Title',
                        'type' => 'text',
                    ],
                    [
                        'key'  => 'url',
                        'name' => 'Destination URL',
                        'type' => 'text',
                    ],
                    [
                        'key'  => 'payload',
                        'name' => 'Payload',
                        'type' => 'textarea',
                    ],
                    [
                        'key'  => 'curl-headers',
                        'name' => 'cURL headers',
                        'type' => 'textarea',
                    ],
                    [
                        'key'  => 'method',
                        'name' => 'HTTP method',
                        'type' => 'dropdown',
                        'choices' => [
                            ['value' => 'POST', 'name' => 'POST'],
                            ['value' => 'GET', 'name' => 'GET'],
                        ],
                    ],
                    [
                        'key'  => 'oauth2-config',
                        'name' => 'OAuth2 configuration',
                        'type' => 'textarea',
                    ],
                    [
                        'key'  => 'trigger',
                        'name' => 'Trigger logic',
                        'type' => 'text',
                    ],
                ],
            ],
        ];
    }

    /**
     * Flatten every setting definition (top-level + any nested sub_settings)
     * into a single list keyed by its original position path, so a test can
     * compare each field before/after injection.
     *
     * @return array<string, array<string, mixed>> path => definition
     */
    private function flatten(array $settings, string $prefix = ''): array
    {
        $out = [];
        foreach ($settings as $i => $def) {
            if (!is_array($def)) {
                $out[$prefix . $i] = $def;
                continue;
            }
            $path = $prefix . ($def['key'] ?? (string) $i);
            $out[$path] = $def;
            if (isset($def['sub_settings']) && is_array($def['sub_settings'])) {
                $out += $this->flatten($def['sub_settings'], $path . '/');
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Help appended to exactly the three target fields and nowhere else
    // ---------------------------------------------------------------

    public function testHelpAppendedToExactlyTheThreeTargetFields(): void
    {
        $help     = '<div class="text-muted">HELP-TEXT-SENTINEL</div>';
        $before   = $this->settingsFixture();
        $after    = $this->inject($before, $help);

        $flatBefore = $this->flatten($before);
        $flatAfter  = $this->flatten($after);

        // Exactly the three target fields have the help text appended to 'name'.
        foreach ($flatAfter as $path => $def) {
            if (!is_array($def) || !isset($def['key'])) {
                continue;
            }
            $originalName = $flatBefore[$path]['name'] ?? '';
            if (in_array($def['key'], self::TARGET_KEYS, true)) {
                $this->assertSame(
                    $originalName . $help,
                    $def['name'],
                    "target field '{$def['key']}' should have help appended"
                );
            } else {
                $this->assertSame(
                    $originalName,
                    $def['name'] ?? '',
                    "non-target field '{$def['key']}' name must be unchanged"
                );
            }
        }

        // Help text appears exactly three times across the whole structure.
        $this->assertSame(
            3,
            substr_count(json_encode($after), 'HELP-TEXT-SENTINEL'),
            'Help text must appear in exactly the three target fields'
        );
    }

    // ---------------------------------------------------------------
    // Non-target field name byte-identical; every key/type unchanged
    // ---------------------------------------------------------------

    public function testNonTargetNamesByteIdenticalAndKeysTypesUnchanged(): void
    {
        $help   = '<div>HELP</div>';
        $before = $this->settingsFixture();
        $after  = $this->inject($before, $help);

        $flatBefore = $this->flatten($before);
        $flatAfter  = $this->flatten($after);

        // Same set of fields before and after (no fields added/removed).
        $this->assertSame(array_keys($flatBefore), array_keys($flatAfter));

        foreach ($flatAfter as $path => $def) {
            $orig = $flatBefore[$path];

            // key and type are never altered for any field.
            if (isset($orig['key'])) {
                $this->assertSame($orig['key'], $def['key'], "key unchanged for $path");
            }
            if (isset($orig['type'])) {
                $this->assertSame($orig['type'], $def['type'], "type unchanged for $path");
            }

            // Non-target field names are byte-identical.
            $isTarget = isset($def['key']) && in_array($def['key'], self::TARGET_KEYS, true);
            if (!$isTarget && isset($orig['name'])) {
                $this->assertSame(
                    $orig['name'],
                    $def['name'],
                    "non-target field name byte-identical for $path"
                );
            }
        }

        // 'choices' on the non-target 'method' field is left intact.
        $this->assertSame(
            $flatBefore['message-config/method']['choices'],
            $flatAfter['message-config/method']['choices'],
            'choices on non-target field must be untouched'
        );
    }

    // ---------------------------------------------------------------
    // Empty-list Help_Text still injected without altering structure
    // ---------------------------------------------------------------

    public function testEmptyListHelpStillInjectedWithoutAlteringStructure(): void
    {
        // The neutral note is what buildTokenRefHelpText returns for an empty list;
        // injection must still append it to the three targets and leave structure intact.
        $help   = $this->neutralNote();
        $before = $this->settingsFixture();
        $after  = $this->inject($before, $help);

        $flatBefore = $this->flatten($before);
        $flatAfter  = $this->flatten($after);

        // Same shape (same field paths) before and after.
        $this->assertSame(array_keys($flatBefore), array_keys($flatAfter));

        foreach ($flatAfter as $path => $def) {
            $orig = $flatBefore[$path];
            if (isset($orig['key'])) {
                $this->assertSame($orig['key'], $def['key'], "key unchanged for $path");
            }
            if (isset($orig['type'])) {
                $this->assertSame($orig['type'], $def['type'], "type unchanged for $path");
            }

            $isTarget = isset($def['key']) && in_array($def['key'], self::TARGET_KEYS, true);
            if ($isTarget) {
                $this->assertSame($orig['name'] . $help, $def['name']);
            } elseif (isset($orig['name'])) {
                $this->assertSame($orig['name'], $def['name']);
            }
        }
    }

    // ===============================================================
    // Property 3: Help text injected into exactly the target fields.
    //             For any $settings structure (arbitrarily nested
    //             sub_settings) and any help text, a field's 'name' is
    //             changed (original + help appended) IFF its 'key' is one
    //             of payload/curl-headers/oauth2-config; every other
    //             field's 'name' is unchanged.
    //             Validates Req 1.2, 3.1, 3.2, 3.3, 3.4
    // ===============================================================

    public function testPropertyHelpInjectedIntoExactlyTargetFields(): void
    {
        $help = '<HELPMARK/>';

        foreach ($this->injectionCases() as $label => $settings) {
            $after      = $this->inject($settings, $help);
            $flatBefore = $this->flatten($settings);
            $flatAfter  = $this->flatten($after);

            // Structure preserved: same field paths before and after.
            $this->assertSame(
                array_keys($flatBefore),
                array_keys($flatAfter),
                "case: $label structure preserved"
            );

            foreach ($flatAfter as $path => $def) {
                if (!is_array($def) || !isset($def['key'])) {
                    continue;
                }
                $orig      = $flatBefore[$path];
                $origName  = isset($orig['name']) && is_string($orig['name']) ? $orig['name'] : '';
                $isTarget  = in_array($def['key'], self::TARGET_KEYS, true);

                if ($isTarget) {
                    $this->assertSame(
                        $origName . $help,
                        $def['name'],
                        "case: $label target '{$def['key']}' at $path appended"
                    );
                } else {
                    // Non-target: name unchanged (whatever it was, including absent).
                    $this->assertSame(
                        $orig['name'] ?? null,
                        $def['name'] ?? null,
                        "case: $label non-target '{$def['key']}' at $path unchanged"
                    );
                }
            }
        }
    }

    // ===============================================================
    // Property 4: Only displayed names are mutated. For any $settings
    //             structure and any help text (including the empty-list
    //             neutral note), injection changes only the 'name' of
    //             target fields; every field's 'key' and 'type' and all
    //             editable values remain identical to the input.
    //             Validates Req 4.2
    // ===============================================================

    public function testPropertyOnlyDisplayedNamesAreMutated(): void
    {
        $helpTexts = ['<HELPMARK/>', $this->neutralNote()];

        foreach ($helpTexts as $help) {
            foreach ($this->injectionCases() as $label => $settings) {
                $after      = $this->inject($settings, $help);
                $flatBefore = $this->flatten($settings);
                $flatAfter  = $this->flatten($after);

                foreach ($flatAfter as $path => $def) {
                    if (!is_array($def)) {
                        continue;
                    }
                    $orig = $flatBefore[$path];

                    // key, type, choices and any non-name scalar/array value unchanged.
                    foreach ($orig as $field => $value) {
                        if ($field === 'name' || $field === 'sub_settings') {
                            continue;
                        }
                        $this->assertSame(
                            $value,
                            $def[$field] ?? null,
                            "case: $label field '$field' at $path unchanged"
                        );
                    }

                    // name of non-target fields unchanged.
                    $isTarget = isset($def['key']) && in_array($def['key'], self::TARGET_KEYS, true);
                    if (!$isTarget && array_key_exists('name', $orig)) {
                        $this->assertSame(
                            $orig['name'],
                            $def['name'] ?? null,
                            "case: $label non-target name at $path unchanged"
                        );
                    }
                }
            }
        }
    }

    /**
     * Representative $settings structures spanning the input space for the
     * injection property tests: the realistic fixture, deeply nested
     * sub_settings, targets at the top level, non-array elements interleaved,
     * target fields with a missing 'name', and a structure with no targets.
     *
     * @return array<string, array<int, mixed>>
     */
    private function injectionCases(): array
    {
        return [
            'realistic fixture' => $this->settingsFixture(),

            'targets at top level' => [
                ['key' => 'payload', 'name' => 'P', 'type' => 'textarea'],
                ['key' => 'other', 'name' => 'O', 'type' => 'text'],
                ['key' => 'curl-headers', 'name' => 'C', 'type' => 'textarea'],
                ['key' => 'oauth2-config', 'name' => 'A', 'type' => 'textarea'],
            ],

            'deeply nested targets' => [
                [
                    'key'  => 'outer',
                    'name' => 'Outer',
                    'type' => 'sub_settings',
                    'sub_settings' => [
                        ['key' => 'mid-text', 'name' => 'Mid', 'type' => 'text'],
                        [
                            'key'  => 'inner',
                            'name' => 'Inner',
                            'type' => 'sub_settings',
                            'sub_settings' => [
                                ['key' => 'payload', 'name' => 'Deep payload', 'type' => 'textarea'],
                                ['key' => 'noise', 'name' => 'Noise', 'type' => 'text'],
                                ['key' => 'oauth2-config', 'name' => 'Deep oauth', 'type' => 'textarea'],
                            ],
                        ],
                    ],
                ],
            ],

            'target with missing name' => [
                ['key' => 'payload', 'type' => 'textarea'], // no 'name'
                ['key' => 'curl-headers', 'name' => 'C', 'type' => 'textarea'],
            ],

            'no targets present' => [
                ['key' => 'summary-page', 'name' => 'S', 'type' => 'descriptive'],
                [
                    'key'  => 'wrap',
                    'name' => 'Wrap',
                    'type' => 'sub_settings',
                    'sub_settings' => [
                        ['key' => 'a', 'name' => 'A', 'type' => 'text'],
                        ['key' => 'b', 'name' => 'B', 'type' => 'text'],
                    ],
                ],
            ],
        ];
    }

    // ===============================================================
    // redcap_module_configuration_settings($project_id, $settings)
    //
    // The configuration hook. In the project branch (non-empty
    // $project_id) it (a) rewrites the summary-page href from "#" to the
    // resolved summary.php URL (Req 6.1), and (b) reads the system
    // token-management sub-settings, builds the names-only Help_Text, and
    // injects it into the three Token_Ref_Fields (Req 1.1, 1.2, 1.3,
    // 3.x). In the system branch (empty $project_id) it returns $settings
    // unchanged with respect to both modifications (Req 5.1, 5.2). The
    // full settings structure is returned with both modifications applied
    // (Req 6.2).
    //
    // Covers Requirements: 5.1, 5.2, 6.1, 6.2.
    // ===============================================================

    /**
     * Build a module whose getUrl() and getSubSettings() are mocked so the
     * hook can run without a REDCap installation.
     *
     * @param string $summaryUrl  value getUrl('summary.php', false, false) returns
     * @param array  $systemTokens value getSubSettings('token-management') returns
     */
    private function makeHookModule(string $summaryUrl, array $systemTokens): REDCapREST
    {
        $module = $this->getMockBuilder(REDCapREST::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUrl', 'getSubSettings'])
            ->getMock();

        $module->method('getUrl')->willReturn($summaryUrl);
        $module->method('getSubSettings')->willReturn($systemTokens);

        return $module;
    }

    /**
     * A $settings fixture for the hook: a top-level summary-page descriptive
     * setting carrying an href="#" link, plus the message-config sub_settings
     * container holding the three target fields.
     *
     * @return array<int, array<string, mixed>>
     */
    private function hookSettingsFixture(): array
    {
        return [
            [
                'key'  => 'summary-page',
                'name' => 'Summary page: <a href="#">view summary</a>',
                'type' => 'descriptive',
            ],
            [
                'key'  => 'section-header-1',
                'name' => 'Message configuration',
                'type' => 'descriptive',
            ],
            [
                'key'     => 'message-config',
                'name'    => 'Message configuration',
                'type'    => 'sub_settings',
                'repeatable' => true,
                'sub_settings' => [
                    ['key' => 'title',         'name' => 'Title',                'type' => 'text'],
                    ['key' => 'url',           'name' => 'Destination URL',      'type' => 'text'],
                    ['key' => 'payload',       'name' => 'Payload',              'type' => 'textarea'],
                    ['key' => 'curl-headers',  'name' => 'cURL headers',         'type' => 'textarea'],
                    ['key' => 'oauth2-config', 'name' => 'OAuth2 configuration', 'type' => 'textarea'],
                    ['key' => 'trigger',       'name' => 'Trigger logic',        'type' => 'text'],
                ],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Empty $project_id -> settings returned identical to input
    // ---------------------------------------------------------------

    public function testEmptyProjectIdReturnsSettingsUnchanged(): void
    {
        $settings = $this->hookSettingsFixture();

        // getUrl / getSubSettings must never be consulted in the system branch.
        $module = $this->getMockBuilder(REDCapREST::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUrl', 'getSubSettings'])
            ->getMock();
        $module->expects($this->never())->method('getUrl');
        $module->expects($this->never())->method('getSubSettings');

        // Empty project id covers both '' and null callers of the system dialog.
        $this->assertSame($settings, $module->redcap_module_configuration_settings('', $settings));
        $this->assertSame($settings, $module->redcap_module_configuration_settings(null, $settings));
    }

    // ---------------------------------------------------------------
    // Non-empty $project_id -> summary href rewritten AND help injected
    // ---------------------------------------------------------------

    public function testProjectIdRewritesSummaryHrefAndInjectsHelp(): void
    {
        $summaryUrl   = 'https://redcap.example.org/redcap_v99/ExternalModules/?prefix=redcap_rest&page=summary';
        $systemTokens = [
            $this->tokenEntry('alpha-ref'),
            $this->tokenEntry('beta-ref'),
        ];

        $module = $this->makeHookModule($summaryUrl, $systemTokens);
        $after  = $module->redcap_module_configuration_settings('42', $this->hookSettingsFixture());

        // --- Req 6.1: summary-page href rewritten from "#" to the resolved URL.
        $summary = null;
        foreach ($after as $def) {
            if (($def['key'] ?? null) === 'summary-page') {
                $summary = $def;
                break;
            }
        }
        $this->assertNotNull($summary, 'summary-page setting present');
        $this->assertStringContainsString('href="' . $summaryUrl . '"', $summary['name']);
        $this->assertStringNotContainsString('href="#"', $summary['name']);

        // --- Req 1.2, 3.x: Help_Text (names-only) injected into the three targets.
        $flat = $this->flatten($after);
        foreach (self::TARGET_KEYS as $key) {
            $path = 'message-config/' . $key;
            $this->assertArrayHasKey($path, $flat, "target '$key' present");
            $this->assertStringContainsString('<code>[token-ref:alpha-ref]</code>', $flat[$path]['name']);
            $this->assertStringContainsString('<code>[token-ref:beta-ref]</code>', $flat[$path]['name']);
        }

        // --- Req 6.2: both modifications applied in the single returned structure.
        // Non-target fields keep their original names; no help leaks beyond targets.
        $encoded = json_encode($after);
        $this->assertSame(
            3,
            substr_count($encoded, '[token-ref:alpha-ref]'),
            'Help injected into exactly the three target fields'
        );

        // --- Req 2.x: no secret token value appears anywhere in the output.
        $this->assertStringNotContainsString(self::SECRET_VALUE, $encoded);
    }

    // ---------------------------------------------------------------
    // Non-empty $project_id, no system token names -> neutral note injected,
    // summary href still rewritten
    // ---------------------------------------------------------------

    public function testProjectIdWithNoTokenNamesInjectsNeutralNoteAndRewritesHref(): void
    {
        $summaryUrl   = 'https://redcap.example.org/summary';
        $systemTokens = []; // no system token references defined

        $module = $this->makeHookModule($summaryUrl, $systemTokens);
        $after  = $module->redcap_module_configuration_settings('7', $this->hookSettingsFixture());

        // Summary href still rewritten (Req 6.1).
        $summary = null;
        foreach ($after as $def) {
            if (($def['key'] ?? null) === 'summary-page') {
                $summary = $def;
                break;
            }
        }
        $this->assertNotNull($summary);
        $this->assertStringContainsString('href="' . $summaryUrl . '"', $summary['name']);

        // Neutral note injected into each target; field structure intact (Req 4.1, 4.2).
        $flat = $this->flatten($after);
        foreach (self::TARGET_KEYS as $key) {
            $def = $flat['message-config/' . $key];
            $this->assertStringContainsString($this->neutralNote(), $def['name']);
            // key and type untouched.
            $this->assertSame($key, $def['key']);
            $this->assertSame('textarea', $def['type']);
        }
    }
}
