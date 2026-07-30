<?php
/**
 * front/vuln_correct.php — 漏洞修复 · 对话式纠正页
 *
 * 功能：展示执行失败/需回滚的修复任务及其 AI 修复方案（action_json），
 *       允许操作者对 AI 方案提交人工纠正（corrected_action），纠正可沉淀为正式规则。
 *       下次相同 QID 解析时直接复用纠正缓存，不再盲信 LLM。
 *
 * 对话式纠正闭环（最终形态·三）的前端入口：
 *   1. AI 生成修复方案 → 任务执行失败/回滚 → 操作者在此纠正
 *   2. 纠正过 Action Validator 安全闸门 → 记录 Correction 缓存
 *   3. 可选沉淀为正式规则（source=manual, canary_status=pending → 走金丝雀）
 */
// 对话纠正页有多次 AJAX POST（ai_correct → correct_task），需保留 CSRF token 不被消耗
define('GLPI_KEEP_CSRF_TOKEN', true);
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('read', READ);

// ── AJAX POST 处理 ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $act = $_POST['_action'] ?? '';

    if ($act === 'correct_task' && !empty($_POST['task_id'])) {
        $result = PluginAdmanagerVuln::correctTask(
            (int)$_POST['task_id'],
            trim($_POST['fix_type'] ?? ''),
            $_POST['corrected_action'] ?? '',
            trim($_POST['note'] ?? ''),
            ($_POST['promote_to_rule'] ?? '0') === '1'
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'ai_correct' && !empty($_POST['task_id'])) {
        $result = PluginAdmanagerVuln::aiCorrectTask(
            (int)$_POST['task_id'],
            trim($_POST['instruction'] ?? '')
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'promote_correction' && !empty($_POST['corr_id'])) {
        $result = PluginAdmanagerVuln::promoteCorrection((int)$_POST['corr_id']);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'delete_correction' && !empty($_POST['corr_id'])) {
        $result = PluginAdmanagerVuln::deleteCorrection((int)$_POST['corr_id']);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 数据准备 ───────────────────────────────────────────────────────────
// 加载 待审批 + 已批准(需纠正 manual_review) + 执行失败 + 需回滚 的任务
$pendingTasks  = PluginAdmanagerVuln::getTasks('pending');
$approvedTasks = PluginAdmanagerVuln::getTasks('approved');
$failedTasks   = PluginAdmanagerVuln::getTasks('failed');
$rollbackTasks = PluginAdmanagerVuln::getTasks('rollback_required');
$tasks = array_merge($rollbackTasks, $failedTasks, $pendingTasks, $approvedTasks);

// 加载终端列表，构建在线状态查找表（复用 last_seen 5 分钟窗口法）
$onlineMap = [];
try {
    $clients = PluginAdmanagerDeploy::getClients();
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $cutoff = (clone $now)->modify('-5 minutes');
    foreach ($clients as $c) {
        $host = strtolower($c['hostname'] ?? '');
        $serial = $c['serial'] ?? '';
        $isOnline = false;
        if (!empty($c['last_seen'])) {
            try {
                $last = new DateTime($c['last_seen'], new DateTimeZone('UTC'));
                $isOnline = ($last >= $cutoff);
            } catch (\Exception $e) {}
        }
        if ($host) $onlineMap[$host] = $isOnline;
        if ($serial) $onlineMap[$serial] = $isOnline;
    }
} catch (\Exception $e) {}

// 补充展示标签 + 加载任务详情（含 action_json）+ 终端在线状态
foreach ($tasks as &$t) {
    $t['fix_label']    = PluginAdmanagerVuln::fixTypeLabel($t['fix_type'] ?? '');
    $t['risk_label']   = PluginAdmanagerVuln::riskLabel($t['risk_level'] ?? '');
    $t['status_label'] = PluginAdmanagerVuln::statusLabel($t['status'] ?? '');
    // 在线状态：按 hostname 匹配
    $host = strtolower($t['asset_hostname'] ?? '');
    $t['is_online'] = $host && isset($onlineMap[$host]) ? $onlineMap[$host] : null;
    $detail = PluginAdmanagerVuln::getTaskDetail((int)($t['id'] ?? 0));
    if (!empty($detail)) {
        $t['action_json']   = $detail['action_json'] ?? null;
        $t['solution_raw']  = $detail['solution_raw'] ?? null;
        $t['result_log']    = $detail['result_log'] ?? null;
        $t['rollback_plan'] = $detail['rollback_plan'] ?? null;
    }
}
unset($t);

// 纠正缓存列表
$corrections = PluginAdmanagerVuln::getCorrections();
foreach ($corrections as &$c) {
    $c['fix_label'] = PluginAdmanagerVuln::fixTypeLabel($c['fix_type'] ?? '');
}
unset($c);

$canWrite = Session::haveRight('plugin_admanager_deploy', CREATE)
            || Session::haveRight('plugin_admanager_admin', CREATE);

Html::header('漏洞管理 · AI纠正', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'admanager_vuln');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/vuln_correct.html.twig', [
    'tasks'       => $tasks,
    'corrections' => $corrections,
    'can_write'   => $canWrite,
    'csrf_token'  => Session::getNewCSRFToken(),
]);

Html::footer();
