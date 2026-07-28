<?php
/**
 * front/im.php — 通讯平台关联管理页面
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$cfg = PluginAdmanagerConfig::getAll();
$platforms = [];
foreach (['wecom'=>'企业微信','dingtalk'=>'钉钉','feishu'=>'飞书'] as $k => $label) {
    $platforms[$k] = [
        'label'   => $label,
        'enabled' => ($cfg["{$k}_enabled"] ?? '0') === '1',
    ];
}

Html::header('通讯平台', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'admanager_im');
\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/im.html.twig', [
    'can_write'  => PluginAdmanagerProfile::canDo('admin', CREATE),
    'csrf_token' => Session::getNewCSRFToken(),
    'platforms'  => $platforms,
]);
Html::footer();
