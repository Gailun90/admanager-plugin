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

// 注意：本页【绝不】触碰 FastAPI 凭据。AI 对话统一走 agent_proxy.php 代理，
// 由服务端持有并解密 token；agent_chat.js 也从不读取任何 fastapi_url/token，
// 浏览器端无需亦不应拿到明文凭据。因此此处不 fetch 任何 FastAPI 配置。
$jsConfig = json_encode([
    'operator' => PluginAdmanagerVuln::operator(),
], JSON_UNESCAPED_UNICODE);

$canWrite = Session::haveRight('plugin_admanager_deploy', CREATE)
            || Session::haveRight('plugin_admanager_admin', CREATE);

Html::header('AI助手 · 终端安全运维助手', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'admanager_vuln');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/agent.html.twig', [
    'config'     => $jsConfig,
    'can_write'  => $canWrite,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();
