<?php
/**
 * front/agentlog.php — AI Agent 对话审计日志
 *
 * 从 FastAPI itasset 后端拉取 agent_conversation_logs 并展示。
 * 与 agent.php 互为页签（顶部 vuln-subtabs 导航）。
 *
 * GET 参数：
 *   operator   按操作者过滤
 *   role       按角色过滤（user/assistant/tool_call/tool_result/error）
 *   session_id 按会话 ID 过滤
 *   page       分页
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('read', READ);

// ── 筛选 / 分页参数 ──
$filters = [
    'session_id' => trim($_GET['session_id'] ?? ''),
    'operator'   => trim($_GET['operator']   ?? ''),
    'role'       => trim($_GET['role']       ?? ''),
    'start_date' => trim($_GET['start_date'] ?? ''),
    'end_date'   => trim($_GET['end_date']   ?? ''),
];
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 200;
$offset = ($page - 1) * $limit;

// 角色中文 + 配色
$roleMeta = [
    'user'        => ['label' => '用户',     'cls' => 'bg-primary'],
    'assistant'   => ['label' => 'AI 回复',  'cls' => 'bg-success'],
    'tool_call'   => ['label' => '工具调用', 'cls' => 'bg-warning text-dark'],
    'tool_result' => ['label' => '工具结果', 'cls' => 'bg-info text-dark'],
    'error'       => ['label' => '错误',     'cls' => 'bg-danger'],
];

// 时间格式化（UTC → 本地 Asia/Shanghai）
function fmt_agent_ts($iso) {
    if (!$iso) return '';
    try {
        $d = new DateTime($iso);
        $d->setTimezone(new DateTimeZone('Asia/Shanghai'));
        return $d->format('Y-m-d H:i:s');
    } catch (\Throwable $e) {
        return str_replace('T', ' ', explode('.', (string)$iso)[0]);
    }
}

// ── 调用 FastAPI 获取日志 ──
$logs   = [];
$apiErr = '';
$total  = 0;
try {
    $cfg  = PluginAdmanagerConfig::getFastApiConfig();
    $base = rtrim($cfg['url'] ?? '', '/');
    if ($base && !preg_match('#^https?://#', $base)) {
        $base = 'http://' . $base;
    }
    $token = $cfg['token'] ?? '';
    if (!$base) {
        $apiErr = 'FastAPI 地址未配置';
    } else {
        $qs = http_build_query(array_filter([
            'session_id' => $filters['session_id'],
            'operator'   => $filters['operator'],
            'role'       => $filters['role'],
            'start'      => $filters['start_date'],
            'end'        => $filters['end_date'],
            'limit'      => $limit,
            'offset'     => $offset,
        ]));
        $url = $base . '/api/agent/logs?' . $qs;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $apiErr = '连接 FastAPI 失败：' . $cerr;
        } elseif ($code >= 400) {
            $apiErr = "FastAPI 返回 HTTP {$code}";
        } else {
            $data = json_decode($resp, true) ?: [];
            $logs = $data['logs'] ?? [];
            $total = (int)($data['count'] ?? count($logs));
        }
    }
} catch (\Throwable $e) {
    $apiErr = '获取日志异常：' . $e->getMessage();
}

// 预格式化，方便 Twig 直接渲染
foreach ($logs as &$l) {
    $l['ts_fmt']    = fmt_agent_ts($l['timestamp'] ?? '');
    $rm             = $roleMeta[$l['role'] ?? ''] ?? ['label' => ($l['role'] ?? '未知'), 'cls' => 'bg-secondary'];
    $l['role_label'] = $rm['label'];
    $l['role_cls']   = $rm['cls'];
}
unset($l);

$returned = count($logs);
// 接口未返回真实总数，用「本页是否填满」判断是否有下一页
$hasMore = $returned >= $limit;

// 分页链接（保留当前筛选）
$baseQ   = array_filter([
    'session_id' => $filters['session_id'],
    'operator'   => $filters['operator'],
    'role'       => $filters['role'],
    'start_date' => $filters['start_date'],
    'end_date'   => $filters['end_date'],
]);
$prevUrl = '?' . http_build_query($baseQ + ['page' => max(1, $page - 1)]);
$nextUrl = '?' . http_build_query($baseQ + ['page' => min($pages, $page + 1)]);

Html::header('AI助手 · 对话审计日志', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'admanager_vuln');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/agentlog.html.twig', [
    'logs'       => $logs,
    'filters'    => $filters,
    'page'       => $page,
    'has_more'   => $hasMore,
    'prev_url'   => $prevUrl,
    'next_url'   => $nextUrl,
    'api_err'    => $apiErr,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();
