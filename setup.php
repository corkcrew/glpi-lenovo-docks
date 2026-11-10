<?php

/** SPDX-License-Identifier: MIT */

function plugin_init_itcoreinventory()
{
    global $PLUGIN_HOOKS,$DB;
    $PLUGIN_HOOKS['csrf_compliant']['itcoreinventory'] = true;
    $PLUGIN_HOOKS['config_page']['itcoreinventory'] = 'front/config.php';
    require_once __DIR__ . '/inc/settings-ui.php';
    require_once __DIR__ . '/inc/legacy-settings.php';
    require_once __DIR__ . '/inc/profiles.class.php';
    require_once __DIR__ . '/inc/settings.php';
    require_once __DIR__ . '/inc/recognition.php';
    require_once __DIR__ . '/inc/native.class.php';
    require_once __DIR__ . '/inc/dockconnection.class.php';
    require_once __DIR__ . '/inc/migration.class.php';
    require_once __DIR__ . '/inc/undo.class.php';
    require_once __DIR__ . '/inc/hooks.php';
    Plugin::registerClass('PluginItcoreinventoryDockConnection', [
        'addtabon' => ['Computer',PluginItcoreinventoryNative::ASSET]
    ]);
    Plugin::registerClass('PluginItcoreinventoryNative');
    $PLUGIN_HOOKS['pre_inventory']['itcoreinventory'] = [
        'stdClass' => 'plugin_itcoreinventory_native_pre',
        'SimpleXMLElement' => 'plugin_itcoreinventory_native_pre'
    ];
    $PLUGIN_HOOKS['post_inventory']['itcoreinventory'] = 'plugin_itcoreinventory_native_post';
    $PLUGIN_HOOKS['pre_item_purge']['itcoreinventory'] = ['CronTask' => 'plugin_itcoreinventory_guard_task_removal'];
    $PLUGIN_HOOKS['pre_item_delete']['itcoreinventory'] = ['CronTask' => 'plugin_itcoreinventory_guard_task_removal'];
    $PLUGIN_HOOKS['redefine_menus']['itcoreinventory'] = 'plugin_itcoreinventory_native_menu';
    // Rebuild the native menu once for sessions opened before the asset migration.
    if (
        PHP_SAPI !== 'cli' && PluginItcoreinventoryNative::ready()
        && isset($_SESSION['glpiactiveprofile']['id'])
    ) {
        if (
            !array_key_exists('asset_dockstation', $_SESSION['glpiactiveprofile'])
            || ($_SESSION['itcore_native_dock_menu_version'] ?? '') !== '2.0.0b'
        ) {
            $rights = iterator_to_array($DB->request([
                'SELECT' => ['rights'],
                'FROM' => 'glpi_profilerights',
                'WHERE' => [
                    'profiles_id' => $_SESSION['glpiactiveprofile']['id'],
                    'name' => 'asset_dockstation'
                ]
            ]));
            $_SESSION['glpiactiveprofile']['asset_dockstation'] = $rights
            ? (int)reset($rights)['rights'] : 0;
            unset($_SESSION['glpimenu']);
        }
        if (($_SESSION['itcore_native_dock_menu_version'] ?? '') !== '2.0.0b') {
            unset($_SESSION['glpimenu']);
            $_SESSION['itcore_native_dock_menu_version'] = '2.0.0b';
        }
    }
}
function plugin_version_itcoreinventory()
{
    return [
        'name' => 'ITCORE: док-станции',
        'version' => '2.1.1',
        'author' => 'ОКТО ИТ',
        'license' => 'MIT',
        'requirements' => ['glpi' => ['min' => '12.0.0','max' => '12.0.99']]
    ];
}
function plugin_itcoreinventory_check_prerequisites()
{
    return version_compare(GLPI_VERSION, '12.0.0', '>=') && version_compare(GLPI_VERSION, '12.1.0', '<');
}
function plugin_itcoreinventory_check_config($verbose = false)
{
    return true;
}
