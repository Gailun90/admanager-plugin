<?php
/**
 * front/deploy_new_task.php — 新建部署任务页面
 * 从 deploy.php 拆分出来的独立 Tab：只负责创建部署任务的表单与提交。
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$can_write = PluginAdmanagerProfile::canDo('admin', CREATE);

// ── POST：创建部署任务 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    $act = $_POST['_action'] ?? '';

    if ($act === 'create_task') {
        $interactive    = ($_POST['interactive']      ?? '0') === '1';
        $silentOverride = ($_POST['silent_override']  ?? '0') === '1';

        $extra = [];
        if ($interactive) {
            $extra['defer_minutes']  = $_POST['defer_minutes']  ?? null;
            $extra['defer_max_count']= $_POST['defer_max_count'] ?? null;
            $extra['dialog_title']   = $_POST['dialog_title']   ?? null;
            $extra['dialog_message'] = $_POST['dialog_message'] ?? null;
        }
        if ($silentOverride) {
            $extra['silent_override'] = true;
        }

        $result = PluginAdmanagerDeploy::createTask(
            trim($_POST['task_name']   ?? ''),
            (int)($_POST['package_id'] ?? 0),
            $_POST['target_type']      ?? 'all',
            !empty($_POST['target_id']) ? (int)$_POST['target_id'] : null,
            $interactive,
            ($_POST['need_reboot']     ?? '0') === '1',
            (int)($_POST['timeout']    ?? 600),
            $extra
        );
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        // 创建成功后跳转到「部署任务列表」，方便立即看到新任务的推送进度
        Html::redirect('/plugins/admanager/front/deploy.php');
    }
}

// ── 数据准备 ──
$packages      = PluginAdmanagerDeploy::getPackages();
$clients       = PluginAdmanagerDeploy::getClients();
$groups        = PluginAdmanagerDeploy::getGroups();
$deploy_config = PluginAdmanagerDeploy::getDeployConfig();

Html::header('新建任务', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'deploy');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/deploy_new_task.html.twig', [
    'packages'      => $packages,
    'clients'       => $clients,
    'groups'        => $groups,
    'deploy_config' => $deploy_config,
    'can_write'     => $can_write,
    'csrf_token'    => Session::getNewCSRFToken(),
]);

Html::footer();
