<?php

/** SPDX-License-Identifier: MIT */

require_once dirname(__DIR__) . '/src/PluginItcoreinventoryMigration.php';

if (!class_exists('PluginItcoreinventoryMigration', false)) {
    class_alias(
        \GlpiPlugin\Itcoreinventory\PluginItcoreinventoryMigration::class,
        'PluginItcoreinventoryMigration'
    );
}
