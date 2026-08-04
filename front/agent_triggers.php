<?php
/**
 * front/agent_triggers.php — AI 定时/上线触发任务展示页
 * 展示 schedule_task 工具写入的 agent.trigger.scheduled.* / agent.trigger.online.* 记录。
 * 支持人工新建/编辑/取消（AI 有时候会安排错，这里可以直接补救而不用重新对话）。
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$can_write = PluginAdmanagerProfile::canDo('admin', CREATE);

// ── POST 处理 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    $act = $_POST['_action'] ?? '';

    if ($act === 'cancel_trigger' && !empty($_POST['trigger_key'])) {
        try {
            PluginAdmanagerFastApiClient::getInstance()->delete(
                '/api/agent/triggers/' . rawurlencode($_POST['trigger_key'])
            );
            Session::addMessageAfterRedirect('已取消该定时任务', true, INFO);
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect('取消失败：' . $e->getMessage(), true, ERROR);
        }
        Html::redirect($_SERVER['PHP_SELF']);
    }

    if ($act === 'save_trigger') {
        $clientIds = array_values(array_filter(array_map('intval', explode(',', $_POST['client_ids'] ?? ''))));
        $body = [
            'name'         => trim($_POST['name'] ?? ''),
            'task_type'    => $_POST['task_type'] ?? 'run_command',
            'trigger_type' => $_POST['trigger_type'] ?? 'online',
            'client_ids'   => $clientIds,
            'command'      => $_POST['command'] ?? '',
            'interpreter'  => $_POST['interpreter'] ?? 'powershell',
            'priority'     => $_POST['priority'] ?? 'normal',
        ];
        if (($body['trigger_type'] ?? '') === 'scheduled') {
            $body['scheduled_at'] = $_POST['scheduled_at'] ?? '';
        }

        try {
            $editKey = trim($_POST['edit_key'] ?? '');
            if ($editKey !== '') {
                PluginAdmanagerFastApiClient::getInstance()->put(
                    '/api/agent/triggers/' . rawurlencode($editKey), $body
                );
                Session::addMessageAfterRedirect('已更新该定时任务', true, INFO);
            } else {
                PluginAdmanagerFastApiClient::getInstance()->post('/api/agent/triggers', $body);
                Session::addMessageAfterRedirect('已新建定时任务', true, INFO);
            }
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect('保存失败：' . $e->getMessage(), true, ERROR);
        }
        Html::redirect($_SERVER['PHP_SELF']);
    }
}

// ── 数据准备 ──
$triggers = [];
$loadError = '';
try {
    $resp = PluginAdmanagerFastApiClient::getInstance()->get('/api/agent/triggers');
    $triggers = $resp['triggers'] ?? [];
} catch (\Throwable $e) {
    $loadError = $e->getMessage();
}

$clients = PluginAdmanagerDeploy::getClients();

Html::header('AI 定时任务', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'deploy');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/agent_triggers.html.twig', [
    'triggers'   => $triggers,
    'clients'    => $clients,
    'load_error' => $loadError,
    'can_write'  => $can_write,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();
