<?php

/** SPDX-License-Identifier: MIT */

function plugin_itcoreinventory_legacy_defaults(): array
{
    $catalog = [];
    foreach (
        json_decode(
            file_get_contents(dirname(__DIR__) . '/docks.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        ) as $pid => $v
    ) {
        $catalog[] = [
            'enabled' => true,
            'pid' => (string)$pid,
            'model' => $v['model'],
            'name' => $v['name'],
            'serial_pattern' => ''
        ];
    }
    return [
        'enabled' => true,
        'vendor_id' => '17EF',
        'manufacturer' => 'LENOVO',
        'type_name' => 'Док-станция',
        'normalize_serial' => true,
        'serial_pattern' => '^1S([A-Z0-9]{10}|40[A-Z0-9]{2})([A-Z0-9]{8})$',
        'serial_group' => 2,
        'part_group' => 1,
        'migrate_existing' => true,
        'add_marker' => true,
        'dock_location' => true,
        'catalog' => $catalog
    ];
}
