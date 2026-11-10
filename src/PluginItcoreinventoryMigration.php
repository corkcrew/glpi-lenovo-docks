<?php

/** SPDX-License-Identifier: MIT */

namespace GlpiPlugin\Itcoreinventory;

use Computer;
use Config;
use Manufacturer;
use Peripheral;
use PeripheralModel;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/** Explicit CLI migration, used first against a dedicated database clone. */
class PluginItcoreinventoryMigration
{
    private static function columns(string $table): array
    {
        global $DB;
        $out = [];
        foreach ($DB->doQuery('SHOW COLUMNS FROM `' . $table . '`') as $r) {
            $out[] = $r['Field'];
        }
        return $out;
    }
    public static array $discoveryReport = [];

    /** Read only stored inventories linked to actual legacy Peripheral cards. */
    private static function inventoryEvidence(): array
    {
        global $DB;
        $out = [];
        $table = \Glpi\Asset\Asset_PeripheralAsset::getTable();
        $conf = new \Glpi\Inventory\Conf();
        foreach (
            $DB->request([

                'FROM' => $table,

                'WHERE' => [

                    'itemtype_asset' => 'Computer',

                    'itemtype_peripheral' => 'Peripheral',

                    'is_deleted' => 0

                ]

            ]) as $link
        ) {
            $pc = (int) $link['items_id_asset'];
            if (isset($out[$pc])) {
                continue;
            }
            $out[$pc] = [];
            foreach (['json', 'xml'] as $extension) {
                $path = GLPI_INVENTORY_DIR . '/' . $conf->buildInventoryFileName('Computer', $pc, $extension);
                if (!is_file($path) || filesize($path) > 20 * 1024 * 1024) {
                    continue;
                }
                try {
                    if ($extension === 'json') {
                        $data = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
                        $root = $data->content ?? $data;
                        foreach ($root->usbdevices ?? [] as $usb) {
                            $info = plugin_itcoreinventory_info(
                                (string) ($usb->vendorid ?? ''),
                                (string) ($usb->productid ?? ''),
                                (string) ($usb->serial ?? '')
                            );
                            if ($info !== null) {
                                $out[$pc][] = $info;
                            }
                        }
                    } else {
                        $before = libxml_use_internal_errors(true);
                        try {
                            $data = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
                            if ($data === false) {
                                continue;
                            }
                            $root = isset($data->CONTENT) ? $data->CONTENT
                            : $data;
                            foreach ($root->USBDEVICES as $usb) {
                                $info = plugin_itcoreinventory_info(
                                    (string) $usb->VENDORID,
                                    (string) $usb->PRODUCTID,
                                    (string) $usb->SERIAL
                                );
                                if ($info !== null) {
                                    $out[$pc][] = $info;
                                }
                            }
                        } finally {
                            libxml_clear_errors();
                            libxml_use_internal_errors($before);
                        }
                    }
                } catch (Throwable $e) {
                    self::$discoveryReport[] = ['computer_id' => $pc, 'status' => 'inventory_unreadable'];
                }
            }
        }
        // A newly received report may be the first available evidence for an old USB card.
        foreach ($DB->request(['FROM' => PluginItcoreinventoryNative::QUEUE]) as $job) {
            $agents = iterator_to_array($DB->request([

                'FROM' => 'glpi_agents',

                'WHERE' => ['deviceid' => $job['deviceid'], 'itemtype' => 'Computer'],


            ]));
            if (count($agents) !== 1) {
                continue;
            }
            $agent = reset($agents);
            $pc = (int) $agent['items_id'];
            $payload = json_decode($job['payload'], true);
            foreach ($payload['infos'] ?? [] as $stored) {
                $info = plugin_itcoreinventory_info(
                    (string) ($stored['vid'] ?? ''),
                    (string) ($stored['pid'] ?? ''),
                    (string) ($stored['raw'] ?? '')
                );
                if ($info !== null) {
                    $out[$pc][] = $info;
                }
            }
        }
        return $out;
    }

