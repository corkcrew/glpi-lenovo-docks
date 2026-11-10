<?php

/** SPDX-License-Identifier: MIT */

namespace GlpiPlugin\Itcoreinventory;

use Config;
use PeripheralModel;
use PeripheralType;
use RuntimeException;
use Throwable;

/** Transactional return to core Peripheral assets; no global database restoration. */
final class PluginItcoreinventoryUndo
{
    private static function rows(string $table, array $where = []): array
    {
        global $DB;
        return array_values(
            iterator_to_array($DB->request(['FROM' => $table, 'WHERE' => $where]))
        );
    }

    private static function columns(string $table): array
    {
        global $DB;
        $fields = [];
        foreach ($DB->doQuery('SHOW COLUMNS FROM `' . $table . '`') as $row) {
            $fields[] = $row['Field'];
        }
        return $fields;
    }

    private static function state(): array
    {
        $configuration = Config::getConfigurationValues('plugin:itcoreinventory-native');
        if (($configuration['uninstall_reverted'] ?? '0') === '1') {
            return ['done' => true];
        }
        $definitionId = (int) ($configuration['definition_id'] ?? 0);
        if (!$definitionId) {
            return ['done' => true];
        }
        if (self::rows(PluginItcoreinventoryNative::QUEUE)) {
            throw new RuntimeException('Pending dock reports must be processed or reviewed before removal.');
        }
        $assets = [];
        foreach (
            self::rows(
                'glpi_assets_assets',
                ['assets_assetdefinitions_id' => $definitionId]
            ) as $row
        ) {
            if (
                json_decode(
                    $row['custom_fields'] ?: '{}',
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                )
            ) {
                throw new RuntimeException(
                    'Custom native field values require an explicit export or '
                    . 'mapping before removal.'
                );
            }
            $assets[$row['id']] = $row;
        }
        $sources = [];
        $canonicals = [];
        $baseline = [];
        $origins = [];
        $connections = [];
        $originalLocks = [];
        $mapping = [];
        $journalIds = [];
        foreach (
            self::rows(
                PluginItcoreinventoryNative::JOURNAL,
                ['phase' => 'applied']
            ) as $journal
        ) {
            $payload = json_decode($journal['payload'], true, 512, JSON_THROW_ON_ERROR);
            $journalIds[] = $journal['id'];
            foreach ($payload['sources'] as $id => $entry) {
                $sources[$id] = $entry;
                $mapping[$id] = (int) $payload['mapping'][$id];
            }
            foreach ($payload['canonicals'] as $assetId => $sourceId) {
                $canonicals[$assetId] = (int) $sourceId;
            }
            foreach ($payload['baseline'] as $row) {
                $baseline[$row['id']] = $row;
            }
            foreach ($payload['references'] as $group) {
                foreach ($group['rows'] as $row) {
                    $key = $group['table'] . ':' . $row['id'] . ':'
                    . $group['type'];
                    $origins[$key] = [

                        'source_id' => (int) $row[$group['id']],

                        'asset_id' => (int) $payload['mapping'][$row[$group['id']]],


                    ];
                    if ($group['table'] === 'glpi_lockedfields') {
                        $originalLocks[] = ['row' => $row, 'asset_id' => $origins[$key]['asset_id']];
                    }
                }
            }
            foreach ($payload['connections'] as $row) {
                $connections[$row['id']] = $row;
            }
        }
        foreach (self::rows(PluginItcoreinventoryNative::MAP, ['status' => 'migrated']) as $row) {
            if (!isset($sources[$row['peripherals_id']])) {
                throw new RuntimeException(
                    'This dock was migrated by a version without a reverse journal. '
                    . ('Automatic exact restoration is unavailable; ' .
                    'preserve the backup and perform a reviewed ' .
                    'return.')
                );
            }
        }
        foreach ($sources as $id => $entry) {
            if (!isset($assets[$mapping[$id]])) {
                throw new RuntimeException('A migrated native dock was purged; automatic restoration requires review.');
            }
            $existing = self::rows('glpi_peripherals', ['id' => (int) $id]);
            if (!$existing) {
                continue;
            }
            $current = reset($existing);
            if (!$current['is_deleted']) {
                throw new RuntimeException('An archived source was manually restored; removal will not overwrite it.');
            }
            foreach (
                [
                'name',
                'serial',
                'comment',
                'users_id',
                'locations_id',
                'entities_id',
                'otherserial'
                ] as $field
            ) {
                if ((string) $current[$field] !== (string) $entry['source'][$field]) {
                    throw new RuntimeException('An archived source changed: '
                    . $field . '; resolve before removal.');
                }
            }
        }
        $references = $assets
            ? PluginItcoreinventoryMigration::referenceMatrix(array_keys($assets), PluginItcoreinventoryNative::ASSET)
            : [];
        foreach ($references as $group) {
            if ($group['table'] === \Glpi\Asset\Asset_PeripheralAsset::getTable()) {
                throw new RuntimeException('Additional native asset connections require an explicit return mapping.');
            }
            if (str_starts_with($group['table'], 'glpi_plugin_')) {
                throw new RuntimeException(
                    'Another plugin references native docks; review '
                    . 'compatibility before removal.'
                );
            }
        }
        return compact(
            'definitionId',
            'assets',
            'sources',
            'canonicals',
            'baseline',
            'origins',
            'connections',
            'originalLocks',
            'mapping',
            'journalIds',
            'references'
        ) + ['done' => false];
    }

