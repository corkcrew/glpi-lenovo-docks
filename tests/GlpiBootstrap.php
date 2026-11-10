<?php

/** SPDX-License-Identifier: MIT */

namespace ItcoreTests;

use RuntimeException;

function bootGlpi(string $core, string $testRoot): \Glpi\Kernel\Kernel
{
    global $kernel, $DB, $CFG_GLPI;
    $configuration = file_get_contents($testRoot . '/config/config_db.php');
    if (
        !preg_match(
            '/\$dbdefault\s*=\s*[\'"](itcore_docks_test_[a-z0-9_]+)[\'"]/',
            $configuration
        )
    ) {
        throw new RuntimeException('Refusing to boot a configuration without an explicit disposable test DB.');
    }
    define('GLPI_CONFIG_DIR', $testRoot . '/config');
    define('GLPI_VAR_DIR', $testRoot . '/var');
    define('GLPI_PLUGINS_DIRECTORIES', [$testRoot . '/plugins']);
    chdir($core);
    require $core . '/vendor/autoload.php';
    $kernel = new \Glpi\Kernel\Kernel();
    $kernel->boot();
    return $kernel;
}
