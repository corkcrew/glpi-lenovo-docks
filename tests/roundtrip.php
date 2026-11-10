<?php

/** SPDX-License-Identifier: MIT */

use function ItcoreTests\check;

require __DIR__ . '/Assertions.php';
require __DIR__ . '/GlpiBootstrap.php';
$root = getenv('GLPI_TEST_ROOT');
\ItcoreTests\bootGlpi(getenv('GLPI_TEST_CORE'), $root);
if (!str_starts_with($DB->dbdefault, 'itcore_docks_test_legacy_')) {
    throw new RuntimeException('Only the disposable legacy fixture is accepted.');
}
$before = json_decode(
    file_get_contents($root . '/before-docks.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$plugin = new Plugin();
$plugin->getFromDBByCrit(['directory' => 'itcoreinventory']);
$id = $plugin->getID();
$class = PluginItcoreinventoryNative::ASSET;
$assetsBefore = array_values(iterator_to_array($DB->request(['FROM' => 'glpi_assets_assets'])));
$plan = PluginItcoreinventoryUndo::plan();
check(
    $plan['original_cards'] === 3,
    'uninstall preview counts original cards'
);
check(
    $assetsBefore === array_values(iterator_to_array($DB->request(['FROM' => 'glpi_assets_assets']))),
    'preview is read only'
);
$plugin->uninstall($id);
$plugin->getFromDB($id);
check(
    (int) $plugin->fields['state'] === Plugin::NOTINSTALLED,
    'actual GLPI uninstall succeeds'
);
foreach ($before as $table => $rows) {
    foreach ($rows as $row) {
        $current = array_values(
            iterator_to_array(
                $DB->request(['FROM' => $table, 'WHERE' => ['id' => $row['id']]])
            )
        );
        check(
            count($current) === 1,
            'original row exists: ' . $table . ':' . $row['id']
        );
        check(
            reset($current) == $row,
            'original fields restored: ' . $table . ':' . $row['id']
        );
    }
}
$plugin->install($id);
check($plugin->activate($id), 'reinstall activates');
$plugin->bootPlugins();
$current = array_values(
    iterator_to_array(
        $DB->request(
            [
            'FROM' => 'glpi_assets_assets',
            'WHERE' => ['is_deleted' => 0]
            ]
        )
    )
);
check(
    count($current) === 2,
    'reinstall reuses native assets without duplicates'
);
$editedId = (int) $current[0]['id'];
$edited = new $class();
check(
    $edited->update(
        [
        'id' => $editedId,
        'name' => 'Manually edited dock',
        'comment' => 'Retained user note'
        ]
    ),
    'native dock edited'
);
$pc = new Computer();
$pc->getFromDBByCrit(['name' => 'TEST-PC']);
$newId = PluginItcoreinventoryNative::upsert(
    plugin_itcoreinventory_info('17EF', '30A9', 'NEW00001'),
    $pc
);
check($newId > 0, 'new dock created after installation');
$DB->insert(PluginItcoreinventoryNative::QUEUE, [

    'deviceid' => 'BLOCK-REMOVAL',
 'payload' => '{}',
 'date_mod' => date('Y-m-d H:i:s'),


]);
$refused = false;
try {
    $plugin->uninstall($id);
} catch (RuntimeException $error) {
    $refused = str_contains($error->getMessage(), 'Pending dock reports');
}
check($refused, 'pending inventory safely blocks uninstall');
$plugin->getFromDB($id);
check(
    (int) $plugin->fields['state'] === Plugin::ACTIVATED,
    'blocked core uninstall keeps plugin installed'
);
$cron = new CronTask();
check(
    $cron->getFromDBByCrit(
        [
        'itemtype' => 'PluginItcoreinventoryNative',
        'name' => 'DockInventory'
        ]
    ),
    'blocked removal preserves automatic action'
);
$DB->delete(
    PluginItcoreinventoryNative::QUEUE,
    ['deviceid' => 'BLOCK-REMOVAL']
);
$plugin->uninstall($id);
$plugin->getFromDB($id);
check(
    (int) $plugin->fields['state'] === Plugin::NOTINSTALLED,
    'second GLPI uninstall succeeds'
);
$editedLegacy = array_values(
    iterator_to_array(
        $DB->request(
            [
            'FROM' => 'glpi_peripherals',
            'WHERE' => ['name' => 'Manually edited dock',
            'is_deleted' => 0]
            ]
        )
    )
);
check(
    count($editedLegacy) > 0 && $editedLegacy[0]['comment'] === 'Retained user note',
    'later edits retained in core peripherals'
);
$newLegacy = array_values(
    iterator_to_array(
        $DB->request(
            [
            'FROM' => 'glpi_peripherals',
            'WHERE' => ['serial' => 'NEW00001',
            'is_deleted' => 0]
            ]
        )
    )
);
check(
    count($newLegacy) === 1,
    'new dock retained in core peripherals'
);
$plugin->install($id);
check($plugin->activate($id), 'third installation succeeds');
$current = array_values(
    iterator_to_array(
        $DB->request(
            [
            'FROM' => 'glpi_assets_assets',
            'WHERE' => ['is_deleted' => 0]
            ]
        )
    )
);
check(
    count($current) === 3,
    'third installation reuses all original and new docks'
);
$edited->getFromDB($editedId);
check(
    $edited->fields['name'] === 'Manually edited dock',
    'manual name survives reinstall'
);
$plugin->uninstall($id);
$plugin->getFromDB($id);
check(
    (int) $plugin->fields['state'] === Plugin::NOTINSTALLED,
    'third uninstall succeeds'
);
echo 'ROUNDTRIP PASSED' . PHP_EOL;
