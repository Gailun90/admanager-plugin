<?php
/**
 * ajax/dashboard_data.php — 仪表盘慢数据异步端点
 * v4.8: 在线判断改用 last_seen（5分钟），消除两次 API 调用间的时间差导致数字波动
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('read', READ);

$data = PluginAdmanagerDashboard::getFullReport();

// v4.8: 在线判断基于 last_seen（5分钟内），不再跨两次 API 调用比对 online_serials
$online_clients = [];
$recent_tasks = [];
try {
    $api = PluginAdmanagerFastApiClient::getInstance();
    $clients = $api->getClients(1, 200);
    $tasks = $api->get('/api/tasks/admin/list', ['limit' => 10]);

    // ★ v4.8: last_seen 5分钟窗口判断在线，单次调用自洽，不依赖 online_serials
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $cutoff = (clone $now)->modify('-5 minutes');

    foreach (($clients['items'] ?? []) as $c) {
        if (empty($c['last_seen'])) continue;
        try {
            $last = new DateTime($c['last_seen'], new DateTimeZone('UTC'));
            if ($last >= $cutoff) {
                $online_clients[] = $c;
            }
        } catch (\Exception $e) {}
    }
    $recent_tasks = $tasks;
} catch (Exception $e) {}

// ★ v4.8: 用真实在线数覆盖 api_stats 里的数字（保证和 online_list 一致）
$api_stats = $data['api_stats'] ?? [];
$api_stats['online_clients'] = count($online_clients);

header('Content-Type: application/json');
echo json_encode([
    'api_stats'  => $api_stats,
    'week_audit' => $data['week_audit'] ?? [],
    'diff_count' => $data['diff_count'] ?? 0,
    'diff_list'  => $data['diff_list']  ?? [],
    'last_sync'  => $data['last_sync']  ?? null,
    'alert_days' => $data['alert_days'] ?? 7,
    'can_import'   => PluginAdmanagerProfile::canDo('admin', CREATE),
    'online_list'  => $online_clients,
    'recent_tasks' => $recent_tasks,
]);
