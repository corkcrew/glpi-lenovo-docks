<?php

/** SPDX-License-Identifier: MIT */

function plugin_itcoreinventory_install()
{
    $configuration = Config::getConfigurationValues('plugin:itcoreinventory');
    $saved = json_decode($configuration['settings'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
    if (($saved['schema_version'] ?? null) !== 2) {
        PluginItcoreinventorySettings::save(
            'itcoreinventory',
            PluginItcoreinventoryProfiles::read(),
            (int) ($configuration['settings_revision'] ?? 0)
        );
    }
    require_once __DIR__ . '/inc/native.class.php';
    PluginItcoreinventoryNative::installSchema();
    require_once __DIR__ . '/inc/recognition.php';
    require_once __DIR__ . '/inc/dockconnection.class.php';
    require_once __DIR__ . '/inc/migration.class.php';
    $definition = PluginItcoreinventoryNative::createDefinition();
    PluginItcoreinventoryNative::upgradeIdentities();
    Config::setConfigurationValues('plugin:itcoreinventory-native', [
        'ready' => '1',
        'definition_id' => (string)$definition->getID(),
        'uninstall_reverted' => '0',
    ]);
    $oldTask = new CronTask();
    $hadTask = $oldTask->getFromDBByCrit([
        'itemtype' => 'PluginItcoreinventoryNative',
        'name' => 'DockInventory'
    ]);
    $oldFields = $oldTask->fields;
    CronTask::register('PluginItcoreinventoryNative', 'DockInventory', 1800, [
        'mode' => CronTask::MODE_EXTERNAL,
        'state' => 1,
        'logs_lifetime' => 30
    ]);
    if ($hadTask) {
        global $DB;
        $DB->update('glpi_crontasks', array_intersect_key($oldFields, array_flip([
            'mode',
            'state',
            'frequency',
            'lastrun',
            'hourmin',
            'hourmax',
            'logs_lifetime'
        ])), ['id' => $oldFields['id']]);
    }
    if (!empty(plugin_itcoreinventory_settings()['migrate_existing'])) {
        try {
            PluginItcoreinventoryMigration::run();
        } catch (Throwable $e) {
            Config::setConfigurationValues('plugin:itcoreinventory-native', [
                'migration_blocked' => '1',
                'migration_error' => $e->getMessage()
            ]);
        }
    }
    return true;
}
function plugin_itcoreinventory_uninstall()
{
    try {
        require_once __DIR__ . '/inc/undo.class.php';
        PluginItcoreinventoryUndo::run();
        CronTask::unregister('itcoreinventory');
        return true;
    } catch (Throwable $exception) {
        // GLPI 12 ignores a false return from uninstall callbacks.
        // An exception is required to prevent marking a blocked removal as completed.
        throw new RuntimeException('Dock removal cancelled: ' . $exception->getMessage(), 0, $exception);
    }
}
