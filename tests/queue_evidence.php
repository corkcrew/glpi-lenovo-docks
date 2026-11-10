<?php

/** SPDX-License-Identifier: MIT */

use function ItcoreTests\check;

require __DIR__ . '/Assertions.php';
require __DIR__ . '/GlpiBootstrap.php';
$kernel = \ItcoreTests\bootGlpi((string) getenv('GLPI_TEST_CORE'), (string) getenv('GLPI_TEST_ROOT'));
if (!str_starts_with($DB->dbdefault, 'itcore_docks_test_legacy_')) {
    throw new RuntimeException('This follow-up fixture requires the disposable legacy test database.');
}
$pc = new Computer();
check($pc->getFromDBByCrit(['name' => 'TEST-PC']), 'test computer found');
$maker = new Manufacturer();
$maker->getFromDBByCrit(['name' => 'LENOVO']);
$peripheral = new Peripheral();
$raw = '1SSD21B41469ABCD0005';
$id = $peripheral->add([
    'name' => 'USB Composite Device',
    'serial' => $raw,
    'manufacturers_id' => $maker->getID(),
    'peripheralmodels_id' => 0,
    'entities_id' => 0,
    'is_global' => 0,
]);
$link = new \Glpi\Asset\Asset_PeripheralAsset();
$link->add([
    'itemtype_asset' => 'Computer',
    'items_id_asset' => $pc->getID(),
    'itemtype_peripheral' => 'Peripheral',
    'items_id_peripheral' => $id,
    'is_dynamic' => 1,
]);
$before = PluginItcoreinventoryMigration::plan();
check(!isset($before['mapping'][$id]), 'unknown old USB card is not guessed without evidence');
$info = plugin_itcoreinventory_info('17EF', '30A9', $raw);
PluginItcoreinventoryNative::enqueue('ITCORE-TEST-DEVICE', [$info], false);
$plan = PluginItcoreinventoryMigration::plan();
check(isset($plan['mapping'][$id]), 'new queued report supplies VID/PID evidence');
PluginItcoreinventoryMigration::run();
$peripheral->getFromDB($id);
check((bool) $peripheral->fields['is_deleted'], 'old card migrated after the first useful report');
check(PluginItcoreinventoryNative::processQueue() === 1, 'queued report processed against migrated card');
$settings = plugin_itcoreinventory_settings_defaults();
$schema = plugin_itcoreinventory_settings_schema();
$html = \Glpi\Application\View\TemplateRenderer::getInstance()->render('@itcoreinventory/settings.html.twig', [
    'schema' => $schema,
    'settings' => $settings,
    'tabs' => $schema['tabs'],
    'active' => 'general',
    'message' => '',
    'error' => '',
    'csrf' => 'test-only',
    'glpi_csrf' => 'test-only',
    'revision' => 0,
    'preview' => null,
    'preview_fields' => [],
    'history' => [],
    'migration_url' => '/plugins/itcoreinventory/front/migration.php',
]);
check(str_contains($html, 'itcore-settings-editor'), 'Twig settings template rendered');
check(str_contains($html, '_glpi_csrf_token'), 'standard GLPI CSRF field present');
echo 'QUEUE EVIDENCE AND TEMPLATE PASSED' . PHP_EOL;
