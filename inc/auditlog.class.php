<?php
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerAuditLog extends CommonDBTM
{
    public static $rightname = 'plugin_admanager_admin';
    static $table = 'glpi_plugin_admanager_auditlogs';

    public static function getTypeName($nb = 0): string { return 'AD操作审计日志'; }

    /**
     * 记录一条审计日志
     */
    /** 自动化操作（不计审计，防止数据库膨胀） */
    private static $skipActions = [
        'import_computer', 'import_software', 'import_user',
        'sync_from_api', 'sync_computer', 'sync_software',
    ];

    public static function write(
        string $action_type,
        string $target_type,
        string $target_dn,
        string $target_name,
        array  $params  = [],
        bool   $success = true,
        string $error   = ''
    ): void {
        // 自动同步/导入操作不写审计日志
        if (in_array($action_type, self::$skipActions, true)) return;
        $log = new self();
        $log->add([
            'date_mod'     => date('Y-m-d H:i:s'),
            'users_id'     => Session::getLoginUserID() ?: 0,
            'action_type'  => $action_type,
            'target_type'  => $target_type,
            'target_dn'    => mb_substr($target_dn, 0, 512),
            'target_name'  => mb_substr($target_name, 0, 255),
            'params'       => json_encode($params, JSON_UNESCAPED_UNICODE),
            'result'       => $success ? 1 : 0,
            'error_message'=> $error,
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    }

    /**
     * 获取带用户真实名称的日志列表
     */
    public static function getLogsWithNames(array $filters = [], int $limit = 100, int $offset = 0): array {
        global $DB;
        $logs = self::getLogs($filters, $limit, $offset);
        if (empty($logs)) return $logs;
        $uid_list = array_filter(array_column($logs, 'users_id'));
        $names = [];
        if (!empty($uid_list)) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $uid_list],
            ]) as $u) {
                $names[$u['id']] = $u['name'];
            }
        }
        foreach ($logs as $k => $log) {
            $logs[$k]['user_name'] = $names[$log['users_id']] ?? '用户#' . $log['users_id'];
        }
        return $logs;
    }

    /**
     * 查询审计日志（带过滤）
     */
    public static function getLogs(array $filters = [], int $limit = 100, int $offset = 0): array {
        global $DB;
        $where = ['1' => '1'];
        if (!empty($filters['action_type'])) $where['action_type'] = $filters['action_type'];
        if (!empty($filters['users_id']))    $where['users_id']    = (int)$filters['users_id'];
        // 日期过滤 - 使用数组语法，让 GLPI 自动处理
        if (!empty($filters['date_from'])) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from']))
                $where[] = ['date_mod' => ['>=', $filters['date_from']]];
        }
        if (!empty($filters['date_to'])) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to']))
                $where[] = ['date_mod' => ['<=', $filters['date_to'] . ' 23:59:59']];
        }

        $rows = [];
        foreach ($DB->request([
            'FROM'    => self::$table,
            'WHERE'   => $where,
            'ORDER'   => 'date_mod DESC',
            'LIMIT'   => $limit,
            'START'   => $offset,
        ]) as $row) {
            $row['params'] = json_decode($row['params'] ?? '{}', true);
            $rows[] = $row;
        }
        return $rows;
    }

    public static function countLogs(array $filters = []): int {
        global $DB;
        $where = ['1' => '1'];
        if (!empty($filters['action_type'])) $where['action_type'] = $filters['action_type'];
        $res = $DB->request(['COUNT' => 'id', 'FROM' => self::$table, 'WHERE' => $where]);
        return (int)($res->current()['id'] ?? 0);
    }

    public static function getWeekSummary(): array {
        global $DB;
        $since = date('Y-m-d H:i:s', strtotime('-7 days'));
        $rows  = [];
        foreach ($DB->request([
            'SELECT'  => ['action_type', 'COUNT' => ['id' => 'cnt'], 'SUM' => ['result' => 'success_cnt']],
            'FROM'    => self::$table,
            'WHERE'   => ['date_mod' => ['>=', $since]],
            'GROUPBY' => ['action_type'],
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }
}