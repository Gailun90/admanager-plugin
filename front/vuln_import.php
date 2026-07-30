<?php
/**
 * front/vuln_import.php — 漏洞管理 · 导入记录页
 *
 * 功能：上传 Qualys 格式 xlsx → 后台解析 → 展示导入批次与进度/匹配统计。
 * 批准任务时，可自动执行类型由服务端下发到客户端代理执行。
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('read', READ);

// ── 上传处理 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'upload') {
    // 上传属变更操作，权限对齐 deploy 模块：需 deploy 或 admin，read 只能看
    if (!Session::haveRight('plugin_admanager_deploy', CREATE)
        && !Session::haveRight('plugin_admanager_admin', CREATE)) {
        Session::addMessageAfterRedirect('无权限：需要部署管理或插件管理权限才能上传', true, ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }
    // CSRF 由 GLPI inc/includes.php 全局校验统一处理，此处不再重复校验
    if (!empty($_FILES['vuln_file']['tmp_name'])) {
        $result = PluginAdmanagerVuln::uploadXlsx($_FILES['vuln_file']);
    } else {
        $result = ['ok' => false, 'message' => '请选择 xlsx 文件'];
    }
    Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
    Html::redirect($_SERVER['PHP_SELF']);
}

$imports  = PluginAdmanagerVuln::getImports();
$canWrite = Session::haveRight('plugin_admanager_deploy', CREATE)
            || Session::haveRight('plugin_admanager_admin', CREATE);

Html::header('漏洞管理 · 导入记录', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'admanager_vuln');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/vuln_import.html.twig', [
    'imports'    => $imports,
    'can_write'  => $canWrite,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();