    public static function plan(): array
    {
        $state = self::state();
        if ($state['done']) {
            return ['status' => 'already_returned'];
        }
        return [

            'status' => 'ready',

            'original_cards' => count($state['sources']),

            'new_cards' => count(array_diff_key($state['assets'], $state['canonicals'])),

            'reference_groups' => count($state['references']),

            'note' => ('Current changes and new docks are retained; ' .
        'unchanged original cards recover original IDs ' .
        'and fields.'),


        ];
    }

    private static function legacyModel(int $nativeId, int $definitionId): int
    {
        if (!$nativeId) {
            return 0;
        }
        $rows = self::rows('glpi_assets_assetmodels', [

            'id' => $nativeId,
        'assets_assetdefinitions_id' => $definitionId,


        ]);
        if (!$rows) {
            throw new RuntimeException('Native model is missing.');
        }
        $fields = array_intersect_key(
            reset($rows),
            array_flip(self::columns('glpi_peripheralmodels'))
        );
        unset($fields['id']);
        $model = new PeripheralModel();
        if (
            $model->getFromDBByCrit(
                [
                'name' => $fields['name'],
                'product_number' => $fields['product_number']
                ]
            )
        ) {
            return (int) $model->getID();
        }
        $id = $model->add($fields);
        if (!$id) {
            throw new RuntimeException('Cannot return the native model to a core PeripheralModel.');
        }
        return (int) $id;
    }

    private static function legacyType(int $nativeId, int $definitionId): int
    {
        if (!$nativeId) {
            return 0;
        }
        $rows = self::rows('glpi_assets_assettypes', [

            'id' => $nativeId,
        'assets_assetdefinitions_id' => $definitionId,


        ]);
        if (!$rows) {
            throw new RuntimeException('Native type is missing.');
        }
        $type = new PeripheralType();
        if ($type->getFromDBByCrit(['name' => reset($rows)['name']])) {
            return (int) $type->getID();
        }
        return (int) $type->add(['name' => reset($rows)['name']]);
    }

    private static function lockField(string $name): string
    {
        return str_replace(
            ['assets_assetmodels_id', 'assets_assettypes_id'],
            ['peripheralmodels_id', 'peripheraltypes_id'],
            $name
        );
    }

