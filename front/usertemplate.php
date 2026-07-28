<?php
/**
 * front/usertemplate.php — 用户创建模板管理页面
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$templates  = PluginAdmanagerUserTemplate::getAll();
$all_fields = PluginAdmanagerUserTemplate::allFields();

// 获取 AD OU 列表（供 OU 字段下拉用）
try {
    $ldap = PluginAdmanagerAdLdap::getInstance();
    $ous = $ldap->listOUs();
} catch (\Throwable $e) {
    $ous = [];
}

Html::header('用户创建模板', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'aduser');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/usertemplate.html.twig', [
    'templates'  => $templates,
    'all_fields' => $all_fields,
    'ous'        => $ous,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();