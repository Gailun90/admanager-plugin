<?php
/**
 * ajax/deploy_data.php — 部署页面异步数据端点
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$action = $_GET['action'] ?? 'tasks';

// ── 任务列表 ──
if ($action === 'tasks') {
    $tasks = PluginAdmanagerDeploy::getTasks(30);
    header('Content-Type: application/json');
    echo json_encode($tasks ?: []);
    exit;
}

// ── 任务进度轮询 ──
if ($action === 'task_progress') {
    // 只返回活跃任务的进度
    $all   = PluginAdmanagerDeploy::getTasks(50);
    $active = [];
    foreach ($all as $t) {
        if (in_array($t['status'] ?? '', ['active', 'pending', 'running'])) {
            $active[] = $t;
        }
    }
    header('Content-Type: application/json');
    echo json_encode($active);
    exit;
}

// ── 任务明细终端列表 ──
if ($action === 'targets' && !empty($_GET['task_id'])) {
    $targets = PluginAdmanagerDeploy::getTaskTargets((int)$_GET['task_id']);
    header('Content-Type: application/json');
    echo json_encode($targets ?: []);
    exit;
}

// ── 客户端列表（用于创建任务目标选择） ──
if ($action === 'clients') {
    $clients = PluginAdmanagerDeploy::getClients();
    header('Content-Type: application/json');
    echo json_encode($clients ?: []);
    exit;
}

// ── 分组列表 ──
if ($action === 'groups') {
    try {
        $data = PluginAdmanagerFastApiClient::getInstance()->get('/api/groups');
        header('Content-Type: application/json');
        echo json_encode($data ?: []);
    } catch (\Throwable $e) {
        echo '[]';
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
