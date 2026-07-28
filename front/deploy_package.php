<?php
/**
 * front/deploy_package.php — 安装包库页面
 * 列表 + 上传（带进度条的同页 XHR）/ 编辑 / 删除。
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$can_write = PluginAdmanagerProfile::canDo('admin', CREATE);
$isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

// ── POST 处理 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    $act = $_POST['_action'] ?? '';

    // 上传安装包（前端用同页 XHR 提交，带进度条，需要返回 JSON）
    if ($act === 'upload_package') {
        if (empty($_FILES['package']) || ($_FILES['package']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $result = ['ok' => false, 'message' => '请选择要上传的安装包文件'];
        } else {
            $result = PluginAdmanagerDeploy::uploadPackage(
                $_FILES['package'],
                trim($_POST['pkg_name']    ?? ''),
                trim($_POST['pkg_version'] ?? ''),
                trim($_POST['silent_args'] ?? ''),
                trim($_POST['pkg_desc']    ?? '')
            );
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }

    // 删除安装包
    if ($act === 'delete_package' && !empty($_POST['pkg_id'])) {
        $result = PluginAdmanagerDeploy::deletePackage((int)$_POST['pkg_id']);
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }

    // 编辑安装包元数据
    if ($act === 'update_package' && !empty($_POST['pkg_id'])) {
        $result = PluginAdmanagerDeploy::updatePackage(
            (int)$_POST['pkg_id'],
            trim($_POST['pkg_name']    ?? ''),
            trim($_POST['pkg_version'] ?? ''),
            trim($_POST['silent_args'] ?? ''),
            trim($_POST['pkg_desc']    ?? '')
        );
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }
}

// ── 数据准备 ──
$packages = PluginAdmanagerDeploy::getPackages();

Html::header('安装包库', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'deploy');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/deploy_package.html.twig', [
    'packages'   => $packages,
    'can_write'  => $can_write,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();
