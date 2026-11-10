<?php

/** SPDX-License-Identifier: MIT */

require_once dirname(__DIR__) . '/src/PluginItcoreinventorySettings.php';

if (!class_exists('PluginItcoreinventorySettings', false)) {
    class_alias(
        \GlpiPlugin\Itcoreinventory\PluginItcoreinventorySettings::class,
        'PluginItcoreinventorySettings'
    );
}
