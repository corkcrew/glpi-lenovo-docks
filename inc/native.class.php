<?php

/** SPDX-License-Identifier: MIT */

require_once dirname(__DIR__) . '/src/PluginItcoreinventoryNative.php';

if (!class_exists('PluginItcoreinventoryNative', false)) {
    class_alias(
        \GlpiPlugin\Itcoreinventory\PluginItcoreinventoryNative::class,
        'PluginItcoreinventoryNative'
    );
}