    public static function run(): array
    {
        global $DB;
        if (!PluginItcoreinventoryNative::lock()) {
            throw new RuntimeException('Dock processing is busy; retry removal later.');
        }
        try {
            $DB->beginTransaction();
            try {
                $state = self::state();
                if ($state['done']) {
                    $DB->commit();
                    return ['status' => 'already_returned'];
                }
                $columns = array_flip(self::columns('glpi_peripherals'));
                $targets = $state['canonicals'];
                $nativeLinks = self::rows(PluginItcoreinventoryNative::LINKS);
                $nativeLocks = [];
                foreach (
                    self::rows(
                        'glpi_lockedfields',
                        ['itemtype' => PluginItcoreinventoryNative::ASSET]
                    ) as $row
                ) {
                    $nativeLocks[$row['items_id']][self::lockField($row['field'])] = true;
                }
                foreach ($state['sources'] as $id => $entry) {
                    $fields = array_intersect_key($entry['source'], $columns);
                    $fields['is_deleted'] = $state['assets'][$state['mapping'][$id]]['is_deleted'];
                    if (self::rows('glpi_peripherals', ['id' => (int) $id])) {
                        $DB->update('glpi_peripherals', $fields, ['id' => (int) $id]);
                    } else {
                        $DB->insert('glpi_peripherals', $fields);
                    }
                }
                foreach ($state['assets'] as $assetId => $native) {
                    $new = !isset($targets[$assetId]);
                    $base = $state['baseline'][$assetId] ?? [];
                    foreach (['comment'] as $field) {
                        $native[$field] = trim(
                            preg_replace(
                                '/^\[ITCORE USB[^\r\n]*\](?:\r?\n)?/m',
                                '',
                                $native[$field] ?? ''
                            )
                        );
                        $base[$field] = trim(
                            preg_replace(
                                '/^\[ITCORE USB[^\r\n]*\](?:\r?\n)?/m',
                                '',
                                $base[$field] ?? ''
                            )
                        );
                    }
                    $values = [];
                    foreach (array_intersect_key($native, $columns) as $field => $value) {
                        if ($field === 'id') {
                            continue;
                        }
                        if (
                            $new || !empty($nativeLocks[$assetId][$field])
                            || ($field !== 'date_mod' && (string) $value !== (string) ($base[$field] ?? null))
                        ) {
                            $values[$field] = $value;
                        }
                    }
                    foreach (
                        [
                        'assets_assetmodels_id' => 'peripheralmodels_id',
                        'assets_assettypes_id' => 'peripheraltypes_id'
                        ] as $from => $to
                    ) {
                        if (
                            $new || !empty($nativeLocks[$assetId][$to])
                            || (int) $native[$from] !== (int) ($base[$from] ?? 0)
                        ) {
                            $values[$to] = $from === 'assets_assetmodels_id'
                                ? self::legacyModel((int) $native[$from], $state['definitionId'])
                                : self::legacyType((int) $native[$from], $state['definitionId']);
                        }
                    }
                    if ($new) {
                        $values['is_global'] = 0;
                        $DB->insert('glpi_peripherals', $values);
                        $targets[$assetId] = (int) $DB->insertId();
                    } elseif ($values) {
                        $destinations = array_keys(array_filter(
                            $state['mapping'],
                            static fn ($targetAsset) => $targetAsset === (int) $assetId
                        ));
                        $DB->update('glpi_peripherals', $values, ['id' => $destinations]);
                    }
                }
                foreach ($state['references'] as $group) {
                    foreach ($group['rows'] as $row) {
                        $assetId = (int) $row[$group['id']];
                        $key = $group['table'] . ':' . $row['id']
                        . ':' . $group['type'];
                        $origin = $state['origins'][$key] ?? null;
                        $target = $origin && $origin['asset_id'] === $assetId
                            ? $origin['source_id'] : $targets[$assetId];
                        $values = [$group['type'] => 'Peripheral', $group['id'] => $target];
                        if ($group['table'] === 'glpi_lockedfields') {
                            $values['field'] = self::lockField($row['field']);
                        }
                        if ($group['table'] === 'glpi_logs' && (int) $row['id_search_option'] === 65) {
                            $values['id_search_option'] = 61;
                        }
                        $DB->update($group['table'], $values, ['id' => $row['id']]);
                    }
                }
                foreach ($state['originalLocks'] as $entry) {
                    $row = $entry['row'];
                    if (empty($nativeLocks[$entry['asset_id']][$row['field']])) {
                        continue;
                    }
                    $same = self::rows('glpi_lockedfields', [

                        'itemtype' => 'Peripheral',
                    'items_id' => $row['items_id'],
                    'field' => $row['field'],


                    ]);
                    if (!$same) {
                        $DB->insert('glpi_lockedfields', $row);
                    }
                }
                $coreLinks = \Glpi\Asset\Asset_PeripheralAsset::getTable();
                foreach ($state['connections'] as $id => $original) {
                    $assetId = $state['mapping'][$original['items_id_peripheral']];
                    $match = array_filter($nativeLinks, static fn ($link) =>
                        (int) $link['assets_id'] === $assetId
                        && (int) $link['computers_id'] === (int) $original['items_id_asset']);
                    $original['is_deleted'] = $match ? (int) reset($match)['is_deleted']
                    : 1;
                    $existing = self::rows($coreLinks, ['id' => $id]);
                    if ($existing) {
                        $current = reset($existing);
                        foreach (
                            [
                            'itemtype_asset',
                            'items_id_asset',
                            'itemtype_peripheral',
                            'items_id_peripheral'
                            ] as $field
                        ) {
                            if ((string) $current[$field] !== (string) $original[$field]) {
                                throw new RuntimeException(
                                    'An original core connection changed; removal was '
                                    . 'cancelled.'
                                );
                            }
                        }
                        $DB->update($coreLinks, $original, ['id' => $id]);
                    } else {
                        $DB->insert($coreLinks, $original);
                    }
                }
                foreach ($nativeLinks as $link) {
                    if (!isset($targets[$link['assets_id']])) {
                        continue;
                    }
                    $key = [

                        'itemtype_asset' => 'Computer',
                    'items_id_asset' => $link['computers_id'],

                        'itemtype_peripheral' => 'Peripheral',
                    'items_id_peripheral' => $targets[$link['assets_id']],


                    ];
                    $same = self::rows($coreLinks, $key);
                    $values = $key + ['is_deleted' => $link['is_deleted'], 'is_dynamic' => $link['is_dynamic']];
                    if ($same) {
                        $DB->update($coreLinks, $values, ['id' => reset($same)['id']]);
                    } else {
                        $DB->insert($coreLinks, $values);
                    }
                }
                foreach ($state['sources'] as $sourceId => $entry) {
                    $restored = self::rows('glpi_peripherals', ['id' => $sourceId]);
                    $snapshot = reset($restored);
                    $snapshot['_itcore_meta'] = [

                        'info' => $entry['info'],
                    'canonical_source_id' => $entry['canonical_id'],


                    ];
                    $DB->update(PluginItcoreinventoryNative::MAP, [

                        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),


                    ], ['peripherals_id' => $sourceId]);
                }
                foreach ($state['assets'] as $assetId => $native) {
                    $sourceId = $targets[$assetId];
                    if (!isset($state['canonicals'][$assetId])) {
                        $legacyRows = self::rows('glpi_peripherals', ['id' => $sourceId]);
                        $legacy = reset($legacyRows);
                        $DB->insert(PluginItcoreinventoryNative::MAP, [

                            'peripherals_id' => $sourceId,
                        'assets_id' => $assetId,

                            'snapshot' => json_encode($legacy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),

                            'status' => 'reverted',
                        'date_mod' => date('Y-m-d H:i:s'),


                        ]);
                    }
                    $DB->update(
                        'glpi_assets_assets',
                        ['is_deleted' => 1],
                        ['id' => $assetId]
                    );
                }
                $remaining = $state['assets']
                    ? PluginItcoreinventoryMigration::referenceMatrix(
                        array_keys($state['assets']),
                        PluginItcoreinventoryNative::ASSET
                    )
                    : [];
                if ($remaining) {
                    throw new RuntimeException('Native references remain; removal was cancelled.');
                }
                $DB->update(
                    PluginItcoreinventoryNative::MAP,
                    ['status' => 'reverted'],
                    ['status' => 'migrated']
                );
                if ($state['journalIds']) {
                    $DB->update(
                        PluginItcoreinventoryNative::JOURNAL,
                        ['phase' => 'reverted'],
                        ['id' => $state['journalIds']]
                    );
                }
                $DB->insert(PluginItcoreinventoryNative::JOURNAL, [

                    'phase' => 'undo',

                    'payload' => json_encode([

                        'native_assets' => $state['assets'],
                'targets' => $targets,

                        'native_links' => $nativeLinks,
                'references' => $state['references'],


                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),

                    'date_mod' => date('Y-m-d H:i:s'),


                ]);
                $DB->update(
                    'glpi_assets_assetdefinitions',
                    ['is_active' => 0],
                    ['id' => $state['definitionId']]
                );
                Config::setConfigurationValues('plugin:itcoreinventory-native', [

                    'ready' => '0',
                'uninstall_reverted' => '1',
                'migration_blocked' => '0',


                ]);
                $DB->commit();
                return [
                'status' => 'returned',
                'native_docks' => count($targets),
                'original_cards' => count($state['sources'])
                ];
            } catch (Throwable $exception) {
                $DB->rollBack();
                throw $exception;
            }
        } finally {
            PluginItcoreinventoryNative::unlock();
        }
    }
}
