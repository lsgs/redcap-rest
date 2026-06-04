<?php
/**
 * REDCap External Module: REDCap REST
 * Send API calls when saving particular instruments when a trigger condition is met.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\REDCapREST;

use ExternalModules\AbstractExternalModule;

require_once 'Instruction.php';
require_once 'ModuleSettingsManager.php';

class REDCapREST extends AbstractExternalModule {
    const MODULE_TITLE = "REDCap REST";
    protected const DISPLAY_MAX_FIELD_MAP = 5;
    protected const IMPORT_ACTION = 'import-instructions';
    protected $configArray;
    protected $Proj;
    protected $record;
    protected $event_id;
    protected $instrument;
    protected $instance;
    protected $destURL;
    protected $token;
    protected $tokenRef;
    protected $curlOpts;
    protected $title;
    
	function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance=1) {
        global $Proj;
        $this->Proj = $Proj;
        $this->title = self::MODULE_TITLE." external module";
		$settings = $this->getSubSettings('message-config');

        foreach($settings as $i => $instruction) {
            if (!$instruction['message-enabled']) continue; 
            if (array_search($instrument, $instruction['trigger-form'])===false) continue;
            if (!empty($instruction['trigger-logic']) && true!==\REDCap::evaluateLogic($instruction['trigger-logic'], $project_id, $record, $event_id, $repeat_instance)) continue;
            
            $this->record = $record;
            $this->event_id = $event_id;
            $this->instrument = $instrument;
            $this->instance = $repeat_instance;

            $this->destURL = $this->pipe($instruction['dest-url']);
            $this->token = '';
            $this->tokenRef = '';
            $method = $instruction['http-method'];
            $contentType = $this->makeContentType($instruction['content-type']);
            $curlHeaders = $this->makeCurlHeadersArray($instruction['curl-headers']);
            $curlOptions = $this->makeCurlOptionsArray($instruction['curl-options']);
            $resultField = $instruction['result-field'];
            $resultCodeField = $instruction['result-http-code'];

            $resultMap = $instruction['map-to-field'];
            $resultMap = (is_array($resultMap)) ? $resultMap : array();
            foreach ($resultMap as $i => $pair) {
                // remove any incomplete field mappings
                if (array_key_exists('prop-ref', $pair) && empty($pair['prop-ref'])) {
                    unset($resultMap[$i]);
                } else if (array_key_exists('dest-field', $pair) && empty($pair['dest-field'])) {
                    unset($resultMap[$i]);
                } else if (array_key_exists('dest-field', $pair) && !array_key_exists($pair['dest-field'], $this->Proj->metadata)) {
                    unset($resultMap[$i]);
                }
            }
            reset($resultMap);

            $payloadForLog = $instruction['payload'];
            try {
                $payload = $this->formatPayload($instruction['payload'], $contentType);
                $payloadForLog = (empty($this->token)) ? $payload : str_replace($this->token, '|||Token '.$this->tokenRef.' removed|||', $payload);
            } catch (\JsonException $je) {
                \REDCap::logEvent($this->title, 'Error parsing payload JSON string: '.$je->getMessage().PHP_EOL.$payloadForLog, '', $this->record, $this->event_id);
                return;
            } catch (\Throwable $th) {
                \REDCap::logEvent($this->title, $th->getMessage().PHP_EOL.$payloadForLog, '', $this->record, $this->event_id);
                return;
            }
        
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->destURL);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            if ($GLOBALS['is_development_server']) curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            foreach ($curlOptions as $opt) {
                curl_setopt($ch, $opt[0], $opt[1]);
            }

            switch ($method) {
                case 'POST':
					curl_setopt($ch, CURLOPT_POST, 1);
					curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    break;
                case 'PUT':
                case 'PATCH':
                case 'DELETE':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    break;
                default: // GET
                    break;
            }

            $curlHeaders[] = "Content-Type: $contentType";
            $curlHeaders[] = "Content-Length: ".strlen($payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

            // Make the call
            $response = curl_exec($ch);
            $info = curl_getinfo($ch);
            curl_close ($ch);

            $this->log('cURL info: '.json_encode($info)); // log response info useful for debugging responses
            \REDCap::logEvent($this->title, "Sent $method to {$this->destURL}:\n".$payloadForLog."\nResponse: ".$info['http_code']."\n".$response, '', $this->record, $this->event_id);
            
            if (!empty($resultField) || !empty($resultCodeField) || count($resultMap)>0) {
                if (!empty($resultField)) {
                    $this->saveValueToField($resultField, $response);
                }
                if (!empty($resultCodeField)) {
                    $this->saveValueToField($resultCodeField, $info['http_code']);
                }
                if (!empty($resultMap)) {
                    $responseArray = \json_decode($response, true);
                    if (!empty($responseArray)) {
                        foreach ($resultMap as $i => $pair) {
                            $responseValue = $this->extractResultFromResponse($responseArray, $pair['prop-ref']);
                            if ($responseValue!==null) $this->saveValueToField($pair['dest-field'], $responseValue);
                        }
                    }
                } 
            }
        }        
	}

    /**
     * pipe
     * Perform piping on a string in the current record context
     * @param string $string
     * @return string
     */
    protected function pipe($string, $contentType='') {

        try {
            $string = $this->pipeApiToken($string);
        } catch (\Throwable $th) {
            \REDCap::logEvent(self::MODULE_TITLE." external module", 'Error retrieving system token for API call: '.$th->getMessage(), '', $this->record, $this->event_id);
        }

        if ($contentType=='application/x-www-form-urlencoded') {
            // need to urlencode piped strings for x-www-form-urlencoded
            $encodedString = '';
            $kvpairs = \explode('&', $string);
            foreach ($kvpairs as $kvpair) {
                list($k, $v) = \explode('=', $kvpair, 2);

                $v = \urlencode($this->pipe($v));
                $v = str_replace('%7B%7B', '{{', str_replace('%7D%7D', '}}', $v)); // reverse encoding of %7B%7B0%7D%7D => {{0}} (placeholders)

                $encodedString .= "&$k=$v";
            }
            $pipedString = substr($encodedString, 1);

        } else if ($contentType=='application/json') {

            $pipedString = $string;
            try {
                $pipedString = $this->pipe($string);
                $pipedString = str_replace('"______"','""',$pipedString); // empty string value e.g. { "a": "______" } -> { "a": "" }
                $pipedString = preg_replace('/:\s*______/',':null',$pipedString); // empty non-string value e.g. { "b": ______ } -> { "b":null }

                // difficult to guarantee valid json :
                // - instruction payload config can be invalid if piping numeric data e.g. {"x":[someint]}
                // and/or
                // - string after piping can be invalid due to unescaped " characters
                //   e.g. { "a": "text "containing" quotes" } -> { "a": "text \"containing\" quotes" }
                // ignoring for now...
                $jsonDecodedPipedString = \json_decode($pipedString, null, 512, JSON_THROW_ON_ERROR);
                $pipedString = \json_encode($jsonDecodedPipedString);
                
            } catch (\JsonException $je) {
                \REDCap::logEvent(self::MODULE_TITLE." external module", 'Error parsing payload JSON string: '.$je->getMessage().PHP_EOL.$pipedString, '', $this->record, $this->event_id);
            }
    
        } else {
            $pipedString = \Piping::replaceVariablesInLabel(
                $string, // $label='', 
                $this->record, // $record=null, 
                $this->event_id, // $event_id=null, 
                $this->instance, // $instance=1, 
                array(), // $record_data=array(),
                true, // $replaceWithUnderlineIfMissing=true, 
                null, // $project_id=null, 
                false // $wrapValueInSpan=true
            );
        }
        return $pipedString;
    }

    /**
     * pipeApiToken
     * Substiute reference to api token in form [token-ref:xyz] with real token according to system-level module settings
     * (Helps avoid user api tokens being visible in project module settings)
     * @param string
     * @return string 
     */
    protected function pipeApiToken($string) {
        $found = false;
        $matches = array();
        $pattern = "/\[token-ref:([-\w]+)\]/";
        if (!preg_match($pattern, $string, $matches)) return $string;

        $systemTokens = $this->getSubSettings('token-management');
        foreach ($systemTokens as $i => $systemToken) {
            if (  array_key_exists(1, $matches) && $matches[1]==$systemToken['token-ref'] &&
                starts_with($this->destURL, $systemToken['token-url']) ) {
                $found = true;
                break;
            }
        }

        if (!$found) throw new \Exception('Token with reference "'.$matches[1].'" for destination URL "'.$this->destURL.'" not found in system-level token management.');
        
        if ($systemToken['token-lookup-option']==='lookup') {
            $sql = "select api_token from redcap_user_rights where project_id=? and username=? limit 1";
            $q = $this->query($sql, [$systemToken['token-project'], $systemToken['token-username']]);
            $r = db_fetch_assoc($q);
            $this->token = $this->escape($r["api_token"]);
        } else if ($systemToken['token-lookup-option']==='specify') {
            $this->token = $this->escape($systemToken['token-specified']);
        }

        if (empty($this->token)) throw new \Exception('Could not read token with reference "'.$matches[1].'" in system-level token management.');
        $this->tokenRef = $matches[1];
        return str_replace($matches[0], $this->token, $string);
    }

    /**
     * formatPayload
     * @param string
     * @return string
     */
    protected function formatPayload($rawPayload='', $contentType='application/json') {
        if ($rawPayload==='') return $rawPayload;

        $payload = $rawPayload;

        // find and replace sections to skip piping replacement and/or urlencoding e.g. filter logic expressions for REDCap record exports
        $escapePiping = array();
        $escape = preg_match_all('/({{[^}]+}})/', $payload, $escapePiping);
        if ($escape && count($escapePiping) && count($escapePiping[0])) {
            foreach($escapePiping[0] as $idx => $skip) {
                $pos = strpos($payload, $skip);
                $len = strlen($skip);
                $payload = substr_replace($payload, '{{'.$idx.'}}', $pos, $len); // not str_replace() in case multiple of same match
            }
        }

        $payload = $this->pipe($payload, $contentType);

        // reinstate skipped content minus the {{ }} delimiters
        if ($escape && count($escapePiping) && count($escapePiping[0])) {
            foreach($escapePiping[0] as $idx => $skip) {
                $payload = preg_replace("/\{\{$idx\}\}/", trim($skip, '{}'), $payload, 1); // not str_replace() in case multiple of same match
            }
        }

        return $payload;
    }

    protected function findCurlOptInt($key) {
        if (!isset($this->curlConstants)) {
            // from https://stackoverflow.com/a/59650799/2286209
            $constants = get_defined_constants(true);
            $curlOptKeys = preg_grep('/^CURLOPT_/', array_keys($constants['curl']));
            $this->curlOpts = array_intersect_key($constants['curl'], array_flip($curlOptKeys));
        }
        return (array_key_exists($key, $this->curlOpts))
            ? $this->curlOpts[$key] : false;
    }

    protected function makeContentType($instructionContentType='application/json') {
        if (empty(trim($instructionContentType))) $instructionContentType = 'application/json';
        return $instructionContentType;
    }

    protected function makeCurlHeadersArray($instructionCurlHeaders = '')
    {
        // Build a sanitized header list from the EM config, skipping hop-by-hop/problematic headers.
        if (empty(trim($instructionCurlHeaders)))
            return array();

        $headers = array();
        $lines = explode("\n", $instructionCurlHeaders);

        // Never forward these explicitly; cURL/Apache will handle them.
        $blocked = array('content-length', 'connection', 'transfer-encoding', 'expect');

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '')
                continue;

            // Piping (RedCap variables)
            $line = $this->pipe($line);

            // Normalize spaces and remove CR/LF
            $line = str_replace(array("\r", "\n"), ' ', $line);
            $line = preg_replace('/\s+/', ' ', $line);

            // Split "Name: Value"
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2)
                continue;

            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if ($name === '')
                continue;

            if (in_array(strtolower($name), $blocked, true))
                continue;

            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }


    protected function makeCurlOptionsArray($instructionCurlOptions='') {
        if (empty(trim($instructionCurlOptions))) return array();

        $optionsArray = array();
        $optionLines = explode('\n', $instructionCurlOptions);
        foreach ($optionLines as $line) {
            list($opt, $val) = explode('=', $line, 2);
            $optIntVal = $this->findCurlOptInt($opt);
            if ($optIntVal) $optionsArray[] = [$optIntVal, $this->pipe($val)];
        }
        return $optionsArray;
    }

    /**
     * makeSaveArrayElement($response, $ref)
     * Search response for an element with key matching $ref
     * Return the (first) corresponding value
     * @param string $field
     * @param string $value
     * @return string
     */
    protected function makeSaveArrayElement($field, $value) {
        $elem = array();
        $elem[$this->Proj->table_pk] = $this->record;
        if (\REDCap::isLongitudinal()) {
            $elem['redcap_event_name'] = \REDCap::getEventNames(true, false, $this->event_id);
        }
        if ($this->Proj->isRepeatingEvent($this->event_id)) {
            $elem['redcap_repeat_instrument'] = '';
            $elem['redcap_repeat_instance'] = $this->instance;

        } else if ($this->Proj->isRepeatingForm($this->event_id, $this->Proj->metadata[$field]['form_name'])) { // note this is the form of the field we're saving to, not the triggering form
            $elem['redcap_repeat_instrument'] = $this->Proj->metadata[$field]['form_name'];
            $elem['redcap_repeat_instance'] = $this->instance;
        }
        $elem[$field] = $value;
        return $elem;
    }

    /**
     * extractResultFromResponse($array, $ref)
     * Search multidimensional for an element with key matching $ref
     * Return the (last) value with the specified key
     * @param array $array
     * @param string $ref
     * @return string
     */
    protected function extractResultFromResponse($responseArray, $ref) {
        $ref = (string)$ref;
        $result= array($ref => null);
        if (is_array($responseArray)) {
            array_walk_recursive($responseArray, 
                function ($item, $key) use (&$result) {
                    if (array_key_exists($key, $result)) $result[$key] = (string)$item;
                }
            );
        }
        return $result[$ref];
    }

    protected function saveValueToField($field, $value) {
        $saveArray = array($this->makeSaveArrayElement($field, $value));
        $saveResult = \REDCap::saveData('json-array', $saveArray, 'overwrite'); // json_encode() not required for 'json-array' format
        if (empty($saveResult['errors']) ) {
            //\REDCap::logEvent($this->title, "Results saved \n".print_r($saveResult, true)."\nData:\n".print_r($saveArray, true), '', $this->record, $this->event_id);
        } else {
            \REDCap::logEvent($this->title, "Results save failed \n".print_r($saveResult, true)."\nData:\n".print_r($saveArray, true), '', $this->record, $this->event_id);
        }
    }

    /**
     * example
     * Return the result requested
     * @return string
     */
    public function example() {
        if (empty($_POST)) {
            $content = file_get_contents("php://input");
            $data = \json_decode($content, true); // not sure why $_POST is empty!
        } else {
            $data = $_POST;
        }
        \REDCap::logEvent(self::MODULE_TITLE." external module", "Test request:\n".print_r($this->escape($data), true));
        $result = '';
        if (is_array($data)) {
            $result = (array_key_exists('result', $data)) ? $data['result'] : '';
        } else if (is_object($data)) {
            $result = (isset($data->result)) ? $data->result : '';
        } else if (isset($data)) {
            $result = $data;
        }
        return \REDCap::filterHtml(htmlspecialchars_decode($result));
    }

    /**
     * redcap_module_configuration_settings
     * Triggered when the system or project configuration dialog is displayed for a given module.
     * Allows dynamically modify and return the settings that will be displayed - here include a link to the instruction summary page
     * @param string $project_id, $settings
     */
    public function redcap_module_configuration_settings($project_id, $settings) {
        if (!empty($project_id)) {
            foreach ($settings as $si => $sarray) {
                if ($sarray['key']=='summary-page') {
                    $url = $this->getUrl('summary.php',false,false);
                    $settings[$si]['name'] = str_replace('href="#"', 'href="'.$url.'"', $settings[$si]['name']);
                    break;
                }
            }
        }
        return $settings;
    }

    protected function getConfigArray() {
        if (!isset($this->configArray)) {
            $this->configArray = $this->getConfig();
        }
        return $this->configArray;
    }

    protected function getChoicesAsArray(array $settingsArray, $findSetting) {
        $return = null;
        foreach ($settingsArray as $setting => $settingAttrs) {
            if (is_array($settingAttrs) && array_key_exists('key', $settingAttrs) && $settingAttrs['key']==$findSetting) {
                if (array_key_exists('choices', $settingAttrs)) {
                    $return = array();
                    foreach ($settingAttrs['choices'] as $choice) {
                        $return[$choice['value']] = $choice['name'];
                    }
                }
                break;
            } else if (is_array($settingAttrs)) {
                $return = $this->getChoicesAsArray($settingAttrs, $findSetting);
            }
            if (is_array($return)) break;
        }
        return $return;
    }

    protected function getLabelForConfigChoice($setting, $value) {
        $label = $value;
        $choices = $this->getChoicesAsArray($this->getConfigArray(), $setting);
        if (is_array($choices) && array_key_exists($value, $choices)) {
            $label = $choices[$value];
        }
        return $label;
    }

    /**
     * summaryPage()
     * Content for summary page showing table of copy instruction configurations
     */
    public function summaryPage() {
        global $project_id;

        // make the dropdown list of version history for export
        $msm = new ModuleSettingsManager($this);
        $instructionKeys = $this->getInstructionSettingKeys();
        try {
            $history = $msm->getFilteredSettingsHistory($instructionKeys);
        } catch (\Throwable $th) {
            //$this->log('Failed to read module settings history: '.$th->getMessage());
            $history = array();
        }
        $versions = array();
        if (empty($history)) {
            $versions[0] = 'Current';
            $cc = $this->getProjectSetting('message-config');
            if (!is_null($cc)) $msm->saveCurrentSettingsToHistory(); // log current when this is the first time viewed after upgrade to module v1.6.0+
        } else {
            foreach ($history as $version) {
                $id = (empty($versions)) ? 0 : $version['log_id']; // use id=0 for current version of settings
                $lbl = substr($version['timestamp'], 0, 16).' ('.$version['username'].')';
                $versions[$id] = $lbl;
            }
        }
        $versionDropdown = \RCView::select(array('id'=>'module-version','name'=>'module-version'), $versions);

        $instructions = $this->getSubSettings('message-config');
        $columns = array(
            array('title'=>'#','tdclass'=>'text-center','getter'=>function(array $instruction){ return '<span class="module-seq"></span>'; }),
            array('title'=>'Description','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $desc = (isset($instruction['instruction-description'])) ? $instruction['instruction-description'] : ''; // old configs may be missing instruction-description
                if (is_null($desc) || trim($desc=='')) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                } else {
                    $desc = $this->escape(trim($desc));
                    $descDisplay = '<span class="m-0 text-left module-two-line-text" style="font-size:75%; max-width: 20ch;">'.str_replace("\n",' ',$desc).'</span>';
                    return '<span class="module-hidden">'.str_replace("\n",'<br>',$desc).'</span><button class="module-btn-show btn btn-xs btn-outline-primary" title="View full description">'.$descDisplay.'</button>';
                }
            }),
            array('title'=>'Enabled','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $enabledDesc = '<i class="fa-solid '.(($instruction['message-enabled']) ? 'fa-check text-success' : 'fa-times text-danger').'"></i>';
                $messageInstruction = new Instruction($instruction);
                $configErrors = $messageInstruction->getConfigErrors();
                if (count($configErrors)) {
                    $errMsg = '<ul><li>'.implode('</li><li>', $configErrors).'</li></ul>';
                    $enabledDesc .= '<span class="module-hidden">'.$this->escape($errMsg).'</span><button class="module-btn-show btn btn-xs btn-outline-danger ml-2" title="Instruction Configuration Errors"><i class="fa-solid fa-exclamation-triangle mx-2"></i></button>';
                }
                $configWarnings = $messageInstruction->getConfigWarnings();
                if (count($configWarnings)) {
                    $warnMsg = '<ul><li>'.implode('</li><li>', $configWarnings).'</li></ul>';
                    $enabledDesc .= '<span class="module-hidden">'.$this->escape($warnMsg).'</span><button class="module-btn-show btn btn-xs btn-outline-warning ml-2" title="Instruction Configuration Warnings"><i class="fa-solid fa-exclamation-triangle mx-2"></i></button>';
                }
                return $enabledDesc;
            }),
            array('title'=>'Trigger Form(s)','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $formList = array();
                foreach ($instruction['trigger-form'] as $form) {
                    $formList[] = "<span class='badge bg-primary'>$form</span>";
                }
                return implode('<br>', $formList); 
            }),
            array('title'=>'Trigger Logic','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $logic = $instruction['trigger-logic'];
                if (is_null($logic) || trim($logic=='')) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                } else {
                    return '<span class="module-hidden"><pre>'.\htmlspecialchars($logic,ENT_QUOTES).'</pre></span><button class="module-btn-show btn btn-xs btn-outline-primary" title="View Trigger Logic"><i class="fa-solid fa-bolt mx-2"></i></button>';
                }
            }),
            array('title'=>'Destination URL','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $dest_url = \htmlspecialchars($instruction['dest-url'], ENT_QUOTES);
                if (empty($dest_url)) {
                    return '<i class="fa-solid fa-minus text-danger"></i>';
                } else {
                    list($path, $query) = explode('?', $dest_url);
                    $query = (is_null($query) || $query=='') ? '' : '<div class="text-left pl-2">?'.str_replace('&','<br> &',$query).'</div>';
                    return "<span class='badge bg-secondary'>$path $query</span>";
                }
            }),
            array('title'=>'HTTP Method','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                if (empty($instruction['http-method'])) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                } else {
                    $val = $instruction['http-method'];
                    return '<span class="badge bg-primary">'.$this->getLabelForConfigChoice('http-method', $val).'</span>';
                }
            }),
            array('title'=>'Message Payload','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $payload = (isset($instruction['payload'])) ? $instruction['payload'] : ''; // old configs may be missing instruction-description
                if (is_null($payload) || trim($payload=='')) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                } else {
                    $payload = $this->escape(trim($payload));
                    $payloadDisplay = '<span class="m-0 text-left module-two-line-text" style="font-size:75%; max-width: 20ch;">'.str_replace("\n",' ',$payload).'</span>';

                    $payloadDialogText = '';
                    $lines = explode("\n", $payload);
                    foreach ($lines as $l => $line) {
                        $payloadDialogText .= substr("  ".($l+1).". ", -5);
                        $chunks = str_split(htmlspecialchars_decode($line), 100);
                        $payloadDialogText .= htmlspecialchars(implode('<br>   &rdsh; ', $chunks), ENT_QUOTES).'<br>';
                    }

                    return '<span class="module-hidden"><pre>'.rtrim($payloadDialogText).'</pre></span><button class="module-btn-show btn btn-xs btn-outline-primary" title="View full payload">'.$payloadDisplay.'</button>';
                }
            }),
            array('title'=>'Content Type','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $val = htmlspecialchars($instruction['content-type'], ENT_QUOTES);
                if (empty($val)) {
                    $val = 'application/json (default)';
                }
                return "<span class='badge bg-primary'>$val</span>";
            }),
            array('title'=>'Additional Headers','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $val = $instruction['curl-headers'];
                if (empty($val)) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                }
                $outTxt = '';
                $vals = explode('\n', $val);
                foreach ($vals as $hdr) {
                    $outTxt .= "<div class='badge bg-primary'>".\htmlspecialchars($val, ENT_QUOTES)."</div>";
                }
                return $outTxt;
            }),
            array('title'=>'cURL Options','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $val = $instruction['curl-options'];
                if (empty($val)) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                }
                $outTxt = '';
                $vals = explode('\n', $val);
                foreach ($vals as $hdr) {
                    $outTxt .= "<div class='badge bg-primary'>".\htmlspecialchars($val, ENT_QUOTES)."</div>";
                }
                return $outTxt;
            }),
            array('title'=>'Save Response To','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $val = \htmlspecialchars($instruction['result-field'], ENT_QUOTES);
                if (empty($val)) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                }
                return "<span class='badge bg-primary'>$val</span>";
            }),
            array('title'=>'Save Response Code To','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $val = \htmlspecialchars($instruction['result-http-code'], ENT_QUOTES);
                if (empty($val)) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                }
                return "<span class='badge bg-primary'>$val</span>";
            }),
            array('title'=>'Response Data Mapping','tdclass'=>'module-field-map-col','getter'=>function(array $instruction){ 
                $return = '';
                $fieldList = array();
                if (empty($instruction['map-to-field']) || (count($instruction['map-to-field'])===1 && is_null($instruction['map-to-field'][0]['prop-ref']))) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                }
                foreach ($instruction['map-to-field'] as $pair) {
                    if (is_array($pair)) {
                        $p = "<span class='badge bg-secondary'>".\htmlspecialchars($pair['prop-ref'], ENT_QUOTES)."</span>";
                        $d = "<span class='badge bg-primary'>".\htmlspecialchars($pair['dest-field'], ENT_QUOTES)."</span>";
                        $fieldList[] = "<div class='nowrap my-1' style='display:flex;align-items:center;gap:2px;'>$p<i class='fa-solid fa-arrow-right-long text-muted'></i>$d</div>";
                    }
                }
                if (count($fieldList) > self::DISPLAY_MAX_FIELD_MAP) {
                    $firstN = array_slice($fieldList, 0, self::DISPLAY_MAX_FIELD_MAP);
                    $return = '<span class="module-hidden"><div class="module-field-map-dialog-content">';
                    for ($i=0; $i < count($fieldList); $i++) { 
                        $return .= '<div class="my-1" style="display:flex;align-items:center;"><span class="module-field-map-index">'.($i+1).'.</span>'.$fieldList[$i].'</div>';
                    }
                    $return .= '</div></span>';
                    $return .= implode('', $firstN);
                    $return .= '<button type="button" class="module-btn-show btn btn-xs btn-outline-success mt-1" title="View All Field Mappings"><i class="fa-solid fa-eye mr-1"></i>+'.(count($fieldList)-self::DISPLAY_MAX_FIELD_MAP).'</button>';
                } else {
                    $return = implode('', $fieldList);
                }
                return $return; 
            })
        );

        $lastExportType = \UIState::getUIStateValue($project_id, 'module-export', 'repeat-separator') ?? 'line';
        switch ($lastExportType) {
            case 'space': $spaceActive = 'active" aria-current="true"'; $lineActive  = $pipeActive  = ''; break;
            case 'pipe':  $pipeActive  = 'active" aria-current="true"'; $lineActive  = $spaceActive = ''; break;
            default:      $lineActive  = 'active" aria-current="true"'; $spaceActive = $pipeActive  = ''; break;
        }
        ?>
        <div class="projhdr"><i class="fa-solid fa-file-export mr-1"></i>REDCap REST: Summary of Instructions</div>
        <div style="max-width: 1000px;">
            <div id="module-intro-info">
                <h6>Table of Instructions</h6>
                <p>The table below shows the current configuration settings of copy instructions set up in this project.</p>
                <p>You may download or upload instructions in CSV format using the "Export" and "Import" buttons on this page. A new version is included in the dropdown list each time the module settings are saved or altered settings or imported.</p>
            </div>
            <div id="module-import-export">
                <h6>Export & Import <button id="module-btn-import-info" class="btn btn-xs btn-default fs18" type="button"><i class="fa-solid fa-circle-info"></i></button></h6>
                <div id="module-import-export-controls">
                    <?= $versionDropdown ?>
                    <div class="btn-group">
                        <button name="module-btn-export-<?= $lastExportType ?>" class="module-btn-export btn btn-xs btn-primaryrc" type="button">
                            <i class="fa-solid fa-file-export"></i> <?= \RCView::tt('global_71') ?>
                        </button>
                        <button type="button" class="btn btn-xs btn-primaryrc dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="visually-hidden">Toggle Dropdown</span>
                        </button>
                        <ul class="dropdown-menu">
                            <li><span class="dropdown-item-text">Repeating value separator</span></li>
                            <li><button name="module-btn-export-line"  class="module-btn-export dropdown-item <?= $lineActive  ?>" type="button"><i class="fa-solid fa-file-export"></i> <?= \RCView::tt('global_71') ?> (line)</button></li>
                            <li><button name="module-btn-export-space" class="module-btn-export dropdown-item <?= $spaceActive ?>" type="button"><i class="fa-solid fa-file-export"></i> <?= \RCView::tt('global_71') ?> (space)</button></li>
                            <li><button name="module-btn-export-pipe"  class="module-btn-export dropdown-item <?= $pipeActive  ?>" type="button"><i class="fa-solid fa-file-export"></i> <?= \RCView::tt('global_71') ?> (pipe)</button></li>
                        </ul>
                    </div>
                    <button id="module-btn-import" class="btn btn-xs btn-success mx-2"><i class="fa-solid fa-file-import"></i> <?= \RCView::tt('global_72') ?></button> 
                    <button id="module-btn-edit" class="btn btn-xs btn-dark"><i class="fa-solid fa-pencil"></i> <?= \RCView::tt('econsent_43') //"Edit settings"?></button> 
                </div>
                
            </div>
        </div>
        <div id="module-summary-table-container">
        <table id="module-summary-table"><thead><tr>
        <?php 

        foreach ($columns as $col) {
            $class = (empty($col['tdclass'])) ? '' : ' class="'.$this->escape($col['tdclass']).'"';
            echo "<th$class>".\REDCap::filterHtml($col['title']).'</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($instructions as $instruction) {
            echo '<tr>';
            foreach ($columns as $col) {
                $class = (empty($col['tdclass'])) ? '' : ' class="'.$col['tdclass'].'"';
                $contentGetterFunction = $col['getter'];
                $cellContent = call_user_func($contentGetterFunction, $instruction);
                echo "<td $class>".\REDCap::filterHtml($cellContent).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        $url = $this->getUrl('export_import.php', false, false);

        $this->initializeJavascriptModuleObject();
        ?>
        <div id="module-import-dialog-text" class="module-hidden">You may import REDCap REST instructions using a CSV file formatted congruently with how the instructions are exported from this page.</div>
        <div id="module-import-file-container" class="module-hidden"></div>
        <div id="module-import-info" class="module-hidden">
            <p class="mt-0">Instructions may be exported and imported. Use the CSV delimiter character from your REDCap profile when importing.</p>
            <p>Select the timestamp of the settings version to export. The first entry (default) is the latest version; the current settings.</p>
            <p>Import files require the following <strong>fourteen columns</strong> to be present (although you may alter the title text). Values are required for some columns and option for others, as indicated:</p>
            <ol>
                <li><strong>Description (optional)</strong></li>
                <li><strong>Enabled</strong> (required): Integer from the following list:
                    <ol start="0">
                        <li>Disabled</li>
                        <li>Enabled</li>
                    </ol>
                </li>
                <li><strong>Trigger form(s)</strong> (optional): A separated<sup>*</sup> list of form names.</li>
                <li><strong>Trigger condition</strong> (optional): A REDCap logic expression.</li>
                <li><strong>Destination URL</strong> (required): URL of endpoint where message will be sent.</li>
                <li><strong>HTTP method</strong> (required): The desired HTTP verb: <code>POST</code> <code>GET</code> <code>PUT</code> <code>PATCH</code> <code>DELETE</code>.</li>
                <li><strong>Payload</strong> (optional): Payload form e.g. as JSON (piping supported).</li>
                <li><strong>Content type</strong> (optional): Content type for request, e.g. application/json (default), application/x-www-form-urlencoded.</li>
                <li><strong>Additional headers</strong> (optional): Additional HTTP header content for request. One per line.</li>
                <li><strong>cURL options</strong> (optional): cURL options for request. One per line.</li>
                <li><strong>Response save field</strong> (optional): field in which to save the full request response.</li>
                <li><strong>Response code save field</strong> (optional): field in which to save the HTTP code for the response.</li>
                <li><strong>Field mapping - response property</strong> (repeating<sup>+</sup> - required): A separated<sup>*</sup> list of property names within the response.</li>
                <li><strong>Field mapping - save to field</strong> (repeating<sup>+</sup> - required): A separated<sup>*</sup> list of fields response values will be saved to.</li>
            </ol>
            <p><sup>*</sup> "Separated list": repeating values can separated with any non-word character e.g. space, pipe, line-break. The import process will attempt to detect the separator character automatically.</p>
            <p class="mb-0"><sup>+</sup> "Repeating": each of these mapping columns must have the same number of list entries.</p>
        </div>
        <style type="text/css">
            h6 { 
                font-weight: bold; 
            }
            #module-summary-table-container { max-width: 800px; }
            #module-intro-info { margin: 1rem 0 1rem 0; }
            #module-import-export { 
                max-width: 500px;
                margin: 1rem 0 1rem 0;
            }
            #module-version { font-size: 85%; }
            .module-hidden { display: none; }
            .module-field-map-dialog-content { max-height: 500px; overflow-y: scroll; }
            .module-field-map-index { display: inline-block; width: 35px; }
            #module-summary-table .badge { font-weight: normal; padding: 3px 5px; }
            .module-two-line-text {
                overflow: hidden;
                text-overflow: ellipsis;
                display: -webkit-box;
                line-clamp: 2;
                box-orient: vertical;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                height: 2.1em;
                line-height: 1em;
            }
        </style>
        <script type="text/javascript">
            let module = <?=$this->getJavascriptModuleObjectName()?>;
            module.exportUrl = '<?=$url?>&action=export';
            module.importUrl = '<?=$url?>&action=import';
            module.show = function() {
                let title = $(this).attr('title');
                let content = '<div style="overflow-wrap: break-word;">'+$(this).siblings('span:first').html()+'</div>';
                simpleDialog(content, title, null, 810);
            };

            module.exportOk = function() {
                window.location.href = module.exportUrl+'&sep='+module.export_sep+'&ver='+module.export_ver;
            };

            module.export = function() {
                module.export_sep='line';
                switch ($(this).attr('name')) {
                    case 'module-btn-export-space': module.export_sep='space'; break;
                    case 'module-btn-export-pipe': module.export_sep='pipe'; break;
                    default: break;
                }
                $('button.module-btn-export').removeClass('active');
                $('button.module-btn-export').removeAttr('aria-current');
                $(this).addClass('active');
                $(this).attr('aria-current', 'true');

                module.export_ver = $('select[name=module-version]').val();
                let verLbl = $('select[name=module-version] option:selected').text();
                let confText = 'Export copy instructions:<ul><li>Version: '+verLbl+'</li><li>Repeat value separator: '+module.export_sep+'</li></ul>';
                simpleDialog(confText,'REDCap REST: Export CSV','module-export-dialog',350,null,'<?= \RCView::tt('global_53', false) ?>',
                    module.exportOk,
                    '<?= \RCView::tt('design_401', false) ?>'
                );
            };

            // region import code inspired by method in FormDisplayLogicSetup.js
            module.import_elements = {};
            module.import_container = document.querySelector('#module-import-file-container');

            module.import = function() {
                simpleDialog($('#module-import-dialog-text').html(),'REDCap REST: Import CSV','module-import-dialog',650,null,'<?= \RCView::tt('global_53', false) ?>',
                    module.importFile,'<?= \RCView::tt('asi_006', false) ?>');
                fitDialog($('#module-import-dialog'));
                $('#module-import-dialog').dialog().next().find('button:last').addClass('ui-priority-primary').prepend('<img src="'+app_path_images+'xls.gif"> ');
            };

            module.submitImportFile = function() {
                var data = new FormData(module.import_elements.uploadForm);

                // can't use module.ajax() with file upload because payload gets put through JSON.stringify()
                // module.ajax('< ?=static::IMPORT_ACTION?>', [data]).then(function(response) {

                module.sendAjaxRequest('POST', data, { processData: false, contentType: false, })
                    .done(function(response){
                        if (response && response.result==1) {
                            simpleDialog('The CSV file was successfully imported. This page will now reload to reflect the changes.', 'REDCap REST: Instruction Import Successful', null, 700, 'window.location.reload();');
                        } else if (response && response.result==0) {
                            const errorList = '<ul>' + response.errors.map(item => `<li>${item}</li>`).join('') + '</ul>'
                            simpleDialog('<p class="my-0">The CSV file could not be imported. The following errors were encountered:</p>'+errorList, 'REDCap REST: Instruction Import Failed', null, 700);
                        } else {
                            console.log('response:');
                            console.log(response);
                            simpleDialog(woops);
                        }
                    }).fail(function(response){
                        console.log(response);
                        if (response.hasOwnProperty('message') && response.message == 'JSON.parse: unexpected character at line 1 column 1 of the JSON data') {
                            simpleDialog('File upload could not be processed. Refresh this page and retry.'); // usually csrf expired
                        } else {
                            simpleDialog(woops);
                        }
                });
            };
            
            module.sendAjaxRequest = function(method, data, options = {}) {
                data.redcap_csrf_token = get_csrf_token(); // add the csrf token
                var dfd = $.Deferred();
                var base_params = {
                    url: module.importUrl,
                    type: method,
                    data: data,
                    dataType: 'json',
                };
                var params = $.extend(base_params, options);
                $.ajax(params)
                    .done( function( response, textStatus, jqXHR ) {
                        dfd.resolve(response);
                    }).fail( function( jqXHR, textStatus, errorThrown ) {
                    var response = {
                        status: "error",
                        //message: jqXHR.message
                        message: errorThrown
                    };
                    dfd.reject(response);
                });
                return dfd;
            }

            module.handleFileSelected = function(element) {
                // upload the selected file
                if(!!!element) return;
                var self = this; // to maintain the scope inside the event listeners
                // submit the upload form as a file is selected
                element.addEventListener('change', function(e) {
                    e.preventDefault();
                    self.submitImportFile();
                });
            };

            module.getFileInput = function() {
                // create a file input element and register it's event handler
                var fileInput = document.createElement('input');
                fileInput.setAttribute('type', 'file');
                fileInput.setAttribute('name', 'files');
                module.handleFileSelected(fileInput);
                return fileInput;
            };

            module.createUploadForm = function() {
                // create the upload form and add it to the container
                var uploadForm = createUploadForm('module-import-form', module.getFileInput()); // function createUploadForm() in base.js
                var action_input = document.createElement('input');
                action_input.setAttribute('type', 'hidden');
                action_input.setAttribute('name', 'ajax-action');
                action_input.setAttribute('value', '<?= static::IMPORT_ACTION ?>');
                uploadForm.appendChild(action_input);
                module.import_elements.uploadForm = uploadForm;
                module.import_container.appendChild(uploadForm);
            };

            module.importFile = function() {
                // open the "select file" dialog box
                if(!module.import_elements.uploadForm) module.createUploadForm();
                var fileInput = module.import_elements.uploadForm.querySelector('input[type="file"]');
                fileInput.click();
            };
            // end region import code

            module.importInfo = function() {
                simpleDialog(
                    $('#module-import-info').html(),
                    'REDCap REST: Instruction Import Information',
                    'module-import-info-dialog',850
                );
            };

            module.editModuleSettings = function() {
                window.location.href = app_path_webroot + 'ExternalModules/manager/project.php?pid='+pid+'&redcap_rest_config=1';
            };

            module.init = function() {
                $('span.module-seq').each(function(i,e){ $(e).html(i+1) });
                $('button.module-btn-show').on('click', module.show);
                $('#module-summary-table').DataTable({ paging: false });
                $('button.module-btn-export').on('click', module.export);
                $('#module-btn-import').on('click', module.import);
                $('#module-btn-import-info').on('click', module.importInfo);
                $('#module-btn-edit').on('click', module.editModuleSettings);
            };
            $(document).ready(function(){
                module.init();
            });
        </script>
        <?php
    }

    /**
     * redcap_every_page_top()
     * EM Manager page in project: 
     * - include a link to the summary page
     * - auto-open config settings when entering from summary page button click
     */
    public function redcap_every_page_top($project_id) {
        if (!defined('PAGE')) return;
        if (empty($project_id) || PAGE!=='manager/project.php') return;

        $summaryPageUrl = $this->getUrl('summary.php',false,false);
        ?>
        <script type="text/javascript">
            /*REDCap REST summary page link*/
            $(document).ready(function(){
                let url = '<?=$summaryPageUrl?>';
                let loc = $('tr[data-module="redcap_rest"] div.external-modules-description');
                $(loc).append('<div class="mt-1"><a href="'+url+'"><i class="fa-solid fa-list-ol" style="margin-right: 5px;"></i> View Summary of Instructions</a>')
            });
        </script>
        <?php
        if (isset($_GET['redcap_rest_config'])) {
            ?>
            <script type="text/javascript">
                /*REDCap REST auto-config*/
                $(window).on('load', function() {
                    history.pushState({}, null, location.href.split("&redcap_rest_config")[0]);
                    setTimeout(function() {
                        $('tr[data-module="redcap_rest"] button.external-modules-configure-button').trigger('click');
                    }, 1000);
                });
            </script>
            <?php
        }
    }

    public function userHasPermission(): bool {
        global $user_rights;
        $user_rights = (isset($user_rights) && is_array($user_rights)) ? $user_rights : array();
        $modulePermission = $this->getSystemSetting('config-require-user-permission');
        if ($modulePermission) {
            $userHasPermission = (is_array($user_rights['external_module_config']) && in_array('redcap_rest', $user_rights['external_module_config']) || (defined('SUPER_USER') && SUPER_USER && !\UserRights::isImpersonatingUser()));
        } else {
            $userHasPermission = ($user_rights['design'] || (defined('SUPER_USER') && SUPER_USER && !\UserRights::isImpersonatingUser()));
        }
        return $userHasPermission;
    }

    public function export(?string $separatorOption=null, ?int $version=null): void {
        global $project_id;
        $instructions = array();

        switch ($separatorOption) {
            case 'space': $repeatValueSeparator = ' '; break;
            case 'pipe': $repeatValueSeparator = '|'; break;
            default: $separatorOption = 'line'; $repeatValueSeparator = PHP_EOL; break;
        }
        \UIState::saveUIStateValue($project_id, 'redcap-rest-export', 'repeat-separator', $separatorOption);
        
        $delimiter = \User::getCsvDelimiter();
        if ($delimiter == 'tab' || $delimiter == 'TAB') $delimiter = "\t";

        if ($version === 0) {
            // just read current settings
            $instructions = $this->getSubSettings('message-config');
        } else {
            // get settings from logged history
            $msm = new ModuleSettingsManager($this);
            $loggedSettings = $msm->getSettingsHistoryByLogId($version);
            $moduleSettings = $loggedSettings[0]['module_settings'];
            $settingConfig = $this->getSettingConfig('message-config');
            $instructions = $msm->getSubSettingsFromSettingsArray($moduleSettings, $settingConfig);
        }           

        // make export file contents 
        $filename = "REDCap_REST_Export_pid".$project_id."_".date("Y-m-d_Hi");
        $titles = array('Description','Enabled','Trigger form(s)','Trigger condition','Destination URL','HTTP Method','Message Payload','Content Type','Additional Headers','cURL Options','Save Response To Field','Save Response Code To Field','Data Mapping - Property Name','Data Mapping - Save to Field');

        $fp = fopen(APP_PATH_TEMP.$filename, 'w');
        fputcsv($fp, $titles, $delimiter, '"', '');

        foreach ($instructions as $instruction) {
            $instructionRow = array_fill(0, 12, null);
            foreach ($instruction as $key => $value) {
                switch ($key) {
                    case 'instruction-description':
                        $instructionRow[0] = $value ?? '';
                        break;
                    case 'message-enabled':
                        $instructionRow[1] = ($value==1) ? 1 : 0;
                        break;
                    case 'trigger-form':
                        $instructionRow[2] = implode($repeatValueSeparator, $value); // array of form names
                        break;
                    case 'trigger-logic':
                        $instructionRow[3] = $value ?? '';
                        break;
                    case 'dest-url':
                        $instructionRow[4] = $value ?? '';
                        break;
                    case 'http-method':
                        $instructionRow[5] = $value ?? '';
                        break;
                    case 'payload':
                        $instructionRow[6] = $value ?? '';
                        break;
                    case 'content-type':
                        $instructionRow[7] = $value ?? '';
                        break;
                    case 'curl-headers':
                        $instructionRow[8] = $value ?? '';
                        break;
                    case 'curl-options':
                        $instructionRow[9] = $value ?? '';
                        break;
                    case 'result-field':
                        $instructionRow[10] = $value ?? '';
                        break;
                    case 'result-http-code':
                        $instructionRow[11] = $value ?? '';
                        break;
                    case 'map-to-field':
                        $pnArray = $dfArray = array();
                        foreach ($value as $cfValue) {
                            $pnArray[] = $cfValue['prop-ref'] ?? '';
                            $dfArray[] = $cfValue['dest-field'] ?? '';
                        }
                        $instructionRow[12] = implode($repeatValueSeparator, $pnArray);
                        $instructionRow[13] = implode($repeatValueSeparator, $dfArray);
                        break;
                    default: break;
                }
            }
            fputcsv($fp, $instructionRow, $delimiter, '"', '');
        }
    	fclose($fp);

        // Output to file
        header('Pragma: anytextexeptno-cache', true);
        header("Content-type: application/csv");
        header("Content-Disposition: attachment; filename=$filename.csv");

        $fp = fopen(APP_PATH_TEMP.$filename, 'rb');
        print \addBOMtoUTF8(fread($fp, filesize(APP_PATH_TEMP.$filename)));

        // Close file and delete it from temp directory
        fclose($fp);
        unlink(APP_PATH_TEMP.$filename);

        $this->log('Export instruction list', array( 'user' => USERID ));
    }

    /**
     * import()
     * Nb. can't use redcap_module_ajax() for file submit
     * @param string ajax-action
     * @return array
     */
    public function import($action): array {
        global $project_id;
        $configArray = $this->getConfigArray();
        if (!is_array($configArray['auth-ajax-actions']) || !in_array($action, $configArray['auth-ajax-actions'])) return array('Invalid action submitted');
        if (empty($project_id)) return array('Could not detect project');

        $files = \FileManager::getUploadedFiles();

        if(count($files)>1) return array('Multiple files uploaded');
        if (count($files)==0) return array('No file uploaded');

        $file = array_pop($files);

        if (!ends_with($file['name'], '.csv') || !in_array($file['type'], array('text/csv', 'text/plain','application/vnd.ms-excel','application/csv'))) {
            return array('File "'.$file['name'].'" is not a CSV file');
        }

        $csvArray = \FileManager::readCSV($file['tmp_name'], 0, \User::getCsvDelimiter(), '"', '"');

        if (!is_array($csvArray)) return array('Could not read CSV file rows');
        if (count($csvArray) === 0) return array('File contains no rows');
        if (count($csvArray) === 1) return array('File contains header row but no copy instructions');
        
        $currentSettings = $this->getProjectSettings();

        $errors = array();
        $instructions = array();
        $instructionSettings = $this->getInstructionSettingKeys();
        $instructionSettings = array_map(function() { return array(); }, array_flip($instructionSettings));
        foreach ($csvArray as $rowIndex => $row) {
            $importColumns = array(
                'instruction-description' => null,
                'message-enabled' => null,
                'trigger-form' => null,
                'trigger-logic' => null,
                'dest-url' => null,
                'http-method' => null,
                'payload' => null,
                'content-type' => null,
                'curl-headers' => null,
                'curl-options' => null,
                'result-field' => null,
                'result-http-code' => null,
                'map-to-field-prop-ref' => null,
                'map-to-field-dest-field' => null
            );
            
            if (count($row) < count($importColumns)) {
                $errors[] = "File row $rowIndex: expected ".count($importColumns)." values, found only ".count($row);
                continue;
            }
            if ($rowIndex===0) continue; // don't need to validate column heading text
            
            $importColumnsKeys = array_keys($importColumns);

            for ($i=0; $i < count($importColumns); $i++) { 
                $importColumns[$importColumnsKeys[$i]] = $row[$i];
            }

            // prepare and validate repeating settings: trigger form(s), source/destination field pairs and "only-if-empty"
            $importColumns['trigger-form'] = $this->makeRepeatingSetting($importColumns['trigger-form'] ?? '');
            $importColumns['map-to-field-prop-ref'] = $this->makeRepeatingSetting($importColumns['map-to-field-prop-ref'] ?? '');
            $importColumns['map-to-field-dest-field'] = $this->makeRepeatingSetting($importColumns['map-to-field-dest-field'] ?? '');
            
            $nProp = count($importColumns['map-to-field-prop-ref']);
            $nDest = count($importColumns['map-to-field-dest-field']);

            if ($nProp !== $nDest) {
                $errors[] = "File row $rowIndex: count of destination fields ($nDest) does not match count of property names ($nProp) ";
            }

            $mapFields = array();
            foreach ($importColumns['map-to-field-prop-ref'] as $idx => $value) {
                $mapFields[] = array(
                    'prop-ref' => $value,
                    'dest-field' => $importColumns['map-to-field-dest-field'][$idx]
                );
            }
            $importColumns['map-to-field'] = $mapFields;
            unset($importColumns['map-to-field-prop-ref']);
            unset($importColumns['map-to-field-dest-field']);

            try {
                $instructions[] = $instruction = new Instruction($importColumns, $rowIndex+1);

                foreach ($instruction->getConfigErrors() as $ce) {
                    $errors[] = "File row $rowIndex: ".$ce;
                }
            } catch (\Throwable $th) {
                $errors[] = "File row $rowIndex: ".$th->getMessage();
            }
        }

        if (count($errors)) return $errors;

        // merge uploaded map-fields instructions into project settings, check for changes, then save
        $newSettings = $currentSettings; // php arrays copy by val not ref
        $newSettings['message-config'] = array_fill(0, $rowIndex, 'true'); // message-config has one element per instruction
        foreach ($instructions as $idx => $instruction) {
            $instructionSettings = $instruction->getAsModuleSettings();
            foreach ($instructionSettings as $key => $value) {
                $newSettings[$key][$idx] = $value; // e.g. $newSettings['instruction-description'][0] = 'this is the desc for the first instruction'
            }
        }

            $checkKeys = array('instruction-description','message-enabled','trigger-form','trigger-logic','dest-url','http-method','payload','content-type','curl-headers','curl-options','result-field','result-http-code','prop-ref','dest-field');
        if (
            ModuleSettingsManager::are_equal(
                ModuleSettingsManager::keep_keys($newSettings, $checkKeys), 
                ModuleSettingsManager::keep_keys($currentSettings, $checkKeys)
            ) ) 
        {
            return array('No changes to copy instructions detected');
        }

        try {
            $msm = new ModuleSettingsManager($this);
            $msm->saveSettingsToHistory($newSettings);
        } catch (\Throwable $th) {
            $errors[] = 'Could not save results: '.$th->getMessage();
        }

        return $errors;
    }

    /**
     * makeRepeatingSetting()
     * @param string setting value as string 
     * @return array setting value separated into array by line, space, or pipe, whichever produces most elements
     */
    protected function makeRepeatingSetting(string $settingString): array {
        $separators = array(PHP_EOL, ' ', '|');
        $settingArray = array();
        foreach ($separators as $sep) {
            $split = explode($sep, trim($settingString));
            if (count($split) > count($settingArray)) $settingArray = $split;
        }
        return $settingArray;
    }

    protected function getInstructionSettingKeys(): array {
        $keys = array();
        $config = $this->getConfig();
        foreach ($config as $key => $settings) {
            if ($key !== 'project-settings') continue;
            foreach ($settings as $setting) {
                if ($setting['key'] !== 'message-config') continue;
                    foreach ($setting['sub_settings'] as $ss) {
                        if ($ss['type'] !== 'descriptive') $keys[] = $ss['key'];
                    }
                break;
            }
            break;
        }
        return $keys;
    }

    /**
     * redcap_module_save_configuration()
     * Triggered after a module configuration is saved.
     * Capture history when instructions updated.
     */
    public function redcap_module_save_configuration($project_id) {
        if (empty($project_id)) return;
        $msm = new ModuleSettingsManager($this);
        $msm->saveCurrentSettingsToHistory();
    }
}