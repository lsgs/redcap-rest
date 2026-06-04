<?php
/**
 * REDCap External Module: REDCap REST
 * Send API calls when saving particular instruments when a trigger condition is met.
 * Example URL: 
 * /ExternalModules/?prefix=redcap_rest&page=export_import&pid=45&action=export
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\REDCapREST\REDCapREST)) { exit(); }
if (!$module->userHasPermission()) exit(0);
switch ($_GET['action']) {
    case 'export': 
        $sep = (isset($_GET['sep'])) ? $module->escape($_GET['sep']) : null;
        $ver = (isset($_GET['ver'])) ? intval($_GET['ver']) : null;
        $result = $module->export($sep, $ver); 
        break;
    case 'import': 
        $errors = $module->import((isset($_POST['ajax-action'])) ? $module->escape($_POST['ajax-action']) : null); 
        $result = array(
            'result' => (count($errors)) ? 0 : 1,
            'errors' => $errors
        );
        $result = \json_encode($result); 
        break;
    default: $result = null;
}
echo $result;