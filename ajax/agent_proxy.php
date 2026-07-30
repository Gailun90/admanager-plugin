<?php
/**
 * ajax/agent_proxy.php — Agent 对话代理端点
 *
 * 解决浏览器跨域 / 127.0.0.1 不可达问题：
 * 前端 JS 发请求到本文件（同源），本文件通过 curl 转发到 FastAPI 后端（127.0.0.1:8000）。
 *
 * 端点：
 *  POST  ?action=chat            → SSE 流式对话（透传流）
 *  GET   ?action=suggestions&q=  → @ 引用补全
 *  POST  ?action=upload          → 文件上传
 *  GET   ?action=files           → 工作区文件列表
 *  DELETE ?action=delete&filename= → 删除文件
 *  POST  ?action=analyze         → AI 分析文件
 */

// 先开输出缓冲，吃掉 includes.php 的所有意外输出
ob_start();

include('../../../inc/includes.php');

// includes.php 会重置 error_reporting，必须在它之后关闭
error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('zlib.output_compression', '0');

// 丢弃 includes.php 产生的所有输出（PHP 警告、HTML 等）
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// 鉴权：需要登录
if (!Session::getLoginUserID()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => '未登录']);
    exit;
}

PluginAdmanagerProfile::checkRight('read', READ);

// 权限修正：本文件原来所有操作（含 chat/upload/delete/prompt-PUT 这些会让 AI
// 执行命令、改规则、删任务、改全局 Prompt 的写操作）都只要求最低的 read 权限，
// 跟 deploy.php 等页面"写操作单独要求 admin+CREATE"的惯例不一致。
// 这里对会改变系统状态的操作额外要求写权限，只读类操作（suggestions/files/logs/
// sessions/prompt-GET）维持 read 即可。
$__action = $_GET['action'] ?? '';
$__method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$__mutatingAction = in_array($__action, ['chat', 'upload', 'delete'], true)
    || ($__action === 'prompt' && $__method === 'PUT');
if ($__mutatingAction && !PluginAdmanagerProfile::canDo('admin', CREATE)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => '权限不足：此操作需要插件管理权限']);
    exit;
}

// 获取 FastAPI 配置
$cfg = PluginAdmanagerConfig::getFastApiConfig();
$fastapiUrl  = rtrim($cfg['url'] ?? '', '/');
$fastapiToken = $cfg['token'] ?? '';

// 确保 URL 有 http:// 前缀
if ($fastapiUrl && !preg_match('#^https?://#', $fastapiUrl)) {
    $fastapiUrl = 'http://' . $fastapiUrl;
}

if (!$fastapiUrl) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'FastAPI 地址未配置']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {

// ════════════════════════════════════════════════════════════════════════════
// SSE 流式对话 — 透传 FastAPI 的 SSE 响应
// ════════════════════════════════════════════════════════════════════════════
case 'chat':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }

    // 读取请求体
    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody, true) ?: [];

    // 注入操作者信息（GLPI 用户名 + IP，用于审计日志）
    if (!isset($body['operator'])) {
        $body['operator'] = PluginAdmanagerVuln::operator();
    }
    $body['client_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';

    // 设置 SSE 响应头
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    // 禁用 PHP 输出缓冲
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    // 清除所有剩余的输出缓冲（includes.php 可能重新开启了缓冲）
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    @ob_implicit_flush(true);

    // curl 流式请求
    $ch = curl_init($fastapiUrl . '/api/agent/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $fastapiToken,
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ],
        CURLOPT_TIMEOUT        => 900,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) {
            echo $data;
            @flush();
            return strlen($data);
        },
        CURLOPT_HEADER         => false,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        echo "data: " . json_encode(['type' => 'error', 'content' => '连接 FastAPI 失败: ' . $error]) . "\n\n";
        @flush();
    } elseif ($httpCode >= 400) {
        // FastAPI 返回错误（如 503 LLM 未启用），输出为 SSE error 事件
        echo "data: " . json_encode(['type' => 'error', 'content' => "FastAPI 返回 HTTP {$httpCode}"]) . "\n\n";
        @flush();
    }
    break;

