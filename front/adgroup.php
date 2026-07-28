<?php
/**
 * front/adgroup.php — AD 安全组管理
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

Html::header('AD安全组', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'admanager_adgroup');
\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/adgroup.html.twig', [
    'can_write'  => PluginAdmanagerProfile::canDo('admin', CREATE),
    'csrf_token' => Session::getNewCSRFToken(),
]);
Html::footer();
