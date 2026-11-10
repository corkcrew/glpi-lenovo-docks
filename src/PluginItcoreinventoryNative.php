<?php

/** SPDX-License-Identifier: MIT */

namespace GlpiPlugin\Itcoreinventory;

use CommonGLPI;
use Computer;
use Config;
use Lockedfield;
use Manufacturer;
use Peripheral;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use Toolbox;

class PluginItcoreinventoryNative extends CommonGLPI
{
    public const ASSET = 'Glpi\\CustomAsset\\DockStationAsset';
    public const LINKS = 'glpi_plugin_itcoreinventory_docklinks';
    public const MAP = 'glpi_plugin_itcoreinventory_dockmigrations';
    public const QUEUE = 'glpi_plugin_itcoreinventory_dockqueue';
    public const ARCHIVE = 'glpi_plugin_itcoreinventory_dockrefarchive';
    public const IDENTITIES = 'glpi_plugin_itcoreinventory_dockidentities';
    public const JOURNAL = 'glpi_plugin_itcoreinventory_dockjournal';
    public static function getTypeName($nb = 0)
    {
        return 'ITCORE: инвентаризация док-станций';
    }
    public static function cronInfo($name)
    {
        return ['description' => 'Повторная обработка USB-доков и синхронизация местоположения'];
    }
    public static function ready(): bool
    {
        return (Config::getConfigurationValues('plugin:itcoreinventory-native')['ready'] ?? '0') === '1'
        && class_exists(self::ASSET);
    }
    public static function installSchema(): void
    {
        global $DB;
        $definitions = [
            self::JOURNAL => [
                '`id` int unsigned NOT NULL AUTO_INCREMENT',
                '`phase` varchar(32) NOT NULL',
                '`payload` longtext NOT NULL',
                '`date_mod` timestamp NULL DEFAULT NULL',
                'PRIMARY KEY (`id`)',
            ],
            self::LINKS => [
                '`id` int unsigned NOT NULL AUTO_INCREMENT',
                '`assets_id` int unsigned NOT NULL',
                '`computers_id` int unsigned NOT NULL',
                '`is_dynamic` tinyint NOT NULL DEFAULT 1',
                '`is_deleted` tinyint NOT NULL DEFAULT 0',
                '`last_seen` timestamp NULL DEFAULT NULL',
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `asset_pc` (`assets_id`,`computers_id`)',
            ],
            self::MAP => [
                '`id` int unsigned NOT NULL AUTO_INCREMENT',
                '`peripherals_id` int unsigned NOT NULL',
                '`assets_id` int unsigned NOT NULL',
                '`snapshot` longtext NOT NULL',
                '`status` varchar(32) NOT NULL',
                '`date_mod` timestamp NULL DEFAULT NULL',
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `source_id` (`peripherals_id`)',
                'KEY `target_id` (`assets_id`)',
            ],
            self::QUEUE => [
                '`id` int unsigned NOT NULL AUTO_INCREMENT',
                '`deviceid` varchar(255) NOT NULL',
                '`payload` mediumtext NOT NULL',
                '`attempts` int unsigned NOT NULL DEFAULT 0',
                '`last_error` text NULL',
                '`date_mod` timestamp NULL DEFAULT NULL',
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `device` (`deviceid`)',
            ],
            self::ARCHIVE => [
                '`id` int unsigned NOT NULL AUTO_INCREMENT',
                '`source_table` varchar(255) NOT NULL',
                '`source_id` int unsigned NOT NULL',
                '`payload` longtext NOT NULL',
                '`reason` varchar(255) NOT NULL',
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `source` (`source_table`,`source_id`)',
            ],
            self::IDENTITIES => [
                '`id` int unsigned NOT NULL AUTO_INCREMENT',
                '`physical_serial` varchar(255) NOT NULL',
                "`vendor_key` varchar(64) NOT NULL DEFAULT ''",
                '`entities_id` int unsigned NOT NULL',
                '`assets_id` int unsigned NOT NULL',
                'PRIMARY KEY (`id`)',
                'UNIQUE KEY `physical_entity` (`physical_serial`,`entities_id`,`vendor_key`)',
                'KEY `asset_id` (`assets_id`)',
            ],
        ];
        foreach ($definitions as $table => $columns) {
            $DB->doQuery(
                'CREATE TABLE IF NOT EXISTS `' . $table . '` (' . implode(', ', $columns)
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
    }
    public static function upgradeIdentities(): void
    {
        global $DB;
        if (!$DB->fieldExists(self::IDENTITIES, 'vendor_key')) {
            $DB->doQuery('ALTER TABLE `' . self::IDENTITIES . "` ADD `vendor_key` varchar(64) NOT NULL DEFAULT ''");
        }
        $columns = [];
        foreach ($DB->doQuery('SHOW INDEX FROM `' . self::IDENTITIES . "` WHERE Key_name='physical_entity'") as $row) {
            $columns[(int) $row['Seq_in_index']] = $row['Column_name'];
        }
        ksort($columns);
        if (array_values($columns) !== ['physical_serial', 'entities_id', 'vendor_key']) {
            if ($columns) {
                $DB->doQuery('ALTER TABLE `' . self::IDENTITIES . '` DROP INDEX `physical_entity`');
            }
            $DB->doQuery('ALTER TABLE `' . self::IDENTITIES . '` ADD UNIQUE KEY `physical_entity` '
                . '(`physical_serial`,`entities_id`,`vendor_key`)');
        }
        foreach ($DB->request(['FROM' => self::IDENTITIES, 'WHERE' => ['vendor_key' => '']]) as $row) {
            $DB->update(
                self::IDENTITIES,
                ['vendor_key' => self::vendorKey((int) $row['assets_id'])],
                ['id' => $row['id']]
            );
        }
    }

    public static function vendorKey(int $assetId): string
    {
        global $DB;
        $known = iterator_to_array($DB->request([
            'FROM' => self::IDENTITIES, 'WHERE' => ['assets_id' => $assetId, 'vendor_key' => ['<>', '']],
        ]));
        if ($known) {
            return (string) reset($known)['vendor_key'];
        }
        $class = self::ASSET;
        $asset = new $class();
        if (!$asset->getFromDB($assetId)) {
            throw new RuntimeException('Identity points to a missing dock.');
        }
        $maker = new Manufacturer();
        if ($maker->getFromDB($asset->fields['manufacturers_id'])) {
            foreach (plugin_itcoreinventory_settings()['manufacturers'] as $vendor) {
                if (strcasecmp($vendor['name'], $maker->fields['name']) === 0) {
                    return $vendor['id'];
                }
            }
        }
        return 'legacy-' . (int) $asset->fields['manufacturers_id'];
    }

    public static function lock(): bool
    {
        global $DB;
        $key = 'itcore_native_dock_' . $DB->dbdefault;
        $r = $DB->doQuery(
            "SELECT GET_LOCK('" . $DB->escape($key) . "', 2) AS acquired"
        );
        return (int)$DB->fetchAssoc($r)['acquired'] === 1;
    }
    public static function unlock(): void
    {
        global $DB;
        $DB->doQuery("DO RELEASE_LOCK('" . $DB->escape('itcore_native_dock_'
        . $DB->dbdefault) . "')");
    }
    public static function createDefinition(): \Glpi\Asset\AssetDefinition
    {
        global $DB;
        $d = new \Glpi\Asset\AssetDefinition();
        if ($d->getFromDBBySystemName('DockStation')) {
            $owned = Config::getConfigurationValues('plugin:itcoreinventory-native');
            if ((int)($owned['definition_id'] ?? 0) !== (int)$d->getID()) {
                throw new RuntimeException(('DockStation definition already belongs to another '
                .
                'configuration; automatic reuse refused'));
            }
            if (!$d->fields['is_active']) {
                $d->update(['id' => $d->getID(), 'is_active' => 1]);
            }
            return $d;
        }
        $profiles = [];
        foreach (
            $DB->request([
                'FROM' => 'glpi_profilerights',
                'WHERE' => ['name' => 'peripheral']
            ]) as $r
        ) {
            $profiles[(int)$r['profiles_id']] = (int)$r['rights'];
        }
        $capacities = [];
        foreach (
            [
                'HasHistoryCapacity',
                'HasDocumentsCapacity',
                'HasInfocomCapacity',
                'HasNotepadCapacity',
                'HasContractsCapacity',
                'HasLinksCapacity',
                'HasCertificatesCapacity',
                'HasKnowbaseCapacity',
                'HasImpactCapacity',
                'HasNetworkPortCapacity',
                'AllowedInGlobalSearchCapacity',
                'IsProjectAssetCapacity',
                'IsInventoriableCapacity'
            ] as $c
        ) {
            $capacities[] = ['name' => 'Glpi\\Asset\\Capacity\\' . $c];
        }
        if (
            !$d->add([
                'system_name' => 'DockStation',
                'label' => 'Док-станции',
                'icon' => 'ti ti-device-usb',
                'is_active' => 1,
                'profiles' => $profiles,
                'capacities' => $capacities
            ])
        ) {
            throw new RuntimeException('Cannot create native dock definition');
        }
        return $d;
    }
    public static function model(array $info): int
    {
        $class = self::ASSET;
        $definition = $class::getDefinition();
        $modelClass = $definition->getAssetModelClassName();
        $model = new $modelClass();
        if (
            $model->getFromDBByCrit([
                'assets_assetdefinitions_id' => $definition->getID(),
                'product_number' => $info['model'],
                'name' => $info['name'] . ' (' . $info['model'] . ')'
            ])
        ) {
            return (int)$model->getID();
        }
        $id = $model->add([
            'name' => $info['name'] . ' (' . $info['model'] . ')',
            'product_number' => $info['model']
        ]);
        if (!$id) {
            throw new RuntimeException('Native dock model creation failed');
        }
        return (int)$id;
    }
    public static function manufacturer(?array $info = null): int
    {
        $maker = new Manufacturer();
        $name = $info['manufacturer'] ?? (plugin_itcoreinventory_settings()['manufacturers'][0]['name'] ?? 'LENOVO');
        if ($maker->getFromDBByCrit(['name' => $name])) {
            return (int)$maker->getID();
        }
        $id = $maker->add(['name' => $name]);
        if (!$id) {
            throw new RuntimeException('Manufacturer creation failed');
        }
        return (int)$id;
    }
    public static function dockType(): int
    {
        $class = self::ASSET;
        $definition = $class::getDefinition();
        $typeClass = $definition->getAssetTypeClassName();
        $type = new $typeClass();
        $name = plugin_itcoreinventory_settings()['type_name'];
        if (
            $type->getFromDBByCrit([
                'assets_assetdefinitions_id' => $definition->getID(),
                'name' => $name
            ])
        ) {
            return (int)$type->getID();
        }
        $id = $type->add(['name' => $name]);
        if (!$id) {
            throw new RuntimeException('Native dock type creation failed');
        }
        return (int)$id;
    }
    public static function rememberIdentity(string $serial, int $entity, int $assetId, ?string $vendor = null): void
    {
        global $DB;
        $vendor ??= self::vendorKey($assetId);
        $found = iterator_to_array($DB->request([
            'FROM' => self::IDENTITIES,
            'WHERE' => ['physical_serial' => $serial,'entities_id' => $entity, 'vendor_key' => $vendor]
        ]));
        if ($found) {
            if ((int)reset($found)['assets_id'] !== $assetId) {
                throw new RuntimeException('Physical dock identity collision');
            }
            return;
        }
        $DB->insert(self::IDENTITIES, [
            'physical_serial' => $serial,
            'vendor_key' => $vendor,
            'entities_id' => $entity,
            'assets_id' => $assetId
        ]);
    }
    public static function seedIdentities(): void
    {
        global $DB;
        $class = self::ASSET;
        if (!class_exists($class)) {
            return;
        }
        foreach ($DB->request(['FROM' => self::MAP]) as $map) {
            $source = json_decode($map['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $serial = (string)$source['serial'];
            $asset = new $class();
            if (!$asset->getFromDB($map['assets_id'])) {
                throw new RuntimeException('Mapped dock asset is missing');
            }
            self::rememberIdentity(
                (string) $asset->fields['serial'],
                (int) $asset->fields['entities_id'],
                (int) $asset->getID()
            );
            self::rememberIdentity(
                $serial,
                (int)$asset->fields['entities_id'],
                (int)$asset->getID()
            );
        }
    }
    public static function upsert(array $info, Computer $pc): int
    {
        global $DB;
        $class = self::ASSET;
        $definition = $class::getDefinition();
        if (!isset($info['manufacturer_key'])) {
            $info = plugin_itcoreinventory_info(
                $info['vid'] ?? '',
                $info['pid'] ?? '',
                $info['raw'] ?? $info['serial']
            );
            if (!$info) {
                throw new RuntimeException('Queued manufacturer profile is no longer configured.');
            }
        }
        $serialAliases = array_values(
            array_unique([$info['serial'],$info['raw'] ?? $info['serial']])
        );
        $identityRows = iterator_to_array($DB->request([
            'FROM' => self::IDENTITIES,
            'WHERE' => ['physical_serial' => $serialAliases, 'vendor_key' => $info['manufacturer_key']]
        ]));
        $identityAssets = [];
        foreach ($identityRows as $identity) {
            $known = new $class();
            if (!$known->getFromDB($identity['assets_id'])) {
                throw new RuntimeException('Physical identity points to a missing dock');
            }
            if ((int)$known->fields['entities_id'] === (int)$pc->fields['entities_id']) {
                $identityAssets[$known->getID()] = $known->fields;
            }
        }
        if (count($identityAssets) > 1 || ($identityRows && !$identityAssets)) {
            throw new RuntimeException('Physical dock belongs to another entity or is ambiguous');
        }
        $rows = $identityAssets ? array_values($identityAssets) : iterator_to_array($DB->request([
            'FROM' => $class::getTable(),
            'WHERE' => [
                'assets_assetdefinitions_id' => $definition->getID(),
                'entities_id' => $pc->fields['entities_id'],
                'serial' => $serialAliases,
                'manufacturers_id' => self::manufacturer($info)
            ]
        ]));
        if (count($rows) > 1) {
            throw new RuntimeException('Duplicate native dock serial in entity');
        }
        $asset = new $class();
        $values = [
            'name' => $info['name'],
            'serial' => $info['serial'],
            'assets_assetmodels_id' => self::model($info),
            'assets_assettypes_id' => self::dockType(),
            'manufacturers_id' => self::manufacturer($info),
            'last_inventory_update' => date('Y-m-d H:i:s'),
            'is_dynamic' => 1,
            'autoupdatesystems_id' => (int)($pc->fields['autoupdatesystems_id'] ?? 0)
        ];
        if (!empty(plugin_itcoreinventory_settings()['add_marker'])) {
            $previous = $rows ? (string)(reset($rows)['comment'] ?? '')
            : '';
            $previous = preg_replace('/^\[ITCORE USB[^\r\n]*\](?:\r?\n)?/m', '', $previous);
            $marker = '[ITCORE USB VID=' . ($info['vid'] ?? '') . ' PID='
            . ($info['pid'] ?? '')
            . ' serial=' . ($info['raw'] ?? $info['serial']) . ' part='
            . ($info['part'] ?? '') . ']';
            $values['comment'] = trim($previous) . ($previous !== ''
            ? "\n" : '') . $marker;
        }
        if (!$rows) {
            $legacy = iterator_to_array($DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_peripherals',
                'WHERE' => [
                    'entities_id' => $pc->fields['entities_id'],
                    'serial' => $serialAliases,
                    'manufacturers_id' => self::manufacturer($info),
                    'is_deleted' => 0,
                    'is_template' => 0
                ]
            ]));
            if ($legacy) {
                throw new RuntimeException(
                    'An existing Peripheral must be migrated before creating its native dock asset'
                );
            }
            $values += [
                'entities_id' => $pc->fields['entities_id'],
                'users_id' => $pc->fields['users_id'],
                'locations_id' => $pc->fields['locations_id'],
            ];
            $id = $asset->add($values);
            if (!$id) {
                throw new RuntimeException('Native dock creation failed');
            }
        } else {
            $row = reset($rows);
            if ($row['is_deleted']) {
                throw new RuntimeException('Dock is in trash; automatic restoration refused');
            }
            $asset->getFromDB($row['id']);
            $locks = (new Lockedfield())->getLockedNames($class, $asset->getID());
            foreach ($locks as $field) {
                unset($values[$field]);
            }
            if (!$asset->update(['id' => $asset->getID(),'_auto' => 1] + $values)) {
                throw new RuntimeException('Native dock update failed');
            }
            $id = $asset->getID();
        }
        self::rememberIdentity($info['serial'], (int)$pc->fields['entities_id'], (int)$id, $info['manufacturer_key']);
        if (!empty($info['raw'])) {
            self::rememberIdentity($info['raw'], (int)$pc->fields['entities_id'], (int)$id, $info['manufacturer_key']);
        }
        $existing = iterator_to_array($DB->request([
            'FROM' => self::LINKS,
            'WHERE' => ['assets_id' => $id,'computers_id' => $pc->getID()]
        ]));
        if ($existing) {
            $row = reset($existing);
            $DB->update(
                self::LINKS,
                [
                    'is_deleted' => 0,
                    'last_seen' => date('Y-m-d H:i:s')
                ],
                ['id' => $row['id']]
            );
        } else {
            $link = new PluginItcoreinventoryDockConnection();
            if (
                !$link->add([
                    'assets_id' => $id,
                    'computers_id' => $pc->getID(),
                    'is_dynamic' => 1,
                    'is_deleted' => 0,
                    'last_seen' => date('Y-m-d H:i:s')
                ])
            ) {
                throw new RuntimeException('Dock connection creation failed');
            }
        }
        return (int)$id;
    }
    public static function enqueue(string $deviceid, array $infos, bool $complete): void
    {
        global $DB;
        if (
            $deviceid === '' || strlen($deviceid) > 255 || preg_match('/[\x00-\x1f]/', $deviceid)
            || count($infos) > 100
        ) {
            throw new RuntimeException('Invalid inventory identity or dock count');
        }
        $payload = json_encode(
            ['infos' => $infos,
                'complete' => $complete],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        $values = [
            'deviceid' => $deviceid,
            'payload' => $payload,
            'attempts' => 0,
            'last_error' => null,
            'date_mod' => date('Y-m-d H:i:s')
        ];
        $old = iterator_to_array($DB->request([
            'FROM' => self::QUEUE,
            'WHERE' => ['deviceid' => $deviceid],
            'LIMIT' => 1
        ]));
        if ($old) {
            $DB->update(self::QUEUE, $values, ['id' => reset($old)['id']]);
        } else {
            try {
                $DB->insert(self::QUEUE, $values);
            } catch (Throwable$e) {
                $DB->update(self::QUEUE, $values, ['deviceid' => $deviceid]);
            }
        }
    }
    public static function processQueue(): int
    {
        global $DB;
        if (
            !self::ready()
            || (Config::getConfigurationValues('plugin:itcoreinventory-native')['migration_blocked'] ?? '0') === '1'
            || !self::lock()
        ) {
            return 0;
        }
        $count = 0;
        try {
            foreach (
                $DB->request([
                    'FROM' => self::QUEUE,
                    'ORDER' => ['attempts','id'],
                    'LIMIT' => 100
                ]) as $job
            ) {
                try {
                    $agents = iterator_to_array($DB->request([
                        'FROM' => 'glpi_agents',
                        'WHERE' => ['deviceid' => $job['deviceid'],'itemtype' => 'Computer']
                    ]));
                    if (count($agents) !== 1) {
                        throw new RuntimeException('Agent identity is absent or ambiguous');
                    }
                    $agent = reset($agents);
                    $pc = new Computer();
                    if (!$pc->getFromDB($agent['items_id']) || $pc->fields['is_deleted']) {
                        throw new RuntimeException('Inventory computer is unavailable');
                    }
                    $data = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
                    $seen = [];
                    foreach ($data['infos'] as $info) {
                        $seen[] = self::upsert($info, $pc);
                    }
                    if ($data['complete']) {
                        foreach (
                            $DB->request([
                                'FROM' => self::LINKS,
                                'WHERE' => [
                                    'computers_id' => $pc->getID(),
                                    'is_dynamic' => 1,
                                    'is_deleted' => 0
                                ]
                            ]) as $link
                        ) {
                            if (!in_array((int)$link['assets_id'], $seen, true)) {
                                $DB->update(self::LINKS, ['is_deleted' => 1], ['id' => $link['id']]);
                            }
                        }
                    }
                    // Only remove the processed revision; a newer concurrent report remains queued.
                    $DB->delete(self::QUEUE, [
                        'id' => $job['id'],
                        'payload' => $job['payload'],
                        'date_mod' => $job['date_mod']
                    ]);
                    $count++;
                } catch (Throwable$e) {
                    $DB->update(self::QUEUE, [
                        'attempts' => (int)$job['attempts'] + 1,
                        'last_error' => $e->getMessage()
                    ], ['id' => $job['id'],'payload' => $job['payload']]);
                    Toolbox::logInFile(
                        'itcoreinventory-native',
                        json_encode(['job' => $job['id'],'error' => $e->getMessage()])
                        . "\n"
                    );
                }
            }
            self::syncLocations();
        } finally {
            self::unlock();
        }
        return $count;
    }
    public static function syncLocations(): int
    {
        global $DB;
        if (!self::ready() || empty(plugin_itcoreinventory_settings()['dock_location'])) {
            return 0;
        }
        $count = 0;
        $class = self::ASSET;
        foreach (
            $DB->request([
                'FROM' => $class::getTable(),
                'WHERE' => [
                    'assets_assetdefinitions_id' => $class::getDefinition()->getID(),
                    'is_deleted' => 0
                ]
            ]) as $row
        ) {
            $links = iterator_to_array($DB->request([
                'FROM' => self::LINKS,
                'WHERE' => ['assets_id' => $row['id'],'is_deleted' => 0]
            ]));
            if (count($links) !== 1) {
                continue;
            }
            $pc = new Computer();
            if (!$pc->getFromDB(reset($links)['computers_id']) || $pc->fields['is_deleted']) {
                continue;
            }
            $locks = (new Lockedfield())->getLockedNames($class, $row['id']);
            $values = [];
            foreach (['locations_id','users_id'] as $field) {
                if (
                    !empty($pc->fields[$field]) && !in_array($field, $locks, true)
                    && (int)$row[$field] !== (int)$pc->fields[$field]
                ) {
                    $values[$field] = $pc->fields[$field];
                }
            }
            if ($values) {
                $asset = new $class();
                if ($asset->getFromDB($row['id']) && $asset->update(['id' => $row['id'],'_auto' => 1] + $values)) {
                    $count++;
                }
            }
        }
        return $count;
    }
    public static function auditSerials(bool $apply = false): array
    {
        global $DB;
        $out = [];
        if (!self::ready()) {
            return $out;
        }
        $class = self::ASSET;
        $cfg = plugin_itcoreinventory_settings();
        $catalog = plugin_itcoreinventory_catalog();
        if ($apply && !self::lock()) {
            return [['status' => 'busy']];
        }
        try {
            foreach (
                $DB->request([
                    'FROM' => $class::getTable(),
                    'WHERE' => [
                        'assets_assetdefinitions_id' => $class::getDefinition()->getID(),
                        'is_deleted' => 0,
                        'is_template' => 0
                    ]
                ]) as $row
            ) {
                $result = ['id' => (int)$row['id'],'serial' => $row['serial']];
                $modelClass = $class::getDefinition()->getAssetModelClassName();
                $model = new $modelClass();
                if (!$model->getFromDB($row['assets_assetmodels_id'])) {
                    $out[] = $result + ['status' => 'model_missing'];
                    continue;
                }
                $code = (string)$model->fields['product_number'];
                $candidates = [];
                $vendorKey = self::vendorKey((int) $row['id']);
                foreach ($catalog as $entry) {
                    if ($entry['manufacturer_key'] === $vendorKey && strcasecmp($entry['model'], $code) === 0) {
                        $info = plugin_itcoreinventory_info($entry['vid'], $entry['pid'], (string) $row['serial']);
                        if ($info) {
                            $candidates[$info['serial'] . '|' . $info['serial_status']] = $info;
                        }
                    }
                }
                if (count($candidates) !== 1) {
                    $out[] = $result + ['status' => 'unknown_or_ambiguous_model_rule'];
                    continue;
                }
                $n = reset($candidates);
                if ($n['serial_status'] !== 'normalized' || $n['serial'] === $row['serial']) {
                    $out[] = $result + ['status' => $n['serial_status'] === 'plain'
                    ? 'unchanged' : $n['serial_status']];
                    continue;
                }
                $result['proposed_serial'] = $n['serial'];
                if (
                    in_array(
                        'serial',
                        (new Lockedfield())->getLockedNames($class, $row['id']),
                        true
                    )
                ) {
                    $out[] = $result + ['status' => 'locked'];
                    continue;
                }
                $same = iterator_to_array($DB->request([
                    'SELECT' => ['id'],
                    'FROM' => $class::getTable(),
                    'WHERE' => [
                        'assets_assetdefinitions_id' => $class::getDefinition()->getID(),
                        'entities_id' => $row['entities_id'],
                        'serial' => $n['serial'],
                        'manufacturers_id' => $row['manufacturers_id'],
                        'id' => ['<>',$row['id']]
                    ]
                ]));
                $identity = iterator_to_array($DB->request([
                    'FROM' => self::IDENTITIES,
                    'WHERE' => [
                        'physical_serial' => $n['serial'],
                        'vendor_key' => $vendorKey,
                        'entities_id' => $row['entities_id'],
                        'assets_id' => ['<>',$row['id']]
                    ]
                ]));
                if ($same || $identity) {
                    $out[] = $result + ['status' => 'collision'];
                    continue;
                }
                if (!$apply) {
                    $out[] = $result + ['status' => 'would_normalize'];
                    continue;
                }
                $DB->beginTransaction();
                try {
                    $asset = new $class();
                    $asset->getFromDB($row['id']);
                    if (
                        $asset->fields['serial'] !== $row['serial']
                        || in_array(
                            'serial',
                            (new Lockedfield())->getLockedNames($class, $row['id']),
                            true
                        )
                    ) {
                        throw new RuntimeException('Dock changed during serial audit');
                    }
                    self::rememberIdentity($row['serial'], (int)$row['entities_id'], (int)$row['id']);
                    self::rememberIdentity($n['serial'], (int)$row['entities_id'], (int)$row['id']);
                    if (!$asset->update(['id' => $row['id'],'serial' => $n['serial'],'_auto' => 1])) {
                        throw new RuntimeException('Serial update failed');
                    }
                    $DB->commit();
                    $out[] = $result + ['status' => 'normalized'];
                } catch (Throwable $e) {
                    $DB->rollBack();
                    $out[] = $result + ['status' => 'error','error' => $e->getMessage()];
                }
            }
            if ($apply) {
                Config::setConfigurationValues('plugin:itcoreinventory-native', [
                    'serial_audit' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'serial_audit_date' => date('Y-m-d H:i:s')
                ]);
            }
        } finally {
            if ($apply) {
                self::unlock();
            }
        }
        return $out;
    }
    public static function cronDockInventory($task = null): int
    {
        if (self::ready() && !empty(plugin_itcoreinventory_settings()['migrate_existing'])) {
            try {
                PluginItcoreinventoryMigration::run();
            } catch (Throwable $e) {
                Config::setConfigurationValues('plugin:itcoreinventory-native', [
                    'migration_blocked' => '1',
                    'migration_error' => $e->getMessage()
                ]);
                Toolbox::logInFile(
                    'itcoreinventory-native',
                    'Перенос устройств requires review: ' . $e->getMessage()
                    . "\n"
                );
            }
        }
        $n = self::processQueue();
        foreach (self::auditSerials(true) as $r) {
            if (($r['status'] ?? '') === 'normalized') {
                $n++;
            }
        }
        if (self::ready() && self::lock()) {
            try {
                $n += self::syncLocations();
            } finally {
                self::unlock();
            }
        }
        if ($task) {
            $task->addVolume($n);
        }
        return self::ready() ? 1 : 0;
    }
}
