<?php
/**
 * front/vuln_rules.php — 漏洞修复 · QID 规则库页
 *
 * 功能：管理 QID → 修复策略映射（active/draft/disabled）。
 * 命中 active/draft 规则的任务会直接套用策略，不再调 LLM。
 * draft 规则由 LLM 解析结果自动生成，管理员可在此转正（改为 active）。
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('read', READ);

$rules = PluginAdmanagerVuln::getRules();
foreach ($rules as &$r) {
    $r['fix_label']          = PluginAdmanagerVuln::fixTypeLabel($r['fix_type'] ?? '');
    $r['risk_label']         = PluginAdmanagerVuln::riskLabel($r['default_risk_level'] ?? '');
    $r['status_label']       = $r['status'] === 'active' ? '生效' : ($r['status'] === 'draft' ? '草稿' : '停用');
    $r['status_badge_class'] = $r['status'] === 'active' ? 'bg-success'
                                : ($r['status'] === 'draft' ? 'bg-warning text-dark' : 'bg-secondary');
    $r['rollback_json']      = isset($r['rollback_plan']) ? json_encode($r['rollback_plan'], JSON_UNESCAPED_UNICODE) : '';
    $r['action_summary']     = PluginAdmanagerVuln::actionSummary($r['fix_type'] ?? '', $r['action_template'] ?? null);
    $r['action_json']        = isset($r['action_template']) ? json_encode($r['action_template'], JSON_UNESCAPED_UNICODE) : '';
}
unset($r);

$canWrite = Session::haveRight('plugin_admanager_deploy', CREATE)
            || Session::haveRight('plugin_admanager_admin', CREATE);

Html::header('漏洞修复 · QID规则库', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'admanager_vuln');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/vuln_rules.html.twig', [
    'rules'      => $rules,
    'can_write'  => $canWrite,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();
