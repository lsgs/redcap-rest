<?php
/**
 * REDCap External Module: REDCap REST
 * Send API calls when saving particular instruments when a trigger condition is met.
 * Example URL: 
 * /ExternalModules/?prefix=redcap_rest&page=summary&pid=45
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\REDCapREST\REDCapREST)) { exit(); }

if (!$module->userHasPermission()) { 
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
    echo '<div class="red">'.\RCView::tt('pub_001').'</div>'; 
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
    exit;
}
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
$module->summaryPage();
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';