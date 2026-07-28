<?php
/**
 * ajax/import_aduser.php — AD用户导入GLPI AJAX端点
 */
include('../../../inc/includes.php');
Session::checkLoginUser();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => '仅接受 POST']);
    exit;
}

PluginAdmanagerProfile::checkRight('admin', CREATE);

$sam = $_POST['sam'] ?? '';
$dn  = $_POST['dn']  ?? '';

if (!$sam) {
    echo json_encode(['ok' => false, 'message' => '缺少用户名']);
    exit;
}

try {
    // 从 AD 获取用户详细信息
    $ldap = PluginAdmanagerAdLdap::getInstance();
    $entry = $ldap->findUser($sam);

    if (!$entry) {
        echo json_encode(['ok' => false, 'message' => "AD 中未找到用户: {$sam}"]);
        exit;
    }

    // 调用 ImportBridge 导入
    $result = PluginAdmanagerImportBridge::importAdUser($entry);

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
exit;
