<?php
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', UPDATE);

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {

    PluginAdmanagerConfig::saveAll($_POST);
    Session::addMessageAfterRedirect('配置已保存', true, INFO);
    Html::redirect($_SERVER['PHP_SELF']);
}

$config = PluginAdmanagerConfig::getAll();
// 已加密字段不回显明文，仅显示占位
foreach (PluginAdmanagerConfig::ENCRYPTED_FIELDS as $f) {
    if (!empty($config[$f])) $config[$f] = '********';
}

Html::header('连接配置', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'config');
\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/config.html.twig', [
    'config'      => $config,
    'cert_files'  => PluginAdmanagerConfig::listCertificates(),
    'csrf_token' => Session::getNewCSRFToken(),
]);
Html::footer();

