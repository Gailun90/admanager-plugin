<?php
/**
 * front/deploy.php — 部署任务列表页面
 * 展示最近的部署任务，支持取消/删除/重置失败；新建任务已拆分到 deploy_new_task.php。
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$can_write = PluginAdmanagerProfile::canDo('admin', CREATE);

// ── AJAX：任务明细目标列表 ──
if (isset($_GET['action']) && $_GET['action'] === 'targets' && isset($_GET['task_id'])) {
    header('Content-Type: application/json');
    $rows = PluginAdmanagerDeploy::getTaskTargets((int)$_GET['task_id']);
    // 统一时间格式化（GLPI 日期格式 + 本地时区）
    foreach ($rows as &$r) {
        $r['executed_at_fmt'] = PluginAdmanagerTime::fmt($r['executed_at'] ?? null);
    }
    unset($r);
    echo json_encode($rows);
    exit;
}

// ── AJAX：任务进度轮询 ──
if (isset($_GET['_ajax']) && $_GET['_ajax'] === 'task_progress') {
    $tasks = PluginAdmanagerDeploy::getTaskList();
    header('Content-Type: application/json');
    echo json_encode(array_values(array_filter($tasks, function($t) {
        $p = $t['progress'] ?? [];
        return ($p['pending'] ?? 0) + ($p['running'] ?? 0) > 0;
    })));
    exit;
}

// ── POST 处理（取消 / 删除 / 重置失败）──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    $act = $_POST['_action'] ?? '';

    if ($act === 'cancel_task' && !empty($_POST['task_id'])) {
        $result = PluginAdmanagerDeploy::cancelTask((int)$_POST['task_id']);
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }

    if ($act === 'delete_task' && !empty($_POST['task_id'])) {
        $result = PluginAdmanagerDeploy::deleteTask((int)$_POST['task_id']);
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }

    if ($act === 'reset_failed' && !empty($_POST['task_id'])) {
        $result = PluginAdmanagerDeploy::resetFailed((int)$_POST['task_id']);
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }
}

// ── 数据准备 ──
$tasks = PluginAdmanagerDeploy::getTasks(30);
// 统一时间格式化（GLPI 日期格式 + 本地时区），供模板 _fmt 字段使用
foreach ($tasks as &$t) {
    $t['created_at_fmt'] = PluginAdmanagerTime::fmt($t['created_at'] ?? null);
}
unset($t);

Html::header('任务列表', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'deploy');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/deploy.html.twig', [
    'tasks'      => $tasks,
    'can_write'  => $can_write,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();
