<?php
/**
 * front/bitlocker.php — BitLocker 恢复密钥查询
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$computer   = $_GET['computer'] ?? '';
$keys       = [];
$error      = null;

try {
    if ($computer) {
        $keys = PluginAdmanagerAdLdap::getInstance()->queryBitLockerKeys($computer);
        // 审计记录
        PluginAdmanagerAuditLog::write('bitlocker_query', 'BitLocker', $computer, $computer,
            ['count' => count($keys)], true);
    }
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

Html::header('BitLocker密钥查询', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'bitlocker');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/bitlocker.html.twig', [
    'computer'   => $computer,
    'keys'       => $keys,
    'error'      => $error,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();
