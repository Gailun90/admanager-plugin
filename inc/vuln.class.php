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

    /** 人工指定安装包匹配（software_upgrade 专用，对应后端 /api/vuln/tasks/{id}/rematch-package 带 package_id） */
    public static function manualMatchPackage(int $taskId, int $packageId, ?int $assetId = null): array {
        try {
            $payload = ['operator' => self::operator(), 'package_id' => $packageId];
            if ($assetId !== null) {
                $payload['asset_id'] = $assetId;
            }
            $data = PluginAdmanagerFastApiClient::getInstance()->post(
                "/api/vuln/tasks/{$taskId}/rematch-package",
                $payload
            );
            PluginAdmanagerAuditLog::write('vuln_manual_match', 'vuln',
                (string)$taskId, '', ['task_id' => $taskId, 'package_id' => $packageId], true, '');
            $matched = !empty($data['matched_package_id']);
            $msg = $matched
                ? "任务 #{$taskId} 已人工匹配到安装包 #{$data['matched_package_id']}，可点「确认下发」执行升级"
                : "任务 #{$taskId} 人工匹配失败（指定的安装包不存在）";
            return ['ok' => true, 'message' => $msg, 'task' => $data];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '人工匹配失败：' . $e->getMessage()];
        }
    }

    /** 删除修复任务（清理重复/无效任务用） */
    public static function deleteTask(int $taskId): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->delete("/api/vuln/tasks/{$taskId}");
            PluginAdmanagerAuditLog::write('vuln_task_delete', 'vuln',
                (string)$taskId, '', [], true, '');
            return ['ok' => true, 'message' => $data['message'] ?? "任务 #{$taskId} 已删除"];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => '删除失败：' . $e->getMessage()];
        }
    }

    /** 拉取软件部署库安装包列表（人工匹配下拉用） */
    public static function getPackages(): array {
        try {
            return PluginAdmanagerFastApiClient::getInstance()->get('/api/packages') ?: [];
        } catch (Exception $e) { return []; }
    }

    // ── 规则库 ────────────────────────────────────────────────────────────

    public static function getRules(string $status = ''): array {
        try {
            $q = $status !== '' ? ['status' => $status] : [];
            return PluginAdmanagerFastApiClient::getInstance()->get('/api/vuln/rules', $q) ?: [];
        } catch (Exception $e) { return []; }
    }

    public static function createRule(array $fields): array {
        // action_template / rollback_plan 经 $_POST 提交时会被 GLPI Sanitizer 转义，
        // 故前端统一 base64 编码；此处优先 base64 解码，失败回退原始 json_decode（兼容旧调用）。
        foreach (['action_template', 'rollback_plan'] as $k) {
            if (isset($fields[$k]) && is_string($fields[$k])) {
                $fields[$k] = self::decodeJsonField($fields[$k]);
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
                $fields[$k] = self::decodeJsonField($fields[$k]);
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

    /**
     * 解码动作模板/回滚方案字段。
     * 前端经 $_POST 提交 JSON 时因 GLPI Sanitizer 会破坏引号，统一 base64 编码后透传；
     * 这里 strict 校验 base64 合法且能解出 JSON 才采用，否则回退原始 json_decode（兼容旧调用）。
     */
    private static function decodeJsonField($v) {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $b = base64_decode($v, true);
        if ($b !== false && base64_encode($b) === $v) {
            $dec = json_decode($b, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $dec;
            }
        }
        return json_decode($v, true) ?: null;
    }

    /**
     * 将源规则复制应用到一组目标 QID：已存在则更新、不存在则新建（UPSERT）。
     * 仅传 source_rule_id，服务端从 API 取源规则内容，避免把 JSON 透传 $_POST 被 Sanitizer 破坏。
     */
    public static function copyRule(int $sourceRuleId, array $targetQids): array {
        $all = self::getRules();
        $src = null;
        foreach ($all as $r) {
            if ((int)($r['id'] ?? 0) === $sourceRuleId) {
                $src = $r;
                break;
            }
        }
        if (!$src) {
            return ['ok' => false, 'message' => "源规则 #{$sourceRuleId} 不存在"];
        }
        // 源规则里 action_template / rollback_plan 若仍是 JSON 字符串则先解码为数组
        foreach (['action_template', 'rollback_plan'] as $k) {
            if (isset($src[$k]) && is_string($src[$k])) {
                $src[$k] = self::decodeJsonField($src[$k]);
            }
        }
        $byQid = [];
        foreach ($all as $r) {
            $byQid[(string)($r['qid'] ?? '')] = $r;
        }
        $client = PluginAdmanagerFastApiClient::getInstance();
        $created = []; $updated = []; $errors = [];
        foreach ($targetQids as $q) {
            $q = trim((string)$q);
            if ($q === '') {
                continue;
            }
            $payload = [
                'qid'                => $q,
                'fix_type'           => $src['fix_type'] ?? 'manual_review',
                'default_risk_level' => $src['default_risk_level'] ?? 'medium',
                'status'             => $src['status'] ?? 'active',
                'action_template'    => $src['action_template'] ?? null,
                'rollback_plan'      => $src['rollback_plan'] ?? null,
                'notes'              => '复制自规则 #' . $sourceRuleId . ' (QID ' . ($src['qid'] ?? '') . ')',
            ];
            try {
                if (isset($byQid[$q])) {
                    $client->put('/api/vuln/rules/' . (int)$byQid[$q]['id'], $payload);
                    $updated[] = $q;
                } else {
                    $client->post('/api/vuln/rules', $payload);
                    $created[] = $q;
                }
            } catch (\Exception $e) {
                $errors[] = $q . ': ' . $e->getMessage();
            }
        }
        PluginAdmanagerAuditLog::write('vuln_rule_copy', 'vuln',
            (string)$sourceRuleId, implode(',', array_merge($created, $updated)),
            ['created' => $created, 'updated' => $updated, 'errors' => $errors], empty($errors), '');
        $ok = empty($errors);
        $msg = sprintf('复制完成：新建 %d 条、更新 %d 条、失败 %d 条',
            count($created), count($updated), count($errors));
        if ($errors) {
            $msg .= '；失败：' . implode('；', $errors);
        }
        return ['ok' => $ok, 'message' => $msg, 'created' => $created, 'updated' => $updated, 'errors' => $errors];
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
            'shell_exec'         => '命令执行',
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

            case 'shell_exec':
                $cmd = $action['command'] ?? '';
                if ($cmd === '') {
                    return $action['description'] ?? '（未指定命令）';
                }
                $cmd = preg_replace('/\s+/', ' ', $cmd);
                $len = mb_strlen($cmd);
                return '执行命令：' . ($len > 90 ? mb_substr($cmd, 0, 90) . '…' : $cmd);

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
     * $correctedAction 可以是 base64(JSON) 字符串、原始 JSON 字符串或数组。
     *
     * 注意：GLPI 在 inc/includes.php 会对 $_POST 做 Sanitizer::sanitize（HTML 转义），
     * 直接传 JSON 会把结构引号 " 变成 &quot;，导致 json_decode 失败并误报“JSON 格式无效”。
     * 因此前端改为 base64 传输；此处先尝试 base64 解码（strict），失败则回退为原始 JSON 字符串。
     */
    public static function correctTask(int $taskId, string $fixType, $correctedAction,
                                        string $note = '', bool $promoteToRule = false): array {
        // corrected_action: 字符串 → 解码为数组
        if (is_string($correctedAction)) {
            // 先尝试 base64 解码（strict 模式：含非 base64 字符直接返回 false）
            $raw = base64_decode($correctedAction, true);
            if ($raw === false) {
                $raw = $correctedAction; // 非 base64 → 视为原始 JSON 字符串（兼容旧调用）
            }
            $decoded = json_decode($raw, true);
            if ($decoded === null && trim($raw) !== '') {
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
