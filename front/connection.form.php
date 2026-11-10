<?php

/** SPDX-License-Identifier: MIT */

global $DB;
if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}
Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !PluginItcoreinventoryNative::ready()) {
    http_response_code(405);
    exit;
}
$action = (string)($_POST['action'] ?? '');
$link = new PluginItcoreinventoryDockConnection();
if ($action === 'disconnect') {
    $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$id || !$link->getFromDB($id)) {
        http_response_code(404);
        exit;
    }
    $assetId = (int)$link->fields['assets_id'];
    $pcId = (int)$link->fields['computers_id'];
} elseif ($action === 'connect') {
    $assetId = (int)($_POST['assets_id'] ?? 0);
    $pcId = (int)($_POST['computers_id'] ?? 0);
} else {
    http_response_code(400);
    exit;
}
$class = PluginItcoreinventoryNative::ASSET;
$asset = new $class();
$pc = new Computer();
if (
    !$asset->getFromDB($assetId) || !$pc->getFromDB($pcId) || !$asset->can($assetId, UPDATE)
    || !$pc->can($pcId, UPDATE)
    || $asset->fields['is_deleted'] || $pc->fields['is_deleted']
) {
    http_response_code(403);
    exit;
}
if ((int)$asset->fields['entities_id'] !== (int)$pc->fields['entities_id']) {
    Session::addMessageAfterRedirect(
        'Связь между разными организациями не создаётся.',
        false,
        ERROR
    );
    Html::back();
}
if (!PluginItcoreinventoryNative::lock()) {
    Session::addMessageAfterRedirect(
        'Инвентаризация выполняется. Повторите действие.',
        false,
        ERROR
    );
    Html::back();
}
try {
    if ($action === 'disconnect') {
        $link->update(['id' => $link->getID(),'is_deleted' => 1]);
    } else {
        $existing = iterator_to_array($DB->request([
            'FROM' => $link::getTable(),
            'WHERE' => ['assets_id' => $assetId,'computers_id' => $pcId]
        ]));
        if ($existing) {
            $link->update([
                'id' => reset($existing)['id'],
                'is_deleted' => 0,
                'is_dynamic' => 0
            ]);
        } else {
            $link->add([
                'assets_id' => $assetId,
                'computers_id' => $pcId,
                'is_deleted' => 0,
                'is_dynamic' => 0
            ]);
        }
    }
    PluginItcoreinventoryNative::syncLocations();
} finally {
    PluginItcoreinventoryNative::unlock();
}
Html::redirect($class::getFormURLWithID($assetId));
