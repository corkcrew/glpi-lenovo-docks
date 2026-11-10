<?php

/** SPDX-License-Identifier: MIT */

if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}
if (!class_exists(PluginItcoreinventoryNative::ASSET)) {
    http_response_code(503);
    exit;
}
$class = PluginItcoreinventoryNative::ASSET;
Html::redirect($class::getSearchURL());
