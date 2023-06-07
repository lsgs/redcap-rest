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
    protected $record;
    protected $event_id;
    protected $instrument;
    protected $instance;
    protected $curlOpts;
    
	function redcap_save_record($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
        global $Proj;
        $title = self::MODULE_TITLE." external module";
		$settings = $this->getSubSettings('message-config');

        foreach($settings as $i => $instruction) {
            if (!$instruction['message-enabled']) continue; 
            if (array_search($instrument, $instruction['trigger-form'])===false) continue;
            if (!empty($instruction['trigger-logic']) && true!==\REDCap::evaluateLogic($instruction['trigger-logic'], $project_id, $record, $event_id, $repeat_instance)) continue;
            
            $this->record = $record;
            $this->event_id = $event_id;
            $this->instrument = $instrument;
            $this->instance = $repeat_instance;

            $destURL = $this->pipe($instruction['dest-url']);
            $method = $instruction['http-method'];
            $contentType = $this->makeContentType($instruction['content-type']);
            $curlHeaders = $this->makeCurlHeadersArray($instruction['curl-headers']);
            $curlOptions = $this->makeCurlOptionsArray($instruction['curl-options']);
            $resultField = $instruction['result-field'];

            $resultMap = $instruction['map-to-field'];
            foreach ($resultMap as $i => $pair) {
                // remove any incomplete field mappings
                if (array_key_exists('prop-ref', $pair) && empty($pair['prop-ref'])) {
                    unset($resultMap[$i]);
                } else if (array_key_exists('dest-field', $pair) && empty($pair['dest-field'])) {
                    unset($resultMap[$i]);
                } else if (array_key_exists('dest-field', $pair) && !array_key_exists($pair['dest-field'], $Proj->metadata)) {
                    unset($resultMap[$i]);
                }
            }
            reset($resultMap);

            try {
                $payload = $this->formatPayload($instruction['payload'], $contentType);
            } catch (\JsonException $je) {
                \REDCap::logEvent($title, 'Error parsing payload JSON string: '.$je->getMessage().PHP_EOL.$payload, '', $this->record, $this->event_id);
                return;
            } catch (\Throwable $th) {
                \REDCap::logEvent($title, $th->getMessage().PHP_EOL.$payload, '', $this->record, $this->event_id);
                return;
            }
        
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $destURL);
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

            \REDCap::logEvent($title, "Sent $method to $destURL:\n".$payload."\nResponse: ".$info['http_code']."\n".$response, '', $this->record, $this->event_id);
            
            if ($info['http_code'] >= 200 && $info['http_code'] <= 299 && (!empty($resultField) || count($resultMap)>0)) {
                global $Proj;
                $saveArray = array();
                $saveArray[$Proj->table_pk] = $this->record;
                if (\REDCap::isLongitudinal()) $saveValue['redcap_event_name'] = \REDCap::getEventNames(true, false, $this->event_id);
                if ($Proj->isRepeatingEvent($event_id)) {
                    $saveArray['redcap_repeat_instrument'] = '';
                    $saveArray['redcap_repeat_instance'] = $this->instance;
                } else if ($Proj->isRepeatingForm($event_id, $instrument)) {
                    $saveArray['redcap_repeat_instrument'] = $this->instrument;
                    $saveArray['redcap_repeat_instance'] = $this->instance;
                }
                if (!empty($resultField)) {
                    $saveArray[$resultField] = $response;
                }
                if (!empty($resultMap)) {
                    $responseArray = \json_decode($response, true);
                    if (!empty($responseArray)) {
                        foreach ($resultMap as $i => $pair) {
                            $saveArray[$pair['dest-field']] = $this->extractResultFromResponse($responseArray, $pair['prop-ref']);
                        }
                    }
                } 

                $saveResult = \REDCap::saveData('json-array', [$saveArray], 'overwrite'); // json_encode() not required for 'json-array' format

                if (!empty($saveResult['errors']) ) {
                    \REDCap::logEvent($title, "Save to field $resultField failed \n".print_r($saveResult['errors'], true)."\nData:\n".print_r($saveArray, true), '', $record, $event_id);
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
     * extractResultFromResponse($response, $ref)
     * Search response for an element with key matching $ref
     * Return the (first) corresponding value
     * @param array $repsonseArray
     * @param string $ref
     * @return string
     */
    protected function extractResultFromResponse($responseArray, $ref) {
        if (count($responseArray)===0) return '';
        if (empty($ref)) return '';
        if (array_key_exists($ref, $responseArray)) return (string)$responseArray[$ref];
        foreach ($responseArray as $key => $value) {
            if ($key==$ref) return $value;
            if (is_array($value)) return $this->extractResultFromResponse($value, $ref);
        }
        return '';
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