<?php

/** SPDX-License-Identifier: MIT */

require_once dirname(__DIR__) . '/src/PluginItcoreinventoryProfiles.php';
if (!class_exists('PluginItcoreinventoryProfiles', false)) {
    class_alias(\GlpiPlugin\Itcoreinventory\PluginItcoreinventoryProfiles::class, 'PluginItcoreinventoryProfiles');
}
