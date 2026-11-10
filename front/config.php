<?php

/** SPDX-License-Identifier: MIT */

global $DB;
if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__) . '/src/PluginItcoreinventoryProfilePage.php';
\GlpiPlugin\Itcoreinventory\PluginItcoreinventoryProfilePage::page();