// ════════════════════════════════════════════════════════════════════════════
// @ 引用补全
// ════════════════════════════════════════════════════════════════════════════
case 'suggestions':
    $q = $_GET['q'] ?? '';
    $url = $fastapiUrl . '/api/agent/suggestions?' . http_build_query(['q' => $q]);
    echo _proxy_get($url, $fastapiToken);
    break;

// ════════════════════════════════════════════════════════════════════════════
// 文件上传
// ════════════════════════════════════════════════════════════════════════════
case 'upload':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => '没有上传文件']);
        exit;
    }
    $file = $_FILES['file'];
    $url = $fastapiUrl . '/api/agent/workspace/upload';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file' => new CURLFile(
                $file['tmp_name'],
                $file['type'] ?: 'application/octet-stream',
                $file['name']
            ),
        ],
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $fastapiToken,
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    http_response_code($code);
    header('Content-Type: application/json');
    echo $resp ?: json_encode(['error' => '上传失败']);
    break;

// ════════════════════════════════════════════════════════════════════════════
// 工作区文件列表
// ════════════════════════════════════════════════════════════════════════════
case 'files':
    $url = $fastapiUrl . '/api/agent/workspace/files';
    echo _proxy_get($url, $fastapiToken);
    break;

// ════════════════════════════════════════════════════════════════════════════
// 删除工作区文件
// ════════════════════════════════════════════════════════════════════════════
case 'delete':
    $filename = $_GET['filename'] ?? '';
    if (!$filename) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => '缺少 filename 参数']);
        exit;
    }
    $url = $fastapiUrl . '/api/agent/workspace/files/' . urlencode($filename);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST   => 'DELETE',
        CURLOPT_HTTPHEADER      => [
            'Authorization: Bearer ' . $fastapiToken,
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    http_response_code($code);
    header('Content-Type: application/json');
    echo $resp ?: json_encode(['error' => '删除失败']);
    break;

// ════════════════════════════════════════════════════════════════════════════
// AI 分析文件
// ════════════════════════════════════════════════════════════════════════════
case 'analyze':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
    $body = file_get_contents('php://input');
    $url = $fastapiUrl . '/api/agent/workspace/analyze';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $fastapiToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    http_response_code($code);
    header('Content-Type: application/json');
    echo $resp ?: json_encode(['error' => '分析失败']);
    break;

// ════════════════════════════════════════════════════════════════════════════
// Agent Prompt 管理
// ════════════════════════════════════════════════════════════════════════════
case 'prompt':
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $url = $fastapiUrl . '/api/agent/prompt';
        echo _proxy_get($url, $fastapiToken);
    } elseif ($method === 'PUT') {
        $body = file_get_contents('php://input');
        $url = $fastapiUrl . '/api/agent/prompt';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST   => 'PUT',
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_HTTPHEADER      => [
                'Authorization: Bearer ' . $fastapiToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        http_response_code($code);
        header('Content-Type: application/json');
        echo $resp ?: json_encode(['error' => '更新失败']);
    } else {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['error' => '不支持的请求方法']);
    }
    break;

// ════════════════════════════════════════════════════════════════════════════
// Agent 对话审计日志
// ════════════════════════════════════════════════════════════════════════════
case 'logs':
    $url = $fastapiUrl . '/api/agent/logs?' . http_build_query($_GET);
    echo _proxy_get($url, $fastapiToken);
    break;

case 'sessions':
    $url = $fastapiUrl . '/api/agent/logs/sessions?' . http_build_query($_GET);
    echo _proxy_get($url, $fastapiToken);
    break;

default:
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => '未知操作: ' . $action]);
    exit;
}


// ════════════════════════════════════════════════════════════════════════════
// 辅助函数
// ════════════════════════════════════════════════════════════════════════════

function _proxy_get(string $url, string $token): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER      => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    http_response_code($code ?: 502);
    header('Content-Type: application/json');
    return $resp ?: json_encode(['error' => '请求失败']);
}
