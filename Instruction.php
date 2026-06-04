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
    public $result_field;
    public $result_http_code;
    public $result_field_map;
    
    public $config_errors;
    public $config_warnings;

    public function __construct(array $instruction, ?int $instruction_index=null) {
        global $Proj;
        $this->source_project = $Proj;
        $this->sequence = ($instruction_index??0)+1;
        $this->config_errors = array();
        $this->config_warnings = array();
        if (!array_key_exists('instruction-description',$instruction)) $instruction['instruction-description'] = null;

        $simple_settings = array(
            'instruction-description','message-enabled','trigger-form','trigger-logic','dest-url','http-method','payload','content-type','curl-headers','curl-options','result-field','result-http-code','map-to-field'
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

        if (!empty($this->result_field) && !array_key_exists($this->result_field, $this->source_project->metadata)) {
            $this->config_errors[] = "invalid field for result \"".htmlspecialchars($this->result_field,ENT_QUOTES)."\"";
        }

        if (!empty($this->result_http_code) && !array_key_exists($this->result_http_code, $this->source_project->metadata)) {
            $this->config_errors[] = "invalid field for result http code \"".htmlspecialchars($this->result_http_code,ENT_QUOTES)."\"";
        }

        if (!empty($this->result_field_map) && is_array($this->result_field_map)) {
            foreach ($this->result_field_map as $idx => $pair) {
                $prop_ref = trim($pair['prop-ref']);
                $dest_field = trim($pair['dest-field']);

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
        $instructionSettings['result-field'] = $this->result_field;
        $instructionSettings['result-http-code'] = $this->result_http_code;

        foreach ($this->result_field_map as $pair) {
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