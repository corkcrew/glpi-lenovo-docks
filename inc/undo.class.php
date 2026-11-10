<?php

/** SPDX-License-Identifier: MIT */

require_once dirname(__DIR__) . '/src/PluginItcoreinventoryUndo.php';

if (!class_exists('PluginItcoreinventoryUndo', false)) {
    class_alias(\GlpiPlugin\Itcoreinventory\PluginItcoreinventoryUndo::class, 'PluginItcoreinventoryUndo');
}
