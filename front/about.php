<?php
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('read', READ);
Html::header('关于', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'about');
\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/about.html.twig', []);
Html::footer();
