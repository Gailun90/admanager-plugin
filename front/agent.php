<?php
/**
 * front/agent.php — AI Agent 对话页（漏洞修复智能助手）
 *
 * 功能：
 *  - 类 ChatGPT 对话界面，支持流式响应（SSE）
 *  - @ 引用终端/QID/分组
 *  - Agent 工作区（文件上传、预览、AI分析、推送）
 *  - 工具调用可视化（展示 Agent 调用了哪些工具及结果）
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('read', READ);

// 获取 FastAPI 配置
$fastapiCfg = PluginAdmanagerConfig::getFastApiConfig();
$fastapiUrl  = rtrim($fastapiCfg['url'] ?? '', '/');
$fastapiToken = $fastapiCfg['token'] ?? '';

// 透传给前端 JS
$jsConfig = json_encode([
    'fastapi_url'   => $fastapiUrl,
    'fastapi_token' => $fastapiToken,
    'operator'      => PluginAdmanagerVuln::operator(),
], JSON_UNESCAPED_UNICODE);

$canWrite = Session::haveRight('plugin_admanager_deploy', CREATE)
            || Session::haveRight('plugin_admanager_admin', CREATE);

Html::header('AI Agent · 终端安全运维助手', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'admanager_vuln');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/agent.html.twig', [
    'config'     => $jsConfig,
    'can_write'  => $canWrite,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();
