<?php

/** SPDX-License-Identifier: MIT */

function plugin_itcoreinventory_guard_task_removal(CronTask $task): void
{
    if (($task->fields['itemtype'] ?? '') === 'PluginItcoreinventoryNative') {
        PluginItcoreinventoryUndo::plan();
    }
}

function plugin_itcoreinventory_native_menu(array $menu): array
{
    global $DB;
    $class = PluginItcoreinventoryNative::ASSET;
    $key = strtolower($class);
    // Native rights were added while this browser session was already open.
    if (
        PluginItcoreinventoryNative::ready() && isset($_SESSION['glpiactiveprofile']['id'])
        && ($_SESSION['itcore_native_dock_rights_refreshed'] ?? 0) !== $_SESSION['glpiactiveprofile']['id']
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
        $_SESSION['itcore_native_dock_rights_refreshed'] = $_SESSION['glpiactiveprofile']['id'];
    }
    if (
        PluginItcoreinventoryNative::ready() && isset($menu['assets']['content'])
        && $class::canView()
    ) {
        $entry = $class::getMenuContent();
        if ($entry) {
            $menu['assets']['content'][$key] = $entry;
        }
    }
    foreach ($menu as &$category) {
        if (
            !isset(
                $category['content']['monitor'],
                $category['content'][$key]
            )
        ) {
            continue;
        }
        $native = $category['content'][$key];
        $ordered = [];
        foreach ($category['content'] as $k => $v) {
            if (in_array($k, [$key,'itcoredocks'], true)) {
                continue;
            }
            $ordered[$k] = $v;
            if ($k === 'monitor') {
                $ordered[$key] = $native;
            }
        }
        $category['content'] = $ordered;
        break;
    }
    unset($category);
    return $menu;
}
function plugin_itcoreinventory_native_pre($data)
{
    $GLOBALS['itcore_native_dock_batch'] = null;
    if (!PluginItcoreinventoryNative::ready()) {
        throw new RuntimeException('Dock asset migration is not ready; inventory will be retried');
    }
    if (empty(plugin_itcoreinventory_settings()['enabled'])) {
        return $data;
    }
    $infos = [];
    $hasUsb = false;
    $partial = false;
    if ($data instanceof SimpleXMLElement) {
        $root = isset($data->CONTENT) ? $data->CONTENT : $data;
        $hasUsb = isset($root->USBDEVICES);
        $remove = [];
        $i = 0;
        foreach ($root->USBDEVICES ?? [] as $usb) {
            $info = plugin_itcoreinventory_info(
                (string)$usb->VENDORID,
                (string)$usb->PRODUCTID,
                (string)$usb->SERIAL
            );
            if ($info) {
                $infos[$info['manufacturer_key'] . ':' . $info['serial']] = $info;
                $remove[] = $i;
            }
            $i++;
        }
        rsort($remove);
        foreach ($remove as $i) {
            unset($root->USBDEVICES[$i]);
        }
    } elseif (is_object($data)) {
        $root = $data->content ?? $data;
        $hasUsb = is_object($root) && property_exists($root, 'usbdevices');
        $partial = !empty($data->partial);
        if ($hasUsb) {
            $keep = [];
            foreach ($root->usbdevices as $usb) {
                $info = plugin_itcoreinventory_info(
                    (string)($usb->vendorid ?? ''),
                    (string)($usb->productid ?? ''),
                    (string)($usb->serial ?? '')
                );
                if ($info) {
                    $infos[$info['manufacturer_key'] . ':' . $info['serial']] = $info;
                } else {
                    $keep[] = $usb;
                }
            }
            $root->usbdevices = $keep;
        }
    }
    if ($hasUsb) {
        $GLOBALS['itcore_native_dock_batch'] = ['infos' => array_values($infos),'complete' => !$partial];
    }
    return $data;
}
function plugin_itcoreinventory_native_post($data)
{
    $batch = $GLOBALS['itcore_native_dock_batch'] ?? null;
    $GLOBALS['itcore_native_dock_batch'] = null;
    if ($batch) {
        try {
            PluginItcoreinventoryNative::enqueue(
                (string)($data->deviceid ?? ''),
                $batch['infos'],
                $batch['complete']
            );
            PluginItcoreinventoryNative::processQueue();
        } catch (Throwable$e) {
            Toolbox::logInFile(
                'itcoreinventory-native',
                'Post-inventory processing failed: ' . $e->getMessage()
                . "\n"
            );
        }
    }
    return $data;
}