    public static function sources(): array
    {
        global $DB;
        self::$discoveryReport = [];
        $cfg = plugin_itcoreinventory_settings();
        $catalog = plugin_itcoreinventory_catalog();
        $evidence = self::inventoryEvidence();
        $table = \Glpi\Asset\Asset_PeripheralAsset::getTable();
        $groups = [];
        foreach (
            $DB->request([

                'FROM' => 'glpi_peripherals',

                'WHERE' => ['is_deleted' => 0, 'is_template' => 0],

                'ORDER' => 'id'

            ]) as $row
        ) {
            $raw = strtoupper(trim((string) $row['serial']));
            $matches = [];
            $previous = iterator_to_array($DB->request([

                'FROM' => PluginItcoreinventoryNative::MAP,

                'WHERE' => ['peripherals_id' => $row['id'], 'status' => 'reverted'],


            ]));
            if ($previous) {
                $snapshot = json_decode(
                    reset($previous)['snapshot'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $stored = $snapshot['_itcore_meta']['info'] ?? null;
                if ($stored) {
                    $recognized = plugin_itcoreinventory_info($stored['vid'], $stored['pid'], $raw);
                    if ($recognized) {
                        $key = $recognized['manufacturer_key'] . '|' . $recognized['model']
                            . '|' . $recognized['serial'];
                        $matches[$key] = $recognized;
                    }
                }
            }
            // A structured marker from an earlier plugin is authoritative VID/PID evidence.
            if (
                preg_match(
                    '/VID=([A-F0-9]{4});?\s*PID=([A-F0-9]{4})/i',
                    (string) $row['comment'],
                    $m
                )
            ) {
                $info = plugin_itcoreinventory_info($m[1], $m[2], $raw);
                if ($info !== null) {
                    $matches[$info['manufacturer_key'] . '|' . $info['model'] . '|' . $info['serial']] = $info;
                }
            }
            foreach (
                $DB->request([

                    'FROM' => $table,

                    'WHERE' => [

                        'itemtype_asset' => 'Computer',

                        'itemtype_peripheral' => 'Peripheral',

                        'items_id_peripheral' => $row['id'],

                        'is_deleted' => 0

                    ]

                ]) as $link
            ) {
                foreach ($evidence[(int) $link['items_id_asset']] ?? [] as $info) {
                    $normalized = plugin_itcoreinventory_info($info['vid'], $info['pid'], $raw);
                    $sameSerial = in_array($raw, [$info['raw'], $info['serial']], true)
                        || ($normalized && $normalized['serial_status'] === 'normalized'
                            && $normalized['serial'] === $info['serial']);
                    if ($raw !== '' && $sameSerial) {
                        $matches[$info['manufacturer_key'] . '|' . $info['model'] . '|' . $info['serial']] = $info;
                    }
                }
            }
            $maker = new Manufacturer();
            $isConfigured = $maker->getFromDB($row['manufacturers_id']) && (bool) array_filter(
                $cfg['manufacturers'],
                static fn($vendor) => $vendor['enabled']
                    && strcasecmp($vendor['name'], $maker->fields['name']) === 0
            );
            if (!$matches && $isConfigured && $raw !== '') {
                $model = new PeripheralModel();
                $modelCode = $model->getFromDB($row['peripheralmodels_id'])
                    ? strtoupper((string) $model->fields['product_number']) : '';
                if (
                    $modelCode === '' && strcasecmp($maker->fields['name'], 'LENOVO') === 0
                    && preg_match('/^1S(40[A-Z0-9]{2})[A-Z0-9]{8}$/D', $raw, $m)
                ) {
                    $modelCode = $m[1];
                }
                foreach ($catalog as $entry) {
                    if (
                        strtoupper($entry['model']) !== $modelCode
                        || strcasecmp($entry['manufacturer'], $maker->fields['name']) !== 0
                    ) {
                        continue;
                    }
                    $info = plugin_itcoreinventory_info($entry['vid'], $entry['pid'], $raw);
                    if ($info !== null && in_array($info['serial_status'], ['plain', 'normalized'], true)) {
                        $matches[$info['manufacturer_key'] . '|' . $info['model'] . '|' . $info['serial']] = $info;
                    }
                }
            }
            if (count($matches) !== 1) {
                if ($isConfigured || $matches) {
                    self::$discoveryReport[] = [

                        'id' => (int) $row['id'],

                        'status' => $matches ? 'ambiguous' : 'insufficient_evidence'

                    ];
                }
                continue;
            }
            if ($row['is_global'] || !empty($row['brand'])) {
                throw new RuntimeException('Legacy dock has unsupported global/brand data: '
                . $row['id']);
            }
            $info = reset($matches);
            $key = $row['entities_id'] . '|' . $info['manufacturer_key'] . '|' . $info['serial'];
            $groups[$key][] = ['source' => $row, 'info' => $info];
        }
        $sources = [];
        foreach ($groups as $entries) {
            usort(
                $entries,
                static function (array $a, array $b): int {
                    $aScore = (int) !empty($a['source']['users_id'])
                        + (int) !empty($a['source']['locations_id']);
                    $bScore = (int) !empty($b['source']['users_id'])
                        + (int) !empty($b['source']['locations_id']);
                    return ($bScore <=> $aScore)
                        ?: ((int) $a['source']['id'] <=> (int) $b['source']['id']);
                }
            );
            $canonicalId = (int) $entries[0]['source']['id'];
            $merged = $entries[0]['source'];
            foreach ($entries as $entry) {
                foreach (['otherserial', 'contact_num', 'users_id', 'locations_id'] as $field) {
                    if (empty($entry['source'][$field])) {
                        continue;
                    }
                    if (!empty($merged[$field]) && (string) $merged[$field] !== (string) $entry['source'][$field]) {
                        throw new RuntimeException('Conflicting legacy dock values: '
                        . $field);
                    }
                    $merged[$field] = $entry['source'][$field];
                }
                $comment = preg_replace(
                    '/^\[ITCORE (?:Dock|USB)[^\r\n]*(?:\r?\n)?/m',
                    '',
                    (string) $entry['source']['comment']
                );
                $canonicalComment = preg_replace(
                    '/^\[ITCORE (?:Dock|USB)[^\r\n]*(?:\r?\n)?/m',
                    '',
                    (string) $merged['comment']
                );
                if (
                    trim($comment) !== '' && trim($canonicalComment) !== ''
                    && trim($comment) !== trim($canonicalComment)
                ) {
                    throw new RuntimeException('Conflicting legacy dock comments');
                }
                if (trim($comment) !== '' && trim($canonicalComment) === '') {
                    $merged['comment'] = $entry['source']['comment'];
                }
            }
            foreach ($entries as $entry) {
                $entry['canonical_id'] = $canonicalId;
                $entry['merged'] = $merged;
                $sources[$entry['source']['id']] = $entry;
            }
        }
        return $sources;
    }

    public static function plan(): array
    {
        $sources = self::sources();
        $references = $sources ? self::referenceMatrix(array_keys($sources))
        : [];
        return [

            'source_records' => count($sources),

            'native_assets' => count(array_unique(array_column($sources, 'canonical_id'))),

            'mapping' => array_map(static fn ($entry) => [

                'target_source_id' => $entry['canonical_id'],

                'model' => $entry['info']['model'],

                'serial' => $entry['info']['serial']

            ], $sources),

            'references' => array_map(static fn ($group) => [

                'table' => $group['table'],

                'rows' => count($group['rows'])

            ], $references),

            'review' => self::$discoveryReport,


        ];
    }

    public static function referenceMatrix(array $ids, string $itemtype = 'Peripheral'): array
    {
        global $DB;
        $schema = $DB->escape($DB->dbdefault);
        $tables = [];
        foreach (
            $DB->doQuery(
                "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA='$schema' ORDER BY TABLE_NAME,ORDINAL_POSITION"
            ) as $r
        ) {
            $tables[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
        }
        $out = [];
        foreach ($tables as $table => $columns) {
            if (str_starts_with($table, 'glpi_plugin_itcoreinventory_dock')) {
                continue;
            }
            if ($itemtype === 'Peripheral' && in_array('peripherals_id', $columns, true)) {
                $n = count(iterator_to_array($DB->request([

                    'SELECT' => ['peripherals_id'],

                    'FROM' => $table,

                    'WHERE' => ['peripherals_id' => $ids]

                ])));
                if ($n) {
                    throw new RuntimeException('Unsupported static peripheral foreign key: '
                    . $table);
                }
            }
            if ($itemtype !== 'Peripheral' && in_array('assets_id', $columns, true)) {
                $rows = iterator_to_array($DB->request([

                    'SELECT' => ['assets_id'],
                'FROM' => $table,
                'WHERE' => ['assets_id' => $ids],


                ]));
                if ($rows) {
                    throw new RuntimeException('Unsupported native asset foreign key: '
                    . $table);
                }
            }
            if ($itemtype === 'Peripheral' && $table === \Glpi\Asset\Asset_PeripheralAsset::getTable()) {
                continue;
            }
            foreach ($columns as $type) {
                if (!preg_match('/^itemtype(.*)$/D', $type, $m)) {
                    continue;
                }
                $id = 'items_id' . $m[1];
                if (!in_array($id, $columns, true)) {
                    continue;
                }
                $rows = iterator_to_array($DB->request([

                    'FROM' => $table,

                    'WHERE' => [$type => $itemtype, $id => $ids]

                ]));
                if ($rows) {
                    if (!in_array('id', $columns, true)) {
                        throw new RuntimeException('Reference table without primary ID: '
                        . $table);
                    }
                    $out[] = compact('table', 'type', 'id', 'rows');
                }
            }
        }
        return $out;
    }
    public static function run(): array
    {
        global $DB;
        if (!PluginItcoreinventoryNative::lock()) {
            throw new RuntimeException('Dock inventory is busy');
        }
        try {
            $sources = self::sources();
            if (!$sources) {
                PluginItcoreinventoryNative::seedIdentities();
                Config::setConfigurationValues(
                    'plugin:itcoreinventory-native',
                    [

                        'migration_blocked' => '0',

                        'migration_error' => ''

                    ]
                );
                return ['status' => 'already_migrated','sources' => 0];
            }
            if (empty(plugin_itcoreinventory_settings()['migrate_existing'])) {
                throw new RuntimeException('Перенос устройств is disabled in plugin settings');
            }
            $ids = array_map('intval', array_keys($sources));
            $matrix = self::referenceMatrix($ids);
            $definition = PluginItcoreinventoryNative::createDefinition();
            $class = PluginItcoreinventoryNative::ASSET;
            $targetColumns = self::columns($class::getTable());
            $mapping = [];
            $canonicalTargets = [];
            $expectedTargets = [];
            $DB->beginTransaction();
            try {
                foreach ($sources as $oldId => $entry) {
                    if ((int)$entry['canonical_id'] !== (int)$oldId) {
                        continue;
                    }
                    $source = $entry['merged'];
                    $found = iterator_to_array($DB->request([

                        'FROM' => PluginItcoreinventoryNative::MAP,

                        'WHERE' => ['peripherals_id' => $oldId]

                    ]));
                    $fields = array_intersect_key($source, array_flip($targetColumns));
                    unset($fields['id']);
                    $fields['serial'] = $entry['info']['serial'];
                    $fields['name'] = $entry['info']['name'];
                    $fields['assets_assetdefinitions_id'] = $definition->getID();
                    $fields['assets_assetmodels_id'] = PluginItcoreinventoryNative::model($entry['info']);
                    $fields['manufacturers_id'] = PluginItcoreinventoryNative::manufacturer($entry['info']);
                    $fields['assets_assettypes_id'] = PluginItcoreinventoryNative::dockType();
                    $fields['custom_fields'] = '{}';
                    // Respect manual edits when returning from an earlier uninstall.
                    $locks = iterator_to_array($DB->request([

                        'FROM' => 'glpi_lockedfields',

                        'WHERE' => ['itemtype' => 'Peripheral', 'items_id' => $oldId],


                    ]));
                    foreach ($locks as $lock) {
                        $field = $lock['field'];
                        if (in_array($field, ['name', 'serial', 'manufacturers_id'], true)) {
                            $fields[$field] = $source[$field];
                        }
                        foreach (
                            [
                            'peripheralmodels_id' => 'assets_assetmodels_id',
                            'peripheraltypes_id' => 'assets_assettypes_id'
                            ] as $legacyField => $nativeField
                        ) {
                            if ($field !== $legacyField) {
                                continue;
                            }
                            if (!(int) $source[$legacyField]) {
                                $fields[$nativeField] = 0;
                                continue;
                            }
                            $legacyClass = $legacyField === 'peripheralmodels_id'
                            ? PeripheralModel::class : \PeripheralType::class;
                            $legacyItem = new $legacyClass();
                            if (!$legacyItem->getFromDB($source[$legacyField])) {
                                throw new RuntimeException('Locked legacy dropdown is missing.');
                            }
                            $nativeClass = $legacyField === 'peripheralmodels_id'
                                ? $definition->getAssetModelClassName()
                            : $definition->getAssetTypeClassName();
                            $nativeItem = new $nativeClass();
                            $criteria = ['name' => $legacyItem->fields['name']];
                            if ($legacyField === 'peripheralmodels_id') {
                                $criteria['product_number'] = $legacyItem->fields['product_number'];
                            }
                            $fields[$nativeField] = $nativeItem->getFromDBByCrit(
                                $criteria + ['assets_assetdefinitions_id' => $definition->getID()]
                            ) ? $nativeItem->getID() : $nativeItem->add($criteria);
                            if (!$fields[$nativeField]) {
                                throw new RuntimeException('Locked legacy dropdown could not be preserved.');
                            }
                        }
                    }
                    if ($found) {
                        $record = reset($found);
                        $target = (int) $record['assets_id'];
                        $native = new $class();
                        if (
                            $record['status'] !== 'reverted' || !$native->getFromDB($target)
                            || !$native->fields['is_deleted']
                        ) {
                            throw new RuntimeException(
                                'Legacy source already has an active native target; review '
                                . 'required'
                            );
                        }
                        $DB->update($class::getTable(), $fields, ['id' => $target]);
                        $expectedTargets[$target] = $fields;
                        $canonicalTargets[$oldId] = $target;
                        continue;
                    }
                    $duplicates = iterator_to_array($DB->request([

                        'SELECT' => ['id'],
                    'FROM' => $class::getTable(),

                        'WHERE' => [

                            'assets_assetdefinitions_id' => $definition->getID(),

                            'serial' => $entry['info']['serial'],
                    'entities_id' => $source['entities_id'],


                        ],


                    ]));
                    if ($duplicates) {
                        throw new RuntimeException('Unmapped native dock already exists; refusing accidental merge');
                    }
                    // Preserve numeric IDs when the shared native asset table has no collision.
                    if (
                        !count(iterator_to_array($DB->request([

                            'SELECT' => ['id'],

                            'FROM' => $class::getTable(),

                            'WHERE' => ['id' => $oldId]

                        ])))
                    ) {
                        $fields['id'] = $oldId;
                    }
                    if (!$DB->insert($class::getTable(), $fields)) {
                        throw new RuntimeException('Native asset insertion failed');
                    }
                    $target = (int)($fields['id'] ?? $DB->insertId());
                    $canonicalTargets[$oldId] = $target;
                    $expectedTargets[$target] = $fields;
                }
                foreach ($sources as $oldId => $entry) {
                    $target = $canonicalTargets[$entry['canonical_id']];
                    $mapping[$oldId] = $target;
                    $old = iterator_to_array($DB->request([

                        'FROM' => PluginItcoreinventoryNative::MAP,

                        'WHERE' => ['peripherals_id' => $oldId]

                    ]));
                    $snapshot = $entry['source'];
                    $snapshot['_itcore_meta'] = [
                    'info' => $entry['info'],
                    'canonical_source_id' => $entry['canonical_id']
                    ];
                    $values = [

                        'peripherals_id' => $oldId,
                    'assets_id' => $target,

                        'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),

                        'status' => 'migrated',
                    'date_mod' => date('Y-m-d H:i:s'),


                    ];
                    if ($old) {
                        $DB->update(
                            PluginItcoreinventoryNative::MAP,
                            $values,
                            ['id' => reset($old)['id']]
                        );
                    } else {
                        $DB->insert(PluginItcoreinventoryNative::MAP, $values);
                    }
                }
                $referenceCount = 0;
                foreach ($matrix as $group) {
                    foreach ($group['rows'] as $row) {
                        $values = [

                            $group['type'] => $class,

                            $group['id'] => $mapping[$row[$group['id']]]

                        ];
                        if (
                            $group['table'] === 'glpi_logs' && $group['type'] === 'itemtype'
                            && (int)$row['id_search_option'] === 61
                        ) {
                            $values['id_search_option'] = 65;
                        }
                        if (
                            $group['table'] === 'glpi_lockedfields'
                            && isset($row['field'])
                        ) {
                            $values['field'] = str_replace(
                                [

                                    'peripheralmodels_id',

                                    'peripheraltypes_id'

                                ],
                                [

                                    'assets_assetmodels_id',

                                    'assets_assettypes_id'

                                ],
                                $row['field']
                            );
                        }
                        if ($group['table'] === 'glpi_lockedfields') {
                            $same = iterator_to_array($DB->request([

                                'FROM' => $group['table'],

                                'WHERE' => [

                                    'itemtype' => $class,

                                    'items_id' => $values[$group['id']],

                                    'field' => $values['field']

                                ]

                            ]));
                            if ($same) {
                                $archiveValues = [

                                    'source_table' => $group['table'],

                                    'source_id' => $row['id'],

                                    'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),

                                    'reason' => 'Duplicate lock retained once on the destination asset'

                                ];
                                $archived = iterator_to_array($DB->request([

                                    'FROM' => PluginItcoreinventoryNative::ARCHIVE,

                                    'WHERE' => [
                                'source_table' => $group['table'],
                                'source_id' => $row['id']
                                    ],


                                ]));
                                if ($archived) {
                                    $DB->update(
                                        PluginItcoreinventoryNative::ARCHIVE,
                                        $archiveValues,
                                        ['id' => reset($archived)['id']]
                                    );
                                } else {
                                    $DB->insert(PluginItcoreinventoryNative::ARCHIVE, $archiveValues);
                                }
                                $DB->delete($group['table'], ['id' => $row['id']]);
                                $referenceCount++;
                                continue;
                            }
                        }
                        if (!$DB->update($group['table'], $values, ['id' => $row['id']])) {
                            throw new RuntimeException('Reference migration failed: '
                            . $group['table']);
                        }
                        $referenceCount++;
                    }
                }
                $relationTable = \Glpi\Asset\Asset_PeripheralAsset::getTable();
                $originalConnections = iterator_to_array($DB->request([

                    'FROM' => $relationTable,

                    'WHERE' => [
                'itemtype_peripheral' => 'Peripheral',
                'items_id_peripheral' => $ids,
                'is_deleted' => 0
                    ],


                ]));
                $linkCount = 0;
                foreach (
                    $DB->request([

                        'FROM' => $relationTable,

                        'WHERE' => [

                            'itemtype_peripheral' => 'Peripheral',

                            'items_id_peripheral' => $ids,

                            'is_deleted' => 0

                        ]

                    ]) as $row
                ) {
                    if ($row['itemtype_asset'] !== 'Computer') {
                        throw new RuntimeException('Dock connection to a non-computer asset requires manual migration');
                    }
                    $computer = new Computer();
                    $source = $sources[$row['items_id_peripheral']]['merged'];
                    if (
                        !$computer->getFromDB($row['items_id_asset'])
                        || (int) $computer->fields['entities_id'] !== (int) $source['entities_id']
                    ) {
                        throw new RuntimeException('Missing computer or cross-entity dock link requires review');
                    }
                    $values = [

                        'assets_id' => $mapping[$row['items_id_peripheral']],

                        'computers_id' => $row['items_id_asset'],

                        'is_deleted' => 0,

                        'is_dynamic' => $row['is_dynamic'],

                        'last_seen' => date('Y-m-d H:i:s')

                    ];
                    $existing = iterator_to_array($DB->request([

                        'FROM' => PluginItcoreinventoryNative::LINKS,

                        'WHERE' => [

                            'assets_id' => $values['assets_id'],

                            'computers_id' => $values['computers_id']

                        ]

                    ]));
                    if (!$existing) {
                        $link = new PluginItcoreinventoryDockConnection();
                        if (!$link->add($values)) {
                            throw new RuntimeException('Dock connection migration failed');
                        }
                        $linkCount++;
                    }
                    $DB->update($relationTable, ['is_deleted' => 1], ['id' => $row['id']]);
                }
                // Verify every destination and key business field before hiding the source.
                foreach ($canonicalTargets as $oldId => $target) {
                    $asset = new $class();
                    if (!$asset->getFromDB($target)) {
                        throw new RuntimeException('Missing destination');
                    }
                    foreach (
                        [

                            'serial',

                            'name',

                            'users_id',

                            'locations_id',

                            'entities_id',

                            'otherserial',

                            'comment',

                            'date_creation'

                        ] as $field
                    ) {
                        if (
                            (string)$asset->fields[$field] !== (string) $expectedTargets[$target][$field]
                        ) {
                            throw new RuntimeException('Destination mismatch: '
                            . $field);
                        }
                    }
                }
                foreach (self::referenceMatrix($ids) as $remaining) {
                    throw new RuntimeException('Unmigrated reference remains: '
                    . $remaining['table']);
                }
                foreach ($ids as $id) {
                    $DB->update('glpi_peripherals', ['is_deleted' => 1], ['id' => $id]);
                }
                PluginItcoreinventoryNative::seedIdentities();
                Config::setConfigurationValues('plugin:itcoreinventory-native', [

                    'migration_blocked' => '0',

                    'migration_error' => '',

                    'ready' => '1',

                    'definition_id' => (string)$definition->getID(),

                    'migration_date' => date('Y-m-d H:i:s')

                ]);
                $baseline = iterator_to_array($DB->request([

                    'FROM' => $class::getTable(),
                'WHERE' => ['id' => array_values($canonicalTargets)],


                ]));
                $DB->insert(PluginItcoreinventoryNative::JOURNAL, [

                    'phase' => 'applied',

                    'payload' => json_encode([

                        'sources' => $sources,
                'mapping' => $mapping,

                        'canonicals' => array_flip($canonicalTargets),

                        'references' => $matrix,
                'connections' => $originalConnections,

                        'baseline' => $baseline,


                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),

                    'date_mod' => date('Y-m-d H:i:s'),


                ]);
                $DB->commit();
                return [

                    'status' => 'migrated',

                    'source_records' => count($sources),

                    'native_assets' => count($canonicalTargets),

                    'reference_rows' => $referenceCount,

                    'connections' => $linkCount,

                    'mapping' => $mapping

                ];
            } catch (Throwable$e) {
                $DB->rollBack();
                throw $e;
            }
        } finally {
            PluginItcoreinventoryNative::unlock();
        }
    }
}
