<?php

/** SPDX-License-Identifier: MIT */

namespace GlpiPlugin\Itcoreinventory;

use CommonDBRelation;
use CommonDBTM;
use CommonGLPI;
use Computer;
use Dropdown;
use Html;

class PluginItcoreinventoryDockConnection extends CommonDBRelation
{
    public static ?string $itemtype_1 = PluginItcoreinventoryNative::ASSET;
    public static ?string $items_id_1 = 'assets_id';
    public static ?string $itemtype_2 = Computer::class;
    public static ?string $items_id_2 = 'computers_id';
    public static function getTable($classname = null)
    {
        return PluginItcoreinventoryNative::LINKS;
    }
    public static function getTypeName($nb = 0)
    {
        return 'Подключения док-станций';
    }
    public static function getIcon()
    {
        return 'ti ti-device-usb';
    }
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        global $DB;
        if (
            !$item instanceof CommonDBTM || !$item->can($item->getID(), READ)
            || !PluginItcoreinventoryNative::ready()
        ) {
            return '';
        }
        $column = $item instanceof Computer ? 'computers_id' : 'assets_id';
        $n = count(iterator_to_array($DB->request([
            'SELECT' => ['id'],
            'FROM' => self::getTable(),
            'WHERE' => [$column => $item->getID(),'is_deleted' => 0]
        ])));
        return self::createTabEntry(
            $item instanceof Computer ? 'Док-станции' : 'Компьютеры',
            $n,
            $item::class
        );
    }
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $DB,$CFG_GLPI;
        if (!$item instanceof CommonDBTM || !$item->can($item->getID(), READ)) {
            return false;
        }
        $isComputer = $item instanceof Computer;
        $column = $isComputer ? 'computers_id' : 'assets_id';
        $peerClass = $isComputer ? PluginItcoreinventoryNative::ASSET
        : Computer::class;
        $e = static fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<div class="card"><div class="card-header"><h3 class="card-title">'
        . ($isComputer ? 'Док-станции' : 'Подключённые компьютеры')
        . (('</h3></div><div class="table-responsive"><table class="table '
        .
        'table-hover"><thead><tr>') .
        '<th>Наименование</th><th>Серийный номер</th><th>Источник связи</th><th>Последнее '
        .
        'подтверждение</th><th></th></tr></thead><tbody>');
        $used = [];
        foreach (
            $DB->request([
                'FROM' => self::getTable(),
                'WHERE' => [$column => $item->getID(),'is_deleted' => 0]
            ]) as $row
        ) {
            $peer = new $peerClass();
            $id = $row[$isComputer ? 'assets_id' : 'computers_id'];
            if (!$peer->getFromDB($id) || !$peer->can($id, READ)) {
                continue;
            }
            $used[] = $id;
            echo '<tr><td>' . $peer->getLink() . '</td><td>' . $e($peer->fields['serial'] ?? '')
            . '</td><td>' . ($row['is_dynamic'] ? 'Инвентаризация'
            : 'Вручную') . '</td><td>' . $e($row['last_seen'] ?? '')
            . '</td><td>';
            if ($item->can($item->getID(), UPDATE) && $peer->can($id, UPDATE)) {
                echo '<form method="post" action="' . $e($CFG_GLPI['root_doc']
                . '/plugins/itcoreinventory/front/connection.form.php')
                . ('"><input type="hidden" name="action" value="disconnect"><input '
                .
                'type="hidden" name="id" value="') . (int)$row['id']
                . '"><button class="btn btn-outline-secondary btn-sm">Отключить</button>';
                Html::closeForm();
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($item->can($item->getID(), UPDATE)) {
            echo '<div class="card-body"><form method="post" action="'
            . $e($CFG_GLPI['root_doc']
            . '/plugins/itcoreinventory/front/connection.form.php')
            . '">';
            echo '<input type="hidden" name="action" value="connect"><input type="hidden" name="'
            . $column . '" value="' . $item->getID() . '">';
            Dropdown::show($peerClass, [
                'name' => $isComputer ? 'assets_id' : 'computers_id',
                'entity' => $item->fields['entities_id'],
                'used' => $used
            ]);
            echo ' <button class="btn btn-primary">Подключить</button>';
            Html::closeForm();
            echo('<p class="text-muted mt-2">Автоматическая связь подтверждается следующей '
            .
            'инвентаризацией. Если док присутствует в отчёте ПК, связь будет восстановлена.</p>'
            .
            '</div>');
        }
        echo '</div>';
        return true;
    }
}
