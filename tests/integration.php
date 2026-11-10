<?php

/** SPDX-License-Identifier: MIT */

use function ItcoreTests\check as itcoreIntegrationCheck;

/** Destructive fixtures: only an explicitly named, disposable test database is accepted. */
require __DIR__ . '/Assertions.php';
require __DIR__ . '/GlpiBootstrap.php';
$core = getenv('GLPI_TEST_CORE');
$testRoot = getenv('GLPI_TEST_ROOT');
$scenario = getenv('GLPI_TEST_SCENARIO') ?: 'clean';
if (PHP_SAPI !== 'cli' || !$core || !$testRoot || !in_array($scenario, ['clean', 'legacy'], true)) {
    throw new RuntimeException(
        'Set GLPI_TEST_CORE, GLPI_TEST_ROOT and GLPI_TEST_SCENARIO.'
    );
}
$kernel = \ItcoreTests\bootGlpi($core, $testRoot);
if (!preg_match('/^itcore_docks_test_[a-z0-9_]+$/D', $DB->dbdefault)) {
    throw new RuntimeException('Refusing a non-test database.');
}
$DB->update(
    'glpi_notifications',
    ['is_active' => 0],
    ['id' => ['>',
        0]]
);
$DB->update('glpi_crontasks', ['state' => 0], ['id' => ['>', 0]]);

$legacyIds = [];
$pcId = 0;
$documentId = 0;
if ($scenario === 'legacy') {
    $maker = new Manufacturer();
    $makerId = $maker->add(['name' => 'LENOVO']);
    $model = new PeripheralModel();
    $modelId = $model->add([

        'name' => 'Universal USB-C Dock',

        'product_number' => '40AY'

    ]);
    $pc = new Computer();
    $pcId = $pc->add(['name' => 'TEST-PC', 'entities_id' => 0]);
    $samples = [

        [

            'serial' => '1SSD21B41469ABCD1234',

            'peripheralmodels_id' => 0,

            'manufacturers_id' => 0

        ],

        ['serial' => 'ABCD1234', 'peripheralmodels_id' => $modelId],

        ['serial' => '1S40B0ABCDEFG2', 'peripheralmodels_id' => 0],


    ];
    foreach ($samples as $sample) {
        $peripheral = new Peripheral();
        $id = $peripheral->add($sample + [
            'name' => 'USB Composite Device',
            'manufacturers_id' => $makerId,
            'peripheraltypes_id' => 0,
            'entities_id' => 0,
            'is_global' => 0,
            'is_dynamic' => 1,
        ]);
        itcoreIntegrationCheck((bool) $id, 'legacy fixture created');
        $legacyIds[] = $id;
        $connection = new \Glpi\Asset\Asset_PeripheralAsset();
        itcoreIntegrationCheck((bool) $connection->add([

            'itemtype_asset' => 'Computer',

            'items_id_asset' => $pcId,

            'itemtype_peripheral' => 'Peripheral',

            'items_id_peripheral' => $id,

            'is_dynamic' => 1,


        ]), 'legacy PC connection created');
    }
    $unrelated = new Peripheral();
    $unrelatedId = $unrelated->add([

        'name' => 'Unrelated USB device',

        'serial' => 'MOUSE001',

        'manufacturers_id' => $makerId,

        'entities_id' => 0,


    ]);
    $conf = new \Glpi\Inventory\Conf();
    $inventoryPath = GLPI_INVENTORY_DIR . '/' . $conf->buildInventoryFileName('Computer', $pcId, 'json');
    if (!str_starts_with($inventoryPath, GLPI_VAR_DIR . '/')) {
        throw new RuntimeException('Inventory fixture path is outside the test directory.');
    }
    if (!is_dir(dirname($inventoryPath))) {
        mkdir(dirname($inventoryPath), 0o700, true);
    }
    file_put_contents($inventoryPath, json_encode([

        'content' => [

            'usbdevices' => [

                [

                    'vendorid' => '17EF',

                    'productid' => '30A9',

                    'serial' => 'ABCD1234'

                ],

                [

                    'vendorid' => '17EF',

                    'productid' => '30B5',

                    'serial' => '1S40B0ABCDEFG2'

                ],


            ]

        ],


    ], JSON_THROW_ON_ERROR));
    $document = new Document();
    $documentId = $document->add([

        'name' => 'Fixture document',

        'link' => 'https://example.invalid/',

        'entities_id' => 0

    ]);
    $documentLink = new Document_Item();
    itcoreIntegrationCheck((bool) $documentLink->add([

        'documents_id' => $documentId,

        'itemtype' => 'Peripheral',

        'items_id' => $legacyIds[1],


    ]), 'document fixture linked');
    $finance = new Infocom();
    itcoreIntegrationCheck((bool) $finance->add([

        'itemtype' => 'Peripheral',

        'items_id' => $legacyIds[0],

        'value' => 100,


    ]), 'financial fixture created');
    foreach (array_slice($legacyIds, 0, 2) as $sourceId) {
        $DB->insert('glpi_lockedfields', [

            'itemtype' => 'Peripheral',
        'items_id' => $sourceId,

            'field' => 'locations_id',
        'value' => '0',


        ]);
    }
}

