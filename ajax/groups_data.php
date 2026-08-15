<?php
/**
 * ajax/groups_data.php — 分组管理异步数据端点 v2
 * 双面板模式：返回 groups + 指定分组成员 + 全部终端（用于左侧未分组面板）
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

// 仅用于 GET 分组/成员列表；统一走 PluginAdmanagerFastApiClient（与 groups.php 一致，去掉重复 curl + token 处理）
function groups_api(string $path, array $params = []): array {
    try {
        return PluginAdmanagerFastApiClient::getInstance()->get($path, $params) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

$action = $_GET['action'] ?? 'all';

// 只拿分组列表
if ($action === 'groups') {
    header('Content-Type: application/json');
    echo json_encode(groups_api('/api/groups') ?: []);
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
    $members = groups_api('/api/groups/' . (int)$_GET['group_id'] . '/members') ?: [];
    header('Content-Type: application/json');
    echo json_encode($members);
    exit;
}

// 默认：返回分组列表 + 指定分组成员（兼容旧版）
$groups  = groups_api('/api/groups') ?: [];
$members = [];
if (!empty($_GET['group_id'])) {
    $members = groups_api('/api/groups/' . (int)$_GET['group_id'] . '/members') ?: [];
}

header('Content-Type: application/json');
echo json_encode([
    'groups'  => $groups,
    'members' => $members,
]);
