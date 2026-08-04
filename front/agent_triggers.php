<?php
/**
 * front/agent_triggers.php — AI 定时/上线触发任务展示页
 * 展示 schedule_task 工具写入的 agent.trigger.scheduled.* / agent.trigger.online.* 记录。
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$can_write = PluginAdmanagerProfile::canDo('admin', CREATE);

// ── POST：取消触发任务 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    if (($_POST['_action'] ?? '') === 'cancel_trigger' && !empty($_POST['trigger_key'])) {
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

Html::header('AI 定时任务', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'deploy');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/agent_triggers.html.twig', [
    'triggers'   => $triggers,
    'load_error' => $loadError,
    'can_write'  => $can_write,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();
