<?php

/** SPDX-License-Identifier: MIT */

require_once dirname(__DIR__) . '/src/PluginItcoreinventoryDockConnection.php';

if (!class_exists('PluginItcoreinventoryDockConnection', false)) {
    class_alias(
        \GlpiPlugin\Itcoreinventory\PluginItcoreinventoryDockConnection::class,
        'PluginItcoreinventoryDockConnection'
    );
}
