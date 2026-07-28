<?php
/**
 * ajax/groups_data.php — 分组管理异步数据端点 v2
 * 双面板模式：返回 groups + 指定分组成员 + 全部终端（用于左侧未分组面板）
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

function groups_api(string $method, string $path, array $params = []): array {
    $cfg = PluginAdmanagerConfig::getFastApiConfig();
    $url = rtrim($cfg['url'], '/') . $path;
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $cfg['token']],
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($method === 'POST' || $method === 'PATCH')
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) return [];
    $data = json_decode($raw, true);
    return $data ?: [];
}

$action = $_GET['action'] ?? 'all';

// 只拿分组列表
if ($action === 'groups') {
    header('Content-Type: application/json');
    echo json_encode(groups_api('GET', '/api/groups') ?: []);
    exit;
}

// 拿全部终端（双面板用）
if ($action === 'clients') {
    $api  = PluginAdmanagerFastApiClient::getInstance();
    $data = $api->getClients(1, 200);
    header('Content-Type: application/json');
    $clients = [];
    foreach (($data['items'] ?? []) as $c) {
        $clients[] = [
            'id'       => $c['client_id'] ?? 0,
            'hostname' => $c['hostname'] ?? $c['serial'] ?? '',
            'serial'   => $c['serial'] ?? '',
            'group_id' => $c['group_id'] ?? null,
        ];
    }
    echo json_encode($clients);
    exit;
}

// 拿分组成员
if ($action === 'members' && !empty($_GET['group_id'])) {
    $members = groups_api('GET', '/api/groups/' . (int)$_GET['group_id'] . '/members') ?: [];
    header('Content-Type: application/json');
    echo json_encode($members);
    exit;
}

// 默认：返回分组列表 + 指定分组成员（兼容旧版）
$groups  = groups_api('GET', '/api/groups') ?: [];
$members = [];
if (!empty($_GET['group_id'])) {
    $members = groups_api('GET', '/api/groups/' . (int)$_GET['group_id'] . '/members') ?: [];
}

header('Content-Type: application/json');
echo json_encode([
    'groups'  => $groups,
    'members' => $members,
]);
