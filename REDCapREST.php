<?php
/**
 * REDCap External Module: ERDCap REST
 * Send API calls when saving particular instruments when a trigger condition is met.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\REDCapREST;

use ExternalModules\AbstractExternalModule;
use Exception;
use JsonException;

class REDCapREST extends AbstractExternalModule {
    const MODULE_TITLE = "REDCap REST";
    protected $record;
    protected $event_id;
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
            $this->instance = $repeat_instance;

            $destURL = $this->pipe($instruction['dest-url']);
            $method = $instruction['http-method'];
            $contentType = $this->makeContentType($instruction['content-type']);
            $curlOptions = $this->makeCurlOptionsArray($instruction['curl-options']);
            $resultField = $instruction['result-field'];

            try {
                $payload = $this->formatPayload($instruction['payload'], $contentType);
            } catch (\Throwable $th) {
                \REDCap::logEvent($title, $th->getMessage(), '', $this->record, $this->event_id);
                return;
            }
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $destURL);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            if ($GLOBALS['is_development_server']) curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

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
            
            // If not sending as x-www-form-urlencoded, then set special header
            if ($contentType != 'application/x-www-form-urlencoded') {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: $contentType", "Content-Length: ".strlen($payload)));
            }

            foreach ($curlOptions as $opt) {
                curl_setopt($ch, $opt[0], $opt[1]);
            }
//$fp = dirname(__FILE__).'/log.txt';
//curl_setopt($ch, CURLOPT_VERBOSE, true);
//curl_setopt($ch, CURLOPT_STDERR, fopen($fp, 'w+'));
            // Make the call
            $response = curl_exec($ch);
            $info = curl_getinfo($ch);
            curl_close ($ch);

            \REDCap::logEvent($title, "Sent $method to $destURL:\n".$payload."\nResponse: ".$info['http_code']."\n".$response, '', $this->record, $this->event_id);
            
            if ($info['http_code']==200 && !empty($resultField)) {
                global $Proj;
                $saveArray = [$this->record=>[$this->event_id=>[$Proj->table_pk=>$this->record,$resultField=>$response]]];

                $saveResult = \REDCap::saveData('array', $saveArray, 'overwrite');

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
    protected function pipe($string, $contentType='application/json') {
        if ($contentType=='application/x-www-form-urlencoded') {
            // need to urlencode piped strings for x-www-form-urlencoded
            $encodedString = '';
            $kvpairs = \explode('&', $string);
            foreach ($kvpairs as $kvpair) {
                list($k, $v) = \explode('=', $kvpair, 2);
                $encodedString .= "&$k=".\urlencode($this->pipe($v));
            }
            $pipedString = substr($encodedString, 1);
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
        // replace any missing values with "" or null for valid json
        $pipedString = str_replace('"______"','""',$pipedString); // empty string value
        $pipedString = str_replace('______','null',$pipedString); // empty non-string value
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
        if ($contentType == 'application/json') {
            try {
               $jsonDecodeJsonPayload = \json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
               $jsonString = \json_encode($jsonDecodeJsonPayload, JSON_THROW_ON_ERROR);   
            } catch (JsonException $e) {
                throw new Exception('Could not generate JSON payload \n'.$e->getMessage()."\nPayload string: \n$payload", 0, $e);
            }
            return $jsonString;
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
    
    protected function makeCurlOptionsArray($instructionCurlOptions='') {
        if (empty(trim($instructionCurlOptions))) return array();

        $optionsArray = array();
        $optionLines = explode('\n', $instructionCurlOptions);
        foreach ($optionLines as $line) {
            list($opt, $val) = explode('=', $line, 2);
            $optIntVal = $this->findCurlOptInt($opt);
            if ($optIntVal) $optionsArray[] = [$optIntVal, $val];
        }
        return $optionsArray;
    }

    /**
     * example
     * Return the result requested
     * @return string
     */
    public function example() {
        $content = file_get_contents("php://input");
        $data = \json_decode($content); // not sure why $_POST is empty!
        \REDCap::logEvent(self::MODULE_TITLE." external module", "Test request:\n".print_r($data, true));
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