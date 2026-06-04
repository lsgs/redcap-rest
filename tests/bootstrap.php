<?php
/**
 * Test bootstrap
 * Stubs out REDCap framework classes so module code can be loaded
 * without a REDCap installation.
 */

// Stub AbstractExternalModule so REDCapREST can extend it
namespace ExternalModules {
    if (!class_exists('ExternalModules\AbstractExternalModule')) {
        abstract class AbstractExternalModule {
            public function getSubSettings($key) { return []; }
            public function getProjectSetting($key) { return []; }
            public function setProjectSetting($key, $value) {}
            public function getSystemSetting($key) { return null; }
            public function query($sql, $params = []) { return null; }
            public function escape($value) { return $value; }
            public function log($message) {}
        }
    }
}

// Stub REDCap class
namespace {
    if (!class_exists('REDCap')) {
        class REDCap {
            public static function evaluateLogic() { return true; }
            public static function getData() { return []; }
            public static function saveData() { return ['errors' => []]; }
            public static function logEvent() {}
            public static function isLongitudinal() { return false; }
            public static function getEventNames() { return ''; }
            public static function filterHtml($s) { return $s; }
        }
    }

    if (!class_exists('Piping')) {
        class Piping {
            public static function replaceVariablesInLabel() { return ''; }
        }
    }

    if (!function_exists('starts_with')) {
        function starts_with($haystack, $needle) {
            return strpos($haystack, $needle) === 0;
        }
    }

    if (!function_exists('db_fetch_assoc')) {
        function db_fetch_assoc($result) { return []; }
    }
}
