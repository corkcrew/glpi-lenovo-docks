<?php

/** SPDX-License-Identifier: MIT */

use function ItcoreTests\check;

require __DIR__ . '/Assertions.php';
require __DIR__ . '/GlpiBootstrap.php';
$kernel = \ItcoreTests\bootGlpi((string) getenv('GLPI_TEST_CORE'), (string) getenv('GLPI_TEST_ROOT'));
if (!str_starts_with($DB->dbdefault, 'itcore_docks_test_upgrade_')) {
    throw new RuntimeException('This fixture requires a disposable upgrade test database.');
}
$definition = new \Glpi\Asset\AssetDefinition();
check($definition->getFromDBBySystemName('DockStation'), 'previous native definition exists');
$query = [
    'SELECT' => ['id', 'serial', 'users_id', 'locations_id', 'assets_assetmodels_id'],
    'FROM' => 'glpi_assets_assets',
    'WHERE' => ['assets_assetdefinitions_id' => $definition->getID(), 'is_deleted' => 0],
    'ORDER' => 'id',
];
$before = iterator_to_array($DB->request($query));
$taskQuery = [
    'SELECT' => ['id', 'mode', 'state', 'frequency', 'lastrun'],
    'FROM' => 'glpi_crontasks',
    'WHERE' => ['itemtype' => 'PluginItcoreinventoryNative', 'name' => 'DockInventory'],
];
$scheduleBefore = iterator_to_array($DB->request($taskQuery));
$plugin = new Plugin();
$plugin->checkPluginState('itcoreinventory');
$plugin->getFromDBByCrit(['directory' => 'itcoreinventory']);
$id = $plugin->getID();
$plugin->install($id);
check($plugin->activate($id), 'upgrade activated');
check(PluginItcoreinventoryNative::ready(), 'native definition retained');
check($before === iterator_to_array($DB->request($query)), 'existing native assets and assignments unchanged');
check($scheduleBefore === iterator_to_array($DB->request($taskQuery)), 'existing schedule and task ID unchanged');
$refused = false;
try {
    PluginItcoreinventoryUndo::plan();
} catch (RuntimeException $error) {
    $refused = str_contains($error->getMessage(), 'without a reverse journal');
}
check($refused, 'old migration without provenance refuses an unverified exact return');
check($before === iterator_to_array($DB->request($query)), 'refused return does not alter old assets');
echo 'UPGRADE PASSED: ' . count($before) . ' native assets retained' . PHP_EOL;
