<?php
/**
 * ajax/remote_session.php — // v4.9 远程桌面 Session Token 生成器 — 安全改进：GLPI代理生成，前端不接触API token
 * 
 * 安全设计：GLPI 服务端代理调用 FastAPI，前端永远不接触 API token。
 * 流程：前端 AJAX → 本端点（验证登录态）→ FastAPI POST /api/remote/request/{id} → 返回 session_token
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('read', READ);

$client_id = $_GET['client_id'] ?? 0;
if (!$client_id) {
    http_response_code(400);
    echo json_encode(['error' => '缺少 client_id']);
    exit;
}

try {
    $api = PluginAdmanagerFastApiClient::getInstance();
    $resp = $api->post('/api/remote/request/' . intval($client_id));
    
    if (empty($resp['session_token'])) {
        http_response_code(500);
        echo json_encode(['error' => 'FastAPI 未返回 session_token', 'raw' => $resp]);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'session_token' => $resp['session_token'],
        'hostname'      => $resp['hostname'] ?? '',
    ]);
    // Audit: remote desktop session requested
    PluginAdmanagerAuditLog::write('remote_session', 'Computer', $resp['hostname'] ?? '', 'client_id=' . $client_id);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
