<?php
/**
 * inc/vuln.class.php — 漏洞扫描 AI 辅助修复（含执行通道）
 *
 * 职责：封装 itasset FastAPI /api/vuln/* 接口调用，供 front/vuln*.php 使用。
 * 风格参照 PluginAdmanagerDeploy（异常吞掉返回空/错误消息，页面不白屏）。
 *
 * 执行通道：批准时，registry_fix/software_uninstall 自动下发客户端代理执行并回写结果。
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerVuln
{
    /** 当前 GLPI 登录名（透传给 FastAPI 做 uploaded_by / operator） */
    public static function operator(): string {
        return $_SESSION['glpiname'] ?? ('user#' . (Session::getLoginUserID() ?: 0));
    }

    // ── 导入批次 ──────────────────────────────────────────────────────────

    /**
     * 上传 xlsx 到 FastAPI（multipart，独立 curl：FastApiClient 只支持 JSON body）
     */
    public static function uploadXlsx(array $file): array {
        $safeName = basename($file['name']);
        if (!preg_match('/\.xlsx$/i', $safeName)) {
            return ['ok' => false, 'message' => '仅支持 .xlsx 文件'];
        }
        if ($file['size'] > 20 * 1024 * 1024) {
            return ['ok' => false, 'message' => '文件超过 20MB 上限'];
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'message' => '上传文件无效'];
        }

        $cfg = PluginAdmanagerConfig::getFastApiConfig();
        $url = $cfg['url'] . '/api/vuln/imports?' . http_build_query([
            'uploaded_by' => self::operator(),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(60, (int)$cfg['timeout']),
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $cfg['token'],
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => [
                'file' => new CURLFile($file['tmp_name'],
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    $safeName),
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err)         return ['ok' => false, 'message' => "连接 FastAPI 失败：{$err}"];
        $data = json_decode($raw, true);
        if ($code >= 400) {
            $detail = $data['detail'] ?? $raw;
            return ['ok' => false, 'message' => "上传失败（HTTP {$code}）：" . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE))];
        }

        PluginAdmanagerAuditLog::write(
            'vuln_import', 'vuln', (string)($data['id'] ?? ''), $safeName,
            ['filename' => $safeName, 'size' => $file['size']], true, ''
        );
        return ['ok' => true, 'message' => "已上传（批次 #{$data['id']}），后台解析中…", 'import' => $data];
    }

    public static function getImports(): array {
        try {
            return PluginAdmanagerFastApiClient::getInstance()->get('/api/vuln/imports') ?: [];
        } catch (Exception $e) { return []; }
    }

    /** 重新解析已上传批次（清空旧 findings/tasks 后用当前 AI 配置重跑） */
    public static function reparseImport(int $importId): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->post(
                "/api/vuln/imports/{$importId}/reparse", []
            );
            PluginAdmanagerAuditLog::write('vuln_reparse', 'vuln',
                (string)$importId, '', [], true, '');
            return ['ok' => true, 'message' => "批次 #{$importId} 已重新提交解析，后台运行中…", 'import' => $data];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '重新解析失败：' . $e->getMessage()];
        }
    }

    public static function getImportStats(int $importId): array {
        try {
            return PluginAdmanagerFastApiClient::getInstance()->get("/api/vuln/imports/{$importId}");
        } catch (Exception $e) { return []; }
    }

    public static function getFindings(int $importId, string $match = ''): array {
        try {
            $q = $match !== '' ? ['match' => $match] : [];
            return PluginAdmanagerFastApiClient::getInstance()
                ->get("/api/vuln/imports/{$importId}/findings", $q) ?: [];
        } catch (Exception $e) { return []; }
    }

    /** 人工修正资产匹配 */
    public static function resolveMatch(int $findingId, ?int $assetId): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->post(
                "/api/vuln/findings/{$findingId}/resolve-match",
                ['asset_id' => $assetId, 'regenerate_task' => true]
            );
            PluginAdmanagerAuditLog::write('vuln_resolve_match', 'vuln',
                (string)$findingId, (string)($data['dns_name'] ?? ''),
                ['asset_id' => $assetId], true, '');
            return ['ok' => true, 'message' => '匹配已修正', 'finding' => $data];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '修正失败：' . $e->getMessage()];
        }
    }

    // ── 修复任务 ──────────────────────────────────────────────────────────

    public static function getTasks(string $status = '', int $importId = 0, string $risk = ''): array {
        try {
            $q = [];
            if ($status !== '') $q['status'] = $status;
            if ($importId > 0)  $q['import_id'] = $importId;
            if ($risk !== '')   $q['risk'] = $risk;
            return PluginAdmanagerFastApiClient::getInstance()->get('/api/vuln/tasks', $q) ?: [];
        } catch (Exception $e) { return []; }
    }

    public static function getTaskDetail(int $taskId): array {
        try {
            return PluginAdmanagerFastApiClient::getInstance()->get("/api/vuln/tasks/{$taskId}");
        } catch (Exception $e) { return []; }
    }

    /** approve / reject / mark-manual / dispatch（approve/dispatch 对可执行类型触发下发） */
    public static function taskAction(int $taskId, string $action): array {
        $map = ['approve' => 'approve', 'reject' => 'reject',
                'mark_manual' => 'mark-manual', 'dispatch' => 'dispatch'];
        if (!isset($map[$action])) {
            return ['ok' => false, 'message' => '非法操作'];
        }
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->post(
                "/api/vuln/tasks/{$taskId}/{$map[$action]}",
                ['operator' => self::operator()]
            );
            PluginAdmanagerAuditLog::write('vuln_task_' . $action, 'vuln',
                (string)$taskId, (string)($data['title'] ?? ''),
                ['task_id' => $taskId], true, '');
            $labels = ['approve' => '已批准', 'reject' => '已拒绝',
                       'mark_manual' => '已标记为手动处理', 'dispatch' => '已确认下发'];
            $msg = "任务 #{$taskId} {$labels[$action]}";
            // 批准但被门禁拦下（规则未转正/高风险）时，把原因透传给操作者
            if (!empty($data['dispatch_block_reason'])) {
                $msg .= '（未自动下发：' . $data['dispatch_block_reason'] . '）';
            }
            return ['ok' => true, 'message' => $msg, 'task' => $data];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '操作失败：' . $e->getMessage()];
        }
    }

    public static function batchApprove(array $taskIds): array {
        $taskIds = array_values(array_filter(array_map('intval', $taskIds), fn($i) => $i > 0));
        if (!$taskIds) return ['ok' => false, 'message' => '未选择任务'];
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->post(
                '/api/vuln/tasks/batch-approve',
                ['task_ids' => $taskIds, 'operator' => self::operator()]
            );
            PluginAdmanagerAuditLog::write('vuln_batch_approve', 'vuln',
                '', '', ['count' => count($taskIds), 'ids' => $taskIds], true, '');
            return ['ok' => true, 'message' => $data['message'] ?? '批量批准完成', 'result' => $data];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '批量批准失败：' . $e->getMessage()];
        }
    }

    /** 重新匹配软件安装包（software_upgrade 专用，对应后端 /api/vuln/tasks/{id}/rematch-package） */
    public static function rematchPackage(int $taskId): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->post(
                "/api/vuln/tasks/{$taskId}/rematch-package",
                ['operator' => self::operator()]
            );
            PluginAdmanagerAuditLog::write('vuln_rematch_package', 'vuln',
                (string)$taskId, '', ['task_id' => $taskId], true, '');
            $matched = !empty($data['matched_package_id']);
            $msg = $matched
                ? "任务 #{$taskId} 已匹配到安装包 #{$data['matched_package_id']}，可点「确认下发」执行升级"
                : ("任务 #{$taskId} 仍未匹配到安装包，请先在软件部署库上传/关联对应安装包");
            return ['ok' => true, 'message' => $msg, 'task' => $data];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '重新匹配失败：' . $e->getMessage()];
        }
    }

    // ── 规则库 ────────────────────────────────────────────────────────────

    public static function getRules(string $status = ''): array {
        try {
            $q = $status !== '' ? ['status' => $status] : [];
            return PluginAdmanagerFastApiClient::getInstance()->get('/api/vuln/rules', $q) ?: [];
        } catch (Exception $e) { return []; }
    }

    public static function createRule(array $fields): array {
        // action_template / rollback_plan 可能以 JSON 字符串形式由表单提交，解码为数组
        foreach (['action_template', 'rollback_plan'] as $k) {
            if (isset($fields[$k]) && is_string($fields[$k])) {
                $fields[$k] = $fields[$k] === ''
                    ? null : (json_decode($fields[$k], true) ?: null);
            }
        }
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->post('/api/vuln/rules', $fields);
            PluginAdmanagerAuditLog::write('vuln_rule_create', 'vuln',
                (string)($data['id'] ?? ''), $fields['qid'] ?? '', $fields, true, '');
            return ['ok' => true, 'message' => "规则 QID {$fields['qid']} 已创建"];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '创建失败：' . $e->getMessage()];
        }
    }

    public static function updateRule(int $ruleId, array $fields): array {
        foreach (['action_template', 'rollback_plan'] as $k) {
            if (isset($fields[$k]) && is_string($fields[$k])) {
                $fields[$k] = $fields[$k] === ''
                    ? null : (json_decode($fields[$k], true) ?: null);
            }
        }
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->put("/api/vuln/rules/{$ruleId}", $fields);
            PluginAdmanagerAuditLog::write('vuln_rule_update', 'vuln',
                (string)$ruleId, (string)($data['qid'] ?? ''), $fields, true, '');
            return ['ok' => true, 'message' => '规则已更新'];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '更新失败：' . $e->getMessage()];
        }
    }

    public static function deleteRule(int $ruleId): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->delete("/api/vuln/rules/{$ruleId}");
            PluginAdmanagerAuditLog::write('vuln_rule_delete', 'vuln',
                (string)$ruleId, '', [], true, '');
            return ['ok' => true, 'message' => $data['message'] ?? '规则已删除'];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '删除失败：' . $e->getMessage()];
        }
    }

    // ── 辅助 ──────────────────────────────────────────────────────────────

    /** 终端列表（人工修正匹配下拉用），异常时空数组 */
    public static function getClients(): array {
        return PluginAdmanagerDeploy::getClients();
    }

    public static function fixTypeLabel(string $t): string {
        return [
            'registry_fix'       => '注册表修复',
            'software_upgrade'   => '软件升级',
            'software_uninstall' => '软件卸载',
            'patch_install'      => '补丁安装',
            'manual_review'      => '人工处理',
            'unsupported'        => '暂不支持',
        ][$t] ?? $t;
    }

    public static function riskLabel(string $r): string {
        return ['low' => '低', 'medium' => '中', 'high' => '高'][$r] ?? $r;
    }

    /**
     * 把 action_template 变成一句人能看懂的摘要，不用再跳到别的页面查"卸载的到底是什么"。
     * 覆盖当前实际出现过的几种 fix_type 数据形状；未知/新形状兜底显示 description 或原始 JSON 片段。
     */
    public static function actionSummary(string $fixType, $action): string {
        if (!is_array($action) || empty($action)) {
            return '（无动作模板）';
        }
        switch ($fixType) {
            case 'software_uninstall':
            case 'software_upgrade':
                $sw = $action['software'] ?? $action['uninstall_target'] ?? $action['package_name'] ?? '';
                $ver = $action['target_version'] ?? '';
                $verb = $fixType === 'software_uninstall' ? '卸载' : '升级到';
                return $sw !== ''
                    ? trim("{$verb}「{$sw}」" . ($ver !== '' ? " {$ver}" : ''))
                    : ($action['description'] ?? '（未指定目标软件）');

            case 'registry_fix':
                $changes = $action['changes'] ?? [];
                if (!$changes) return $action['description'] ?? '（无注册表变更项）';
                $parts = [];
                foreach ($changes as $ch) {
                    $act = $ch['action'] ?? 'set';
                    $root = $ch['root'] ?? 'HKLM';
                    $subkey = $ch['subkey'] ?? '';
                    $name = $ch['name'] ?? '';
                    $value = $ch['value'] ?? '';
                    $parts[] = $act === 'delete'
                        ? ('删除 ' . $root . '\\' . $subkey . '\\' . $name)
                        : ($root . '\\' . $subkey . '\\' . $name . ' = ' . $value);
                }
                return implode('；', $parts);

            case 'patch_install':
                $kbs = $action['kb_ids'] ?? [];
                return $kbs ? ('安装补丁：' . implode(', ', $kbs)) : ($action['description'] ?? '（未指定 KB 编号）');

            case 'manual_review':
                return '需人工处理：' . ($action['reason'] ?? $action['description'] ?? '未说明原因');

            default:
                return $action['description'] ?? ('（' . substr(json_encode($action, JSON_UNESCAPED_UNICODE), 0, 80) . '…）');
        }
    }

    public static function statusLabel(string $s): string {
        return [
            'pending'      => '待审批',
            'approved'     => '已批准',
            'rejected'     => '已拒绝',
            'needs_manual' => '已手动处理',
            'dispatched'   => '已下发',
            'done'         => '执行成功',
            'failed'       => '执行失败',
            'pending_verify'    => '待后校验',
            'rollback_required' => '需回滚',
        ][$s] ?? $s;
    }

    // ── 全局熔断开关 ──────────────────────────────────────────────────────
    public static function getKillSwitch(): array {
        try {
            $resp = PluginAdmanagerFastApiClient::getInstance()->get('/api/vuln/kill-switch');
            return $resp ?: ['kill_switch' => false];
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => '查询熔断状态失败: ' . $e->getMessage()];
        }
    }

    public static function toggleKillSwitch(): array {
        try {
            $resp = PluginAdmanagerFastApiClient::getInstance()->post('/api/vuln/kill-switch/toggle', [
                'operator' => self::operator(),
            ]);
            // FastApiClient 返回的是数组，需要包装成 OK 格式
            if (isset($resp['kill_switch'])) {
                return array_merge(['ok' => true], $resp);
            }
            return ['ok' => false, 'message' => '切换熔断失败，API 返回异常'];
        } catch (\Exception $e) {
        return ['ok' => false, 'message' => '切换熔断失败: ' . $e->getMessage()];
        }
    }

    // ── 对话式纠正（最终形态·三）─────────────────────────────────────────

    /**
     * 列出对话式纠正缓存（可按 qid 过滤）。
     */
    public static function getCorrections(string $qid = ''): array {
        try {
            $q = $qid !== '' ? ['qid' => $qid] : [];
            return PluginAdmanagerFastApiClient::getInstance()->get('/api/vuln/corrections', $q) ?: [];
        } catch (Exception $e) { return []; }
    }

    /**
     * 对某修复任务提交人工纠正（对话式规则核心入口）。
     * $correctedAction 可以是 JSON 字符串（表单 textarea）或数组。
     */
    public static function correctTask(int $taskId, string $fixType, $correctedAction,
                                        string $note = '', bool $promoteToRule = false): array {
        // corrected_action: 字符串 → 解码为数组
        if (is_string($correctedAction)) {
            $decoded = json_decode($correctedAction, true);
            if ($decoded === null && trim($correctedAction) !== '') {
                return ['ok' => false, 'message' => '纠正动作 JSON 格式无效'];
            }
            $correctedAction = $decoded ?: new \stdClass();
        }
        if (!is_array($correctedAction) && !($correctedAction instanceof \stdClass)) {
            $correctedAction = new \stdClass();
        }
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->post(
                "/api/vuln/tasks/{$taskId}/correct",
                [
                    'fix_type'         => $fixType,
                    'corrected_action' => $correctedAction,
                    'note'             => $note ?: null,
                    'promote_to_rule'  => $promoteToRule,
                    'operator'         => self::operator(),
                ]
            );
            PluginAdmanagerAuditLog::write('vuln_correct', 'vuln',
                (string)$taskId, (string)($data['qid'] ?? ''),
                ['fix_type' => $fixType, 'promote' => $promoteToRule], true, '');
            $msg = "任务 #{$taskId} 纠正已提交";
            if ($promoteToRule) {
                $msg .= '，已沉淀为规则（待金丝雀观察）';
            }
            return ['ok' => true, 'message' => $msg, 'task' => $data];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '纠正失败：' . $e->getMessage()];
        }
    }

    /**
     * 把一条纠正沉淀为正式规则（source=manual, canary_status=pending）。
     */
    public static function promoteCorrection(int $corrId): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->post(
                "/api/vuln/corrections/{$corrId}/promote",
                ['operator' => self::operator()]
            );
            PluginAdmanagerAuditLog::write('vuln_promote_correction', 'vuln',
                (string)$corrId, (string)($data['qid'] ?? ''),
                ['rule_id' => $data['id'] ?? null], true, '');
            return ['ok' => true, 'message' => "纠正 #{$corrId} 已沉淀为规则 QID {$data['qid']}（待金丝雀观察）", 'rule' => $data];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '沉淀失败：' . $e->getMessage()];
        }
    }

    /**
     * 删除一条纠正缓存。
     */
    public static function deleteCorrection(int $corrId): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->delete("/api/vuln/corrections/{$corrId}");
            PluginAdmanagerAuditLog::write('vuln_delete_correction', 'vuln',
                (string)$corrId, '', [], true, '');
            return ['ok' => true, 'message' => $data['message'] ?? "纠正 #{$corrId} 已删除"];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '删除失败：' . $e->getMessage()];
        }
    }

    // ── AI 辅助对话纠正 ──────────────────────────────────────────────────

    /**
     * AI 辅助纠正：操作者用自然语言描述修复方式，LLM 生成结构化动作供确认（不落库）。
     * 确认后由前端再调 correctTask 落库 + 下发。
     */
    public static function aiCorrectTask(int $taskId, string $instruction): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->post(
                "/api/vuln/tasks/{$taskId}/ai-correct",
                ['instruction' => $instruction, 'operator' => self::operator()]
            );
            PluginAdmanagerAuditLog::write('vuln_ai_correct', 'vuln',
                (string)$taskId, '', ['instruction' => $instruction], true, '');
            return ['ok' => true, 'data' => $data];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => 'AI 纠正失败：' . $e->getMessage()];
        }
    }
}
