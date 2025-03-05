<?php
/**
 * REDCap External Module: ERDCap REST
 * Send API calls when saving particular instruments when a trigger condition is met.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\REDCapREST;

use ExternalModules\AbstractExternalModule;

class REDCapREST extends AbstractExternalModule {
    const MODULE_TITLE = "REDCap REST";
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
    
	function redcap_save_record($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
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
                $encodedString .= "&$k=".\urlencode($this->pipe($v));
            }
            $pipedString = substr($encodedString, 1);

        } else if ($contentType=='application/json') {

            try {
                $pipedString = $this->pipe($string);
                $pipedString = str_replace('"______"','""',$pipedString); // empty string value e.g. { "a": "______" } -> { "a": "" }
                $pipedString = preg_replace('/:\s*______/',':null',$pipedString); // empty non-string value e.g. { "b": ______ } -> { "b":null }

                // difficult to guarantee valid json :
                // - instruction payload config can be invalid if piping numberic data e.g. {"x":[someint]}
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
        $payload = $this->pipe($rawPayload, $contentType);
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
    
    protected function makeCurlHeadersArray($instructionCurlHeaders='') {
        if (empty(trim($instructionCurlHeaders))) return array();
        $headers = array();
        $lines = explode('\n', $instructionCurlHeaders);
        foreach ($lines as $line) {
            $headers[] = $this->pipe($line);
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
//            \REDCap::logEvent($this->title, "Results saved \n".print_r($saveResult, true)."\nData:\n".print_r($saveArray, true), '', $this->record, $this->event_id);
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
}