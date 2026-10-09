<?php
/**
 * REDCap External Module: REDCap REST
 * Send API calls when saving particular instruments when a trigger condition is met.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\REDCapREST;

class Instruction {
    public $source_project;
    public $sequence;
    public $instruction_description;
    public $message_enabled;
    public $trigger_form;
    public $trigger_logic;
    public $dest_url;
    public $http_method;
    public $payload;
    public $content_type;
    public $curl_headers;
    public $curl_options;
    public $oauth2_option;
    public $oauth2_config;
    public $result_field;
    public $result_http_code;
    public $map_to_field;
    
    public $config_errors;
    public $config_warnings;

    public $system_tokens;

    public function __construct(array $instruction, ?int $instruction_index=null, array $systemTokens=array()) {
        global $Proj;
        $this->source_project = $Proj;
        $this->sequence = ($instruction_index??0)+1;
        $this->config_errors = array();
        $this->config_warnings = array();
        $this->system_tokens = $systemTokens;
        if (!array_key_exists('instruction-description',$instruction)) $instruction['instruction-description'] = null;

        $simple_settings = array(
            'instruction-description','message-enabled','trigger-form','trigger-logic','dest-url','http-method','payload','content-type','curl-headers','curl-options','oauth2-option','oauth2-config','result-field','result-http-code','map-to-field'
        );
        try {
            foreach ($simple_settings as $expected_setting) {
                if (array_key_exists($expected_setting, $instruction)) {
                    $prop = str_replace('-','_',$expected_setting);
                    $this->$prop = $instruction[$expected_setting];
                } else {
                    $this->config_errors[] = "missing expected instruction property: $expected_setting";
                }
            }
        } catch (\Throwable $th) {
            $this->config_errors[] = "error in module settings: ".$th->getMessage();
        }

        if (!($this->message_enabled == 0 || $this->message_enabled == 1)) {
            $this->config_errors[] = "invalid value for copy enabled: 0 or 1 expected";
        } else {
            $this->message_enabled = (bool)$this->message_enabled;
        }

        if (!empty($this->trigger_form)) {
            $badFormNames = array_diff($this->trigger_form, array_keys($this->source_project->forms));
            if (!empty($badFormNames)) $this->config_errors[] = "invalid trigger form(s): ".implode(', ', $badFormNames);
        }

        if (!empty($this->trigger_logic) && !\LogicTester::isValid($this->trigger_logic)) {
            $this->config_errors[] = "invalid trigger logic";
        }

        if (empty($this->dest_url) || filter_var($this->dest_url, FILTER_VALIDATE_URL)===false) {
            $this->config_errors[] = "a valid destination url is required";
        }

        $allowed_methods = array('POST','GET','PUT','PATCH','DELETE');
        if (empty($this->http_method) || !in_array($this->http_method, $allowed_methods)) {
            $this->config_errors[] = "a valid http method is required (".implode(', ', $allowed_methods).")";
        }

        if (!empty($this->oauth2_option) && empty($this->oauth2_config)) {
            $this->config_errors[] = "missing oauth2 configuration";
        } else if (!empty($this->oauth2_option) && is_null(\json_decode($this->oauth2_config, true))) {
            $this->config_errors[] = "could not parse oauth2 configuration as json";
        }

        if (!empty($this->result_field) && !array_key_exists($this->result_field, $this->source_project->metadata)) {
            $this->config_errors[] = "invalid field for result \"".htmlspecialchars($this->result_field,ENT_QUOTES)."\"";
        }

        if (!empty($this->result_http_code) && !array_key_exists($this->result_http_code, $this->source_project->metadata)) {
            $this->config_errors[] = "invalid field for result http code \"".htmlspecialchars($this->result_http_code,ENT_QUOTES)."\"";
        }

        if (!empty($this->map_to_field) && is_array($this->map_to_field)) {
            foreach ($this->map_to_field as $idx => $pair) {
                $prop_ref = trim($pair['prop-ref']);
                $dest_field = trim($pair['dest-field']);

                if ($idx===0 && empty($prop_ref) && empty($dest_field)) continue; // allow first pair to be empty (no mapping)
                
                if (is_null($prop_ref) || $prop_ref==='') {
                    $this->config_errors[] = "property reference required for result mapping, pair #".($idx+1);
                }

                if (empty($dest_field)) {
                    $this->config_errors[] = "missing field name for result mapping, pair #".($idx+1);
                } else if (!array_key_exists($dest_field, $this->source_project->metadata)) {
                    $this->config_errors[] = "invalid field name for result mapping \"".htmlspecialchars($dest_field,ENT_QUOTES)."\", pair #".($idx+1);
                }
            }
        }

        $this->validateOAuth2Config();
        $this->validateTokenRefScopes();
    }

    /**
     * validateOAuth2Config()
     * Advisory configuration-time checks for OAuth2 client-credentials setup
     * (Requirements 1 & 2). Runs only when an OAuth2 option is selected AND the
     * oauth2-config decodes to an array; the empty/invalid-JSON cases are already
     * reported as errors elsewhere and are not duplicated here. Emits warnings
     * only; never throws for any input shape.
     * @return void
     */
    private function validateOAuth2Config(): void {
        if (empty($this->oauth2_option)) return;

        $decoded = \json_decode((string)$this->oauth2_config, true);
        if (!is_array($decoded)) return; // empty/invalid JSON already handled as an error

        $missing = array();
        foreach (array('auth-url','client-id','client-secret') as $key) {
            $value = (isset($decoded[$key]) && is_string($decoded[$key])) ? trim($decoded[$key]) : $decoded[$key] ?? null;
            if (!isset($decoded[$key]) || $value === '' || $value === null) {
                $missing[] = $key;
            }
        }
        if (!empty($missing)) {
            $escaped = array_map(function($k) { return htmlspecialchars($k, ENT_QUOTES); }, $missing);
            $this->config_warnings[] = "incomplete oauth2 configuration, missing: ".implode(', ', $escaped);
        }

        $authUrl = (isset($decoded['auth-url']) && is_string($decoded['auth-url'])) ? trim($decoded['auth-url']) : '';
        if ($authUrl !== '') {
            $path = parse_url($authUrl, PHP_URL_PATH);
            if ($path === null || $path === false || $path === '' || $path === '/') {
                $this->config_warnings[] = "oauth2 auth-url \"".htmlspecialchars($authUrl, ENT_QUOTES)."\" appears to be missing a token-endpoint path";
            }
        }
    }

    /**
     * extractTokenRefs()
     * Extract the distinct NAME values from every [token-ref:NAME] occurrence in
     * the supplied text, using the same pattern as REDCapREST::pipeApiToken() so
     * extraction matches resolution exactly. Null/empty input yields an empty
     * array and never throws.
     * @param string|null $text
     * @return array distinct reference names (in order of first appearance)
     */
    private function extractTokenRefs(?string $text): array {
        if ($text === null || $text === '') return array();
        $pattern = "/\[token-ref:([-\w]+)\]/";
        $matches = array();
        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) return array();
        $refs = array();
        foreach ($matches as $match) {
            $ref = $match[1];
            if (!in_array($ref, $refs, true)) $refs[] = $ref;
        }
        return $refs;
    }

    /**
     * validateTokenRefScopes()
     * Advisory configuration-time validation of [token-ref:NAME] references
     * (Requirement 3). Mirrors the runtime scope semantics of
     * REDCapREST::pipeApiToken(): a reference resolves against a system token
     * entry only when the entry's name matches AND the applicable target URL
     * begins with the entry's scope prefix (token-url).
     *
     * Target URL per field (Req 3.3):
     *   - oauth2-config -> the config's auth-url (token endpoint)
     *   - payload / curl-headers / dest-url -> the instruction's dest-url
     *
     * Severity (Req 3.1 / 3.2):
     *   - unknown reference name (no system entry with that name, any scope) -> error
     *   - name matches but none in scope for the target -> warning
     *
     * Skipped entirely when no system token list was injected (Req 3.5 / empty-list
     * safety). oauth2-config refs are skipped when auth-url is missing/empty (the
     * missing auth-url is already surfaced by Req 1; treated as "cannot evaluate").
     * Never resolves tokens and never emits secret values (Req 6). Never throws.
     * @return void
     */
    private function validateTokenRefScopes(): void {
        if (!is_array($this->system_tokens) || empty($this->system_tokens)) return;

        $destUrl = is_string($this->dest_url) ? $this->dest_url : '';

        // Resolve the oauth2-config target (auth-url) once, if evaluable.
        $authUrl = null; // null = cannot evaluate (skip oauth2-config refs)
        $decoded = \json_decode((string)$this->oauth2_config, true);
        if (is_array($decoded) && isset($decoded['auth-url']) && is_string($decoded['auth-url'])) {
            $candidate = trim($decoded['auth-url']);
            if ($candidate !== '') $authUrl = $candidate;
        }

        // field => target URL (or null to skip the field)
        $fields = array(
            'oauth2_config' => $authUrl,
            'payload'       => $destUrl,
            'curl_headers'  => $destUrl,
            'dest_url'      => $destUrl,
        );

        $reported = array(); // dedupe keys of form "ref\x00target"

        foreach ($fields as $prop => $target) {
            if ($target === null || $target === '') continue; // cannot evaluate scope

            $text = isset($this->$prop) && is_string($this->$prop) ? $this->$prop : '';
            if ($text === '') continue;

            foreach ($this->extractTokenRefs($text) as $ref) {
                $dedupeKey = $ref."\x00".$target;
                if (isset($reported[$dedupeKey])) continue;
                $reported[$dedupeKey] = true;

                $nameMatches = array();   // scope prefixes for entries whose name matches
                $inScope = false;
                foreach ($this->system_tokens as $entry) {
                    if (!is_array($entry)) continue;
                    $entryRef = isset($entry['token-ref']) && is_string($entry['token-ref']) ? $entry['token-ref'] : null;
                    if ($entryRef !== $ref) continue;

                    $scope = isset($entry['token-url']) && is_string($entry['token-url']) ? $entry['token-url'] : '';
                    $nameMatches[] = $scope;
                    if (str_starts_with($target, $scope)) {
                        $inScope = true;
                        break;
                    }
                }

                $refEsc = htmlspecialchars($ref, ENT_QUOTES);
                $targetEsc = htmlspecialchars($target, ENT_QUOTES);

                if (empty($nameMatches)) {
                    // Req 3.1: entirely unknown reference name -> error
                    $this->config_errors[] = "unresolved token reference \"$refEsc\": no system token entry with that name";
                } else if (!$inScope) {
                    // Req 3.2: name exists but not in scope for this target -> warning
                    $scopesEsc = array();
                    foreach ($nameMatches as $scope) {
                        if (!in_array($scope, $scopesEsc, true)) {
                            $scopesEsc[] = htmlspecialchars($scope, ENT_QUOTES);
                        }
                    }
                    $this->config_warnings[] = "token reference \"$refEsc\" may be out of scope: configured scope prefix(es) ".implode(', ', $scopesEsc)." do not cover target url \"$targetEsc\"";
                }
            }
        }
    }

    /**
     * getAsModuleSettings()
     * @return array array of settings as per module project settings from $module->getProjectSettings()
     */
    public function getAsModuleSettings(): array {
        $instructionSettings = array();
        $instructionSettings['instruction-description'] = $this->instruction_description;
        $instructionSettings['message-enabled'] = $this->message_enabled;
        $instructionSettings['trigger-form'] = $this->trigger_form;
        $instructionSettings['trigger-logic'] = $this->trigger_logic;
        $instructionSettings['dest-url'] = $this->dest_url;
        $instructionSettings['http-method'] = $this->http_method;
        $instructionSettings['payload'] = $this->payload;
        $instructionSettings['content-type'] = $this->content_type;
        $instructionSettings['curl-headers'] = $this->curl_headers;
        $instructionSettings['curl-options'] = $this->curl_options;
        $instructionSettings['oauth2-option'] = $this->oauth2_option;
        $instructionSettings['oauth2-config'] = $this->oauth2_config;
        $instructionSettings['result-field'] = $this->result_field;
        $instructionSettings['result-http-code'] = $this->result_http_code;

        foreach ($this->map_to_field as $pair) {
            $instructionSettings['map-to-field'][] = 'true';
            $instructionSettings['prop-ref'][] = $pair['prop-ref'];
            $instructionSettings['dest-field'][] = $pair['dest-field'];
        }
        return $instructionSettings;
    }

    /**
     * getProperty()
     * Return the property value matching the string key (if not matched try replacing - with _ in key)
     * @param string property 
     * @return mixed
     */
    public function getProperty(string $property_name): mixed {
        if (property_exists($this, $property_name)) {
            return $this->$property_name;
        } else if (str_contains($property_name, '-')) {
            return $this->getProperty(str_replace('-','_',$property_name));
        }
        return null;
    }

    public function getConfigErrors(): array {
        return $this->config_errors;
    }
    public function getConfigWarnings(): array {
        return $this->config_warnings;
    }
}