<?php
<?php if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }
/**
 * migrate_usertemplate.php — 一次性迁移脚本
 * 将 usertemplate 表从旧结构 (preset_fields + selected_fields)
 * 迁移到新结构 (fields[])
 */
require_once '/var/www/html/glpi/inc/includes.php';

global $DB;
$table = 'glpi_plugin_admanager_usertemplates';

if (!$DB->fieldExists($table, 'fields')) {
    $DB->queryOrDie("ALTER TABLE `{$table}` ADD COLUMN `fields` longtext AFTER `selected_fields`");
    echo "[OK] 新增 fields 列\n";
}

$all = $DB->request(['FROM' => $table]);
$migrated = 0;
$errors = 0;

foreach ($all as $row) {
    $id   = (int)$row['id'];
    $name = $row['name'] ?? "模板#{$id}";

    $existingFields = json_decode($row['fields'] ?? '[]', true) ?: [];
    if (!empty($existingFields)) {
        echo "[SKIP] #{$id} {$name} 已是新格式\n";
        continue;
    }

    $preset   = json_decode($row['preset_fields']   ?? '{}',  true) ?: [];
    $selected = json_decode($row['selected_fields'] ?? '[]', true) ?: [];

    if (empty($selected)) {
        echo "[WARN] #{$id} {$name} selected_fields 为空，跳过\n";
        continue;
    }

    $fields = [];
    foreach ($selected as $key) {
        $fields[] = ['key' => $key, 'default' => $preset[$key] ?? ''];
    }

    $ok = $DB->update($table, ['fields' => json_encode($fields, JSON_UNESCAPED_UNICODE)], ['id' => $id]);
    if ($ok) {
        echo "[MIGRATED] #{$id} {$name} — " . count($fields) . " 个字段\n";
        $migrated++;
    } else {
        echo "[ERROR] #{$id} {$name} 更新失败\n";
        $errors++;
    }
}

echo "\n迁移完成：成功 {$migrated} 条，失败 {$errors} 条\n";