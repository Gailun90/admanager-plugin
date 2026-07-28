<?php
/**
 * front/deploy_config.php — 部署配置页面
 * 设置交互弹窗内容、推迟参数、超时等全局默认值
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$can_write = PluginAdmanagerProfile::canDo('admin', CREATE);
$flash = null;
$flash_type = 'success';

// ── POST 保存 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    $act = $_POST['_action'] ?? '';

    if ($act === 'save_deploy_config') {
        $config = PluginAdmanagerConfig::getAll(); // 先把已有的合并进来防丢失

        $fields = [
            'deploy_defer_minutes'   => (int)($_POST['defer_minutes'] ?? 60),
            'deploy_defer_max_count' => (int)($_POST['defer_max_count'] ?? 3),
            'deploy_dialog_title'    => trim($_POST['dialog_title'] ?? ''),
            'deploy_dialog_message'  => trim($_POST['dialog_message'] ?? ''),
            'deploy_reboot_title'    => trim($_POST['reboot_title'] ?? ''),
            'deploy_reboot_message'  => trim($_POST['reboot_message'] ?? ''),
            'deploy_default_timeout' => (int)($_POST['default_timeout'] ?? 600),
            'deploy_jitter_max'      => (int)($_POST['jitter_max'] ?? 60),
            'deploy_silent_override' => !empty($_POST['silent_override']) ? '1' : '0',
            'pkg_dir'               => trim($_POST['pkg_dir'] ?? ''),
        ];

        PluginAdmanagerConfig::saveAll(array_merge($config, $fields));

        PluginAdmanagerAuditLog::write(
            'config_update', 'deploy', '', '部署配置',
            $fields, true, ''
        );

        $flash = '部署配置已保存';
        $flash_type = 'success';
    }
}

$deploy_config = PluginAdmanagerConfig::getDeployConfig();

Html::header('部署配置', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'deploy_config');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/deploy_config.html.twig', [
    'deploy_config' => $deploy_config,
    'can_write'     => $can_write,
    'csrf_token'    => Session::getNewCSRFToken(),
    'flash'         => $flash,
    'flash_type'    => $flash_type,
]);

Html::footer();
