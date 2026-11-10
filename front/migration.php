<?php

/** SPDX-License-Identifier: MIT */

if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}

Session::checkRight('config', UPDATE);
if (!isset($_SESSION['itcore_dock_migration_csrf'])) {
    $_SESSION['itcore_dock_migration_csrf'] = bin2hex(random_bytes(32));
}
$report = null;
$error = null;
$state = Config::getConfigurationValues('plugin:itcoreinventory-native');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (
            !hash_equals(
                $_SESSION['itcore_dock_migration_csrf'],
                (string) ($_POST['_dock_csrf'] ?? '')
            )
        ) {
            throw new RuntimeException('Сессия формы устарела. Обновите страницу.');
        }
        if (($_POST['action'] ?? '') === 'apply') {
            $report = PluginItcoreinventoryMigration::run();
        } elseif (($_POST['action'] ?? '') === 'undo_preview') {
            $report = PluginItcoreinventoryUndo::plan();
        } elseif (($_POST['action'] ?? '') === 'preview') {
            $report = PluginItcoreinventoryMigration::plan();
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}
Html::header(
    'Перенос док-станций из устройств',
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);
$escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<div class="container-fluid"><div class="card"><div class="card-header">';
echo('<h2 class="card-title">Перенос существующих док-станций</h2>'
.
'</div><div class="card-body">');
echo('<p>Проверка использует каталог моделей, VID/PID в сохранённой инвентаризации и '
.
'структурированные USB-маркеры. ');
echo('Одного названия «USB Composite Device» недостаточно. ' .
'Неоднозначные устройства требуют проверки.</p>');
echo('<p>Перед переносом сохраните резервную копию базы данных. '
.
'Старые карточки перемещаются в корзину; ');
echo('документы, история и поддерживаемые связи переносятся. ' .
'Конфликты останавливают перенос.</p>');
if (!empty($state['migration_error'])) {
    echo '<div class="alert alert-warning">' . $escape($state['migration_error'])
    . '</div>';
}
if ($error !== null) {
    echo '<div class="alert alert-danger">' . $escape($error) . '</div>';
}
echo '<form method="post"><input type="hidden" name="_dock_csrf" value="';
echo $escape($_SESSION['itcore_dock_migration_csrf'])
    . '"><button name="action" value="preview" class="btn btn-outline-primary">';
echo('Проверить без изменений</button> <button name="action" value="apply" class="btn '
.
'btn-primary">Перенести</button>');
Html::closeForm();
echo '<form method="post" class="mt-3"><input type="hidden" name="_dock_csrf" value="';
echo $escape($_SESSION['itcore_dock_migration_csrf']) . '">';
echo '<button name="action" value="undo_preview" class="btn btn-outline-secondary">';
echo 'Проверить возврат перед удалением</button>';
Html::closeForm();
if ($report !== null) {
    echo '<pre class="mt-3">' . $escape(
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ) . '</pre>';
}
echo '</div></div></div>';
Html::footer();
