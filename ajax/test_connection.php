<?php
/**
 * ajax/test_connection.php — 连接测试 AJAX 端点
 */
include('../../../inc/includes.php');

// 必须在任何 GLPI 输出前设置，防止 302 重定向覆盖响应
header('Content-Type: application/json; charset=utf-8');

// 未登录：直接返回 JSON，不走 GLPI 的 302 重定向
if (!isset($_SESSION['glpiname']) || !Session::getLoginUserID()) {
    echo json_encode(['ok' => false, 'message' => '未登录或 Session 已过期，请刷新页面重试']);
    exit;
}

$type = $_GET['type'] ?? '';

try {
    switch ($type) {
        case 'ad':
            $result = PluginAdmanagerConfig::testAdConnection();
            break;
        case 'fastapi':
            $result = PluginAdmanagerConfig::testFastApiConnection();
            break;
        case 'ai':
            $result = PluginAdmanagerConfig::testAiConnection();
            break;
        default:
            $result = ['ok' => false, 'message' => '未知测试类型: ' . htmlspecialchars($type)];
    }
} catch (\Throwable $e) {
    $result = ['ok' => false, 'message' => $e->getMessage()];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
exit;
