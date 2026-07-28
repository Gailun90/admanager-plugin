<?php
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('read', READ);
Html::header('总览', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'dashboard');

// 只获取快速数据（AD统计来自DB缓存），慢数据走异步AJAX
$ad_stats = PluginAdmanagerDashboard::getAdStats();

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/dashboard.html.twig', [
    'ad_stats'   => $ad_stats,
]);
Html::footer();