if ($scenario === 'legacy') {
    $before = [];
    foreach (
        [
        'glpi_peripherals',
        'glpi_documents_items',
        'glpi_infocoms',
        'glpi_lockedfields',
        \Glpi\Asset\Asset_PeripheralAsset::getTable()
        ] as $table
    ) {
        $before[$table] = array_values(iterator_to_array($DB->request(['FROM' => $table])));
    }
    file_put_contents(
        $testRoot . '/before-docks.json',
        json_encode($before, JSON_THROW_ON_ERROR)
    );
}

$plugin = new Plugin();
$plugin->checkPluginState('itcoreinventory');
itcoreIntegrationCheck(
    $plugin->getFromDBByCrit(['directory' => 'itcoreinventory']),
    'plugin discovered'
);
$pluginId = $plugin->getID();
$plugin->install($pluginId);
itcoreIntegrationCheck($plugin->activate($pluginId), 'plugin activated');
itcoreIntegrationCheck(
    PluginItcoreinventoryNative::ready(),
    'native asset definition ready after normal installation'
);
$class = PluginItcoreinventoryNative::ASSET;
itcoreIntegrationCheck(class_exists($class), 'native asset class exists');
itcoreIntegrationCheck(
    is_callable('plugin_itcoreinventory_native_pre'),
    'global GLPI inventory hook exported'
);
itcoreIntegrationCheck(
    str_starts_with(
        (new ReflectionClass('PluginItcoreinventoryNative'))->getName(),
        'GlpiPlugin\\Itcoreinventory\\'
    ),
    'PSR namespace and legacy compatibility bridge loaded'
);
$assets = iterator_to_array($DB->request([

    'FROM' => $class::getTable(),

    'WHERE' => [

        'assets_assetdefinitions_id' => $class::getDefinition()->getID(),

        'is_deleted' => 0

    ],


]));
itcoreIntegrationCheck(
    count($assets) === ($scenario === 'legacy' ? 2 : 0),
    'expected native asset count'
);
if ($scenario === 'legacy') {
    $map = iterator_to_array($DB->request(['FROM' => PluginItcoreinventoryNative::MAP]));
    itcoreIntegrationCheck(count($map) === 3, 'all three legacy records mapped');
    $byOldId = [];
    foreach ($map as $row) {
        $byOldId[$row['peripherals_id']] = $row['assets_id'];
        $old = new Peripheral();
        $old->getFromDB($row['peripherals_id']);
        itcoreIntegrationCheck(
            (bool) $old->fields['is_deleted'],
            'source archived, not purged'
        );
    }
    $first = (int) $byOldId[$legacyIds[0]];
    itcoreIntegrationCheck(
        $first === (int) $byOldId[$legacyIds[1]],
        'USB duplicate merged into the same dock'
    );
    $native = new $class();
    $native->getFromDB($first);
    itcoreIntegrationCheck(
        $native->fields['serial'] === 'ABCD1234',
        'long USB serial normalized'
    );
    itcoreIntegrationCheck(
        $native->fields['name'] === 'ThinkPad Universal USB-C Dock',
        'generic USB name resolved'
    );
    itcoreIntegrationCheck(
        (int) $native->fields['assets_assetmodels_id'] > 0,
        'native model created'
    );
    itcoreIntegrationCheck(
        (int) $native->fields['manufacturers_id'] === (int) $makerId,
        'manufacturer resolved from VID/PID'
    );

    $docs = iterator_to_array($DB->request([

        'FROM' => 'glpi_documents_items',

        'WHERE' => [

            'documents_id' => $documentId,

            'itemtype' => $class,

            'items_id' => $first

        ],


    ]));
    itcoreIntegrationCheck(count($docs) === 1, 'document association preserved');
    $finances = iterator_to_array($DB->request([

        'FROM' => 'glpi_infocoms',

        'WHERE' => ['itemtype' => $class, 'items_id' => $first],


    ]));
    itcoreIntegrationCheck(
        count($finances) === 1 && (float) reset($finances)['value'] === 100.0,
        'financial data preserved'
    );
    $links = iterator_to_array($DB->request([

        'FROM' => PluginItcoreinventoryNative::LINKS,

        'WHERE' => ['is_deleted' => 0]

    ]));
    itcoreIntegrationCheck(
        count($links) === 2,
        'duplicate PC connections merged without loss'
    );
    $unrelated->getFromDB($unrelatedId);
    itcoreIntegrationCheck(
        !$unrelated->fields['is_deleted'],
        'unrelated USB device untouched'
    );
    $again = PluginItcoreinventoryMigration::run();
    itcoreIntegrationCheck(
        $again['status'] === 'already_migrated',
        'migration is idempotent'
    );
    $pc = new Computer();
    $pc->getFromDB($pcId);
    $info = plugin_itcoreinventory_info('17EF', '30A9', '1SSD21B41469ABCD1234');
    itcoreIntegrationCheck(
        PluginItcoreinventoryNative::upsert($info, $pc) === $first,
        'next inventory reuses migrated asset'
    );
    $agent = new Agent();
    $agent->add(
        [

            'deviceid' => 'ITCORE-TEST-DEVICE',

            'agenttypes_id' => 0,

            'itemtype' => 'Computer',

            'items_id' => $pcId

        ]
    );
    $report = (object) [
        'deviceid' => 'ITCORE-TEST-DEVICE',
        'partial' => true,
        'content' => (object) ['usbdevices' => [

            (object) ['vendorid' => '17EF', 'productid' => '30A9', 'serial' => '1SSD21B41469ABCD1234'],

            (object) ['vendorid' => '1234', 'productid' => '0001', 'serial' => 'MOUSE001'],


        ]],
    ];
    plugin_itcoreinventory_native_pre($report);
    itcoreIntegrationCheck(
        count($report->content->usbdevices) === 1,
        'hook consumes only recognized docks'
    );
    plugin_itcoreinventory_native_post($report);
    itcoreIntegrationCheck(
        count(iterator_to_array($DB->request(['FROM' => PluginItcoreinventoryNative::QUEUE]))) === 0,
        'post-inventory hook drains the recognized report queue'
    );
    $fixture = new Peripheral();
    $fixtureId = $fixture->add([

        'name' => 'Migration safety fixture',

        'serial' => 'ABCD0003',

        'manufacturers_id' => $makerId,

        'peripheralmodels_id' => $modelId,

        'entities_id' => 0,

        'is_global' => 0,


    ]);
    $DB->doQuery(
        ('CREATE TABLE glpi_itcore_test_reference (id int unsigned '
        .
        'PRIMARY KEY, peripherals_id int unsigned)')
    );
    try {
        $DB->insert(
            'glpi_itcore_test_reference',
            ['id' => 1,
                'peripherals_id' => $fixtureId]
        );
        $refused = false;
        try {
            PluginItcoreinventoryMigration::run();
        } catch (RuntimeException $exception) {
            $refused = str_contains(
                $exception->getMessage(),
                'Unsupported static peripheral foreign key'
            );
        }
        $fixture->getFromDB($fixtureId);
        itcoreIntegrationCheck(
            $refused && !$fixture->fields['is_deleted'],
            'unsupported reference leaves source intact'
        );
        $refused = false;
        try {
            PluginItcoreinventoryNative::upsert(
                plugin_itcoreinventory_info('17EF', '30A9', 'ABCD0003'),
                $pc
            );
        } catch (RuntimeException $exception) {
            $refused = str_contains($exception->getMessage(), 'must be migrated');
        }
        itcoreIntegrationCheck(
            $refused,
            'unmigrated source prevents a duplicate native asset'
        );
    } finally {
        $DB->doQuery('DROP TABLE glpi_itcore_test_reference');
    }
    $DB->update(
        'glpi_peripherals',
        ['is_deleted' => 1],
        ['id' => $fixtureId]
    );
    $DB->beginTransaction();
    try {
        foreach ([1, 2] as $userId) {
            $conflicting = new Peripheral();
            $conflicting->add([

                'name' => 'Conflicting ownership fixture',

                'serial' => 'ABCD0004',

                'manufacturers_id' => $makerId,

                'peripheralmodels_id' => $modelId,

                'entities_id' => 0,

                'users_id' => $userId,

                'is_global' => 0,


            ]);
        }
        $refused = false;
        try {
            PluginItcoreinventoryMigration::plan();
        } catch (RuntimeException $exception) {
            $refused = str_contains($exception->getMessage(), 'Conflicting legacy dock values');
        }
        itcoreIntegrationCheck(
            $refused,
            'conflicting owners require review, no automatic merge'
        );
    } finally {
        $DB->rollBack();
    }
}
$cron = new CronTask();
itcoreIntegrationCheck(
    $cron->getFromDBByCrit([

        'itemtype' => 'PluginItcoreinventoryNative',

        'name' => 'DockInventory'

    ]),
    'cron registered with backward-compatible itemtype'
);
$idBefore = $cron->getID();
$DB->update(
    'glpi_crontasks',
    ['frequency' => 3600,
        'mode' => 1],
    ['id' => $idBefore]
);
$plugin->install($pluginId);
$plugin->activate($pluginId);
$cron->getFromDB($idBefore);
itcoreIntegrationCheck(
    (int) $cron->fields['frequency'] === 3600 && (int) $cron->fields['mode'] === 1,
    'reinstallation preserves administrator schedule'
);
echo 'INTEGRATION PASSED: ' . $scenario . PHP_EOL;
