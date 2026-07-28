<?php
/**
 * front/vuln_tasks.php — 漏洞修复 · 待处理任务页
 *
 * 功能：展示 AI 解析生成的修复任务，支持按状态/风险筛选、批量批准、
 *       单条批准/拒绝/标记手动处理。低风险且已匹配资产的任务默认预勾选；
 *       高风险任务标红且不预选（需人工确认）。
 * 执行通道：批准时，可自动执行类型（registry_fix/software_uninstall）自动下发
 *       到客户端代理（状态→已下发），客户端回报后回写 执行成功/执行失败。
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('read', READ);

$initialStatus = $_GET['status'] ?? 'pending';
$tasks = PluginAdmanagerVuln::getTasks($initialStatus);
// 预计算前端展示标签，避免在 Twig 中调用静态方法
foreach ($tasks as &$t) {
    $t['fix_label']           = PluginAdmanagerVuln::fixTypeLabel($t['fix_type'] ?? '');
    $t['risk_label']          = PluginAdmanagerVuln::riskLabel($t['risk_level'] ?? '');
    $t['status_label']        = PluginAdmanagerVuln::statusLabel($t['status'] ?? '');
    $t['risk_badge_class']    = $t['risk_level'] === 'high' ? 'bg-danger'
                                : ($t['risk_level'] === 'medium' ? 'bg-warning text-dark' : 'bg-success');
    $statusBadge = [
        'approved'     => 'bg-success',
        'rejected'     => 'bg-danger',
        'needs_manual' => 'bg-info text-dark',
        'dispatched'   => 'bg-primary',
        'done'         => 'bg-success',
        'failed'       => 'bg-danger',
    ][$t['status']] ?? 'bg-secondary';
    // 补丁安装：装完但等待重启才生效 → 警示徽章（区别于真正的「执行成功」）
    if (($t['status'] ?? '') === 'done' && !empty($t['needs_reboot'])) {
        $t['status_label']   = '已完成(待重启)';
        $statusBadge         = 'bg-warning text-dark';
    }
    $t['status_badge_class']  = $statusBadge;
}
unset($t);

$canWrite = Session::haveRight('plugin_admanager_deploy', CREATE)
            || Session::haveRight('plugin_admanager_admin', CREATE);

Html::header('漏洞修复 · 待处理任务', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'admanager_vuln');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/vuln_tasks.html.twig', [
    'tasks'         => $tasks,
    'initial_status'=> $initialStatus,
    'can_write'     => $canWrite,
    'csrf_token'    => Session::getNewCSRFToken(),
]);

Html::footer();
