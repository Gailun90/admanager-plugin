<?php
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerDashboard
{
    /**
     * 返回注册到 GLPI 主页仪表盘的卡片定义
     */
    public static function getDashboardCards(): array {
        return [
            'plugin_admanager_ad_users' => [
                'widgettype'   => ['bigNumber'],
                'label'        => 'AD 用户',
                'group'        => 'IT资产管控',
                'provider'     => 'PluginAdmanagerDashboard::cardAdUserCount',
                'color'        => '#2980b9',
                'icon'         => 'ti ti-users',
            ],
            'plugin_admanager_ad_computers' => [
                'widgettype'   => ['bigNumber'],
                'label'        => 'AD 计算机',
                'group'        => 'IT资产管控',
                'provider'     => 'PluginAdmanagerDashboard::cardAdComputerCount',
                'color'        => '#2ecc71',
                'icon'         => 'ti ti-server',
            ],
            'plugin_admanager_diff_count' => [
                'widgettype'   => ['bigNumber'],
                'label'        => '待同步终端',
                'group'        => 'IT资产管控',
                'provider'     => 'PluginAdmanagerDashboard::cardDiffCount',
                'color'        => '#e74c3c',
                'icon'         => 'ti ti-alert-triangle',
            ],
            'plugin_admanager_total_clients' => [
                'widgettype'   => ['bigNumber'],
                'label'        => '已注册终端',
                'group'        => 'IT资产管控',
                'provider'     => 'PluginAdmanagerDashboard::cardTotalClients',
                'color'        => '#3498db',
                'icon'         => 'ti ti-devices',
            ],
            'plugin_admanager_week_audit' => [
                'widgettype'   => ['summaryNumbers'],
                'label'        => '本周AD操作',
                'group'        => 'IT资产管控',
                'provider'     => 'PluginAdmanagerDashboard::cardWeekAudit',
                'color'        => '#8e44ad',
                'icon'         => 'ti ti-activity',
            ],
        ];
    }

    // ── 各卡片数据提供方法 ────────────────────────────────────────────────────

    /** AD 用户总数（主页看板 + 差异页面共用） */
    public static function cardAdUserCount(array $params = []): array {
        $count = self::getAdUserCount();
        return [
            'number'  => $count,
            'url'     => '/plugins/admanager/front/aduser.php',
            'label'   => $count > 0 ? "共 {$count} 个 AD 用户" : '未连接或无用户',
        ];
    }

    /** AD 计算机总数 */
    public static function cardAdComputerCount(array $params = []): array {
        $count = self::getAdComputerCount();
        return [
            'number'  => $count,
            'url'     => '/plugins/admanager/front/computer_query.php',
            'label'   => $count > 0 ? "共 {$count} 台计算机" : '未连接或无计算机',
        ];
    }

    /** 待同步差异数 */
    public static function cardDiffCount(array $params = []): array {
        $count = PluginAdmanagerSyncState::getDiffCount();
        return [
            'number'  => $count,
            'url'     => '/plugins/admanager/front/import.php?diff=1',
            'label'   => $count > 0 ? "有 {$count} 台终端需同步" : '所有终端已同步',
        ];
    }

    /** FastAPI 已注册终端数 */
    public static function cardTotalClients(array $params = []): array {
        global $DB;
        $res = $DB->request(['COUNT'=>'id','FROM'=>'glpi_plugin_admanager_syncstates']);
        $count = (int)($res->current()['id'] ?? 0);
        return [
            'number' => $count,
            'url'    => '/plugins/admanager/front/import.php',
            'label'  => '台终端已注册',
        ];
    }

    /** 本周 AD 操作统计 */
    public static function cardWeekAudit(array $params = []): array {
        $rows    = PluginAdmanagerAuditLog::getWeekSummary();
        $numbers = [];
        $action_labels = [
            'disable_user'    => '禁用账户',
            'enable_user'     => '启用账户',
            'reset_pwd'       => '重置密码',
            'move_ou'         => '移动OU',
            'import_computer' => '导入终端',
            'import_user'     => '导入用户',
            'create_user'     => '新建用户',
            'search_ad_users' => '搜索用户',
        ];
        foreach ($rows as $row) {
            $numbers[] = [
                'number' => (int)$row['cnt'],
                'label'  => $action_labels[$row['action_type']] ?? $row['action_type'],
                'url'    => '/plugins/admanager/front/auditlog.php?action=' . $row['action_type'],
            ];
        }
        return ['data' => $numbers ?: [['number'=>0,'label'=>'本周暂无操作','url'=>'']]];
    }

    // ── AD 数据查询（带缓存，避免每次请求都连AD） ─────────────────────────

    private static function getAdUserCount(): int {
        return PluginAdmanagerAdCache::getUserCount();
    }

    private static function getAdComputerCount(): int {
        return PluginAdmanagerAdCache::getComputerCount();
    }

    /**
     * 快速获取 AD 统计数据（仅读缓存，不连外部）
     */
    public static function getAdStats(): array {
        return [
            'total_users'     => self::getAdUserCount(),
            'total_computers' => self::getAdComputerCount(),
        ];
    }

    // ── FastAPI 同步 ───────────────────────────────────────────────────────

    /**
     * 从 FastAPI 拉取最新终端列表并同步到 SyncState 表
     */
    public static function syncFromFastApi(): void {
        try {
            $api     = PluginAdmanagerFastApiClient::getInstance();
            $page    = 1;
            $limit   = 100;
            $synced  = 0;
            do {
                $result = $api->getClients($page, $limit);
                $items  = $result['items'] ?? [];
                foreach ($items as $c) {
                    PluginAdmanagerSyncState::upsertFromApi($c);
                    $synced++;
                }
                $total = (int)($result['total'] ?? 0);
                $page++;
            } while ($synced < $total && count($items) === $limit);
        } catch (\Exception $e) {
            // FastAPI 不可达时静默跳过
        }
    }

    /**
     * 获取总览页面完整数据（front/dashboard.php 用）
     */
    public static function getFullReport(): array {
        // 从 FastAPI 同步最新终端列表
        self::syncFromFastApi();

        // FastAPI 统计数据
        $api_stats = [
            'total_clients'  => 0,
            'online_clients' => 0,
            'pending_tasks'  => 0,
            'failed_tasks'   => 0,
        ];
        try {
            $api = PluginAdmanagerFastApiClient::getInstance();
            $dash = $api->get('/api/dashboard');
            $api_stats = array_merge($api_stats, $dash);
        } catch (\Throwable $e) {
            // FastAPI 不可达时使用默认值
        }

        // AD 统计数据
        $ad_stats = [
            'total_users'     => self::getAdUserCount(),
            'total_computers' => self::getAdComputerCount(),
        ];

        return [
            'diff_list'   => PluginAdmanagerSyncState::getDiffList(50),
            'diff_count'  => PluginAdmanagerSyncState::getDiffCount(),
            'last_sync'   => PluginAdmanagerSyncState::getLastImportTime(),
            'week_audit'  => PluginAdmanagerAuditLog::getWeekSummary(),
            'alert_days'  => (int)PluginAdmanagerConfig::get('diff_alert_days'),
            'api_stats'   => $api_stats,
            'ad_stats'    => $ad_stats,
        ];
    }
}
