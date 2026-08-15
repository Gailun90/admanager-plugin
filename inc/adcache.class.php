<?php
/**
 * inc/adcache.class.php — AD 数据库缓存层
 *
 * 设计：
 *   - AD 全量数据持久化到 DB，所有搜索从 DB 走，不实时连 AD
 *   - 手动触发 or 定时自动同步（可配置间隔，默认 6 小时）
 *   - 同步是增量 upsert，按 dn 去重，不整表删除
 *   - 同步过程加 DB 锁防并发
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerAdCache
{
    const TABLE      = 'glpi_plugin_admanager_ad_cache';
    const LOG_TABLE  = 'glpi_plugin_admanager_ad_sync_log';
    const LOCK_KEY   = 'plugin_admanager_ad_sync_lock';

    // 默认自动同步间隔（秒）
    const DEFAULT_AUTO_SYNC_INTERVAL = 21600; // 6 小时

    // ── 查询接口（供 aduser.php / computer_query.php / dashboard 用）────────

    public static function searchUsers(string $keyword = '', string $ou = ''): array {
        global $DB;
        $where = ['cache_type' => 'user'];
        if ($keyword) {
            $where[] = ['OR' => [
                'sam'            => ['LIKE', "%{$keyword}%"],
                'display_name'   => ['LIKE', "%{$keyword}%"],
                'mail'           => ['LIKE', "%{$keyword}%"],
                'department'     => ['LIKE', "%{$keyword}%"],
            ]];
        }
        if ($ou) {
            $where['dn'] = ['LIKE', "%{$ou}%"];
        }
        $rows = $DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'LIMIT' => 2000]);
        $users = array_map(fn($r) => json_decode($r['raw_json'], true) ?: [], iterator_to_array($rows));

        // ── 状态列实时覆盖 ──────────────────────────────────────────────────
        // 搜索结果条数通常远小于全量缓存，对结果集批量发一次 LDAP 查询，
        // 只取 userAccountControl + lockoutTime，用实时值覆盖缓存里的状态字段。
        if (!empty($users)) {
            try {
                $liveStatus = self::fetchLiveStatus($users);
                foreach ($users as &$u) {
                    $sam = strtolower($u['samaccountname'] ?? '');
                    if (isset($liveStatus[$sam])) {
                        $u['is_disabled'] = $liveStatus[$sam]['is_disabled'];
                        $u['is_locked']   = $liveStatus[$sam]['is_locked'];
                        // 同步更新缓存 DB，保持缓存与 AD 一致
                        self::patchCacheStatus($u['distinguishedname'] ?? '', $liveStatus[$sam]);
                    }
                }
                unset($u);
            } catch (\Throwable $e) {
                // LDAP 实时查询失败时静默回退——仍返回缓存状态，不中断页面
                // 在 raw_json 里追加标记让前端显示"状态可能不是最新"
                foreach ($users as &$u) {
                    $u['_status_from_cache'] = true;
                }
                unset($u);
            }
        }

        return $users;
    }

    /**
     * 对搜索结果集批量实时查 AD，只取 userAccountControl + lockoutTime。
     * 构造一个 OR filter，一次 LDAP 请求拿回所有结果，效率高。
     *
     * @return array  [ 'samaccountname_lower' => ['is_disabled'=>bool, 'is_locked'=>bool], ... ]
     */
    private static function fetchLiveStatus(array $users): array {
        if (empty($users)) return [];

        $cfg  = PluginAdmanagerConfig::getAdConfig();

        // 直接建立独立 LDAP 连接（只读状态查询，不复用 AdLdap 实例避免干扰写操作）
        $caPath = $cfg['ca_cert_path'];
        if ($caPath && file_exists($caPath)) putenv("LDAPTLS_CACERT={$caPath}");
        putenv('LDAPTLS_REQCERT=allow');
        $useSSL = $cfg['use_ssl'] || in_array((int)$cfg['port'], [636, 3269]);
        $conn   = ldap_connect(($useSSL ? 'ldaps' : 'ldap') . "://{$cfg['host']}:{$cfg['port']}");
        if (!$conn) return [];
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);   // 状态查询超时 5s，快速回退
        if (!@ldap_bind($conn, $cfg['bind_dn'], $cfg['password'])) return [];

        // 用 sAMAccountName OR filter 一次批量查（比逐 DN 查更通用，不受 DN 大小写影响）
        $orParts = '';
        foreach ($users as $u) {
            $sam = ldap_escape($u['samaccountname'] ?? '', '', LDAP_ESCAPE_FILTER);
            if ($sam) $orParts .= "(sAMAccountName={$sam})";
        }
        if (!$orParts) return [];

        $filter = count($users) === 1
            ? "(&(objectClass=user)(sAMAccountName=" . ldap_escape($users[0]['samaccountname'], '', LDAP_ESCAPE_FILTER) . "))"
            : "(&(objectClass=user)(|{$orParts}))";

        $res = @ldap_search($conn, $cfg['base_dn'], $filter,
            ['sAMAccountName', 'userAccountControl', 'lockoutTime'], 0, 0);
        if (!$res) return [];

        $entries = ldap_get_entries($conn, $res);
        $result  = [];
        for ($i = 0; $i < $entries['count']; $i++) {
            $e   = $entries[$i];
            $sam = strtolower($e['samaccountname'][0] ?? '');
            if (!$sam) continue;
            $uac        = (int)($e['useraccountcontrol'][0] ?? 512);
            $lockout    = (int)($e['lockouttime'][0]        ?? 0);
            $result[$sam] = [
                'is_disabled' => (bool)($uac & 0x2),
                'is_locked'   => $lockout > 0,
            ];
        }
        return $result;
    }

    /**
     * 将实时状态同步写回缓存 DB，避免下次搜索还显示旧状态。
     */
    private static function patchCacheStatus(string $dn, array $status): void {
        if (!$dn) return;
        global $DB;
        // 只更新 is_disabled / is_locked 两个索引列，不动 raw_json（全量同步时再更新）
        $DB->update(self::TABLE,
            [
                'is_disabled' => (int)$status['is_disabled'],
                'is_locked'   => (int)$status['is_locked'],
            ],
            ['dn' => $dn, 'cache_type' => 'user']
        );
    }

    public static function searchComputers(string $keyword = '', string $ou = ''): array {
        global $DB;
        $where = ['cache_type' => 'computer'];
        if ($keyword) {
            $where[] = ['OR' => [
                'sam'            => ['LIKE', "%{$keyword}%"],
                'dns_hostname'   => ['LIKE', "%{$keyword}%"],
                'os'             => ['LIKE', "%{$keyword}%"],
            ]];
        }
        if ($ou) {
            $where['dn'] = ['LIKE', "%{$ou}%"];
        }
        $rows = $DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'LIMIT' => 3000]);
        return array_map(fn($r) => json_decode($r['raw_json'], true) ?: [], iterator_to_array($rows));
    }

    /**
     * AD 安全组列表（供全局搜索用）。
     * 当前 adcache 仅缓存 user / computer，未缓存 group，故返回空数组——
     * 避免 global_search 调用不存在的 getGroups() 抛致命错误。
     * 后续若接入组缓存，这里改为从缓存/AD 读取即可。
     */
    public static function getGroups(): array {
        return [];
    }

    public static function getUserCount(): int {
        global $DB;
        $r = $DB->request(['COUNT' => 'id', 'FROM' => self::TABLE, 'WHERE' => ['cache_type' => 'user']]);
        return (int)(array_values((array)$r->current())[0] ?? 0);
    }

    public static function getComputerCount(): int {
        global $DB;
        $r = $DB->request(['COUNT' => 'id', 'FROM' => self::TABLE, 'WHERE' => ['cache_type' => 'computer']]);
        return (int)(array_values((array)$r->current())[0] ?? 0);
    }

    // ── 缓存状态 ──────────────────────────────────────────────────────────────

    public static function getLastSyncInfo(): array {
        global $DB;
        $r = $DB->request([
            'FROM'    => self::LOG_TABLE,
            'WHERE'   => ['status' => 'ok'],
            'ORDER'   => ['synced_at DESC'],
            'LIMIT'   => 1,
        ]);
        $row = $r->current();
        if (!$row) return ['synced_at' => null, 'total' => 0, 'triggered_by' => ''];
        return [
            'synced_at'    => $row['synced_at'],
            'total'        => $row['total_count'],
            'triggered_by' => $row['triggered_by'],
            'duration_sec' => $row['duration_sec'],
            'sync_type'    => $row['sync_type'],
        ];
    }

    public static function isSyncing(): bool {
        global $DB;
        $r = $DB->request([
            'FROM'  => 'glpi_configs',
            'WHERE' => ['context' => 'plugin:admanager', 'name' => self::LOCK_KEY],
            'LIMIT' => 1,
        ]);
        $row = $r->current();
        if (!$row) return false;
        // 锁超过 30 分钟视为僵尸，自动释放
        return (time() - (int)$row['value']) < 1800;
    }

    public static function needsAutoSync(): bool {
        $info = self::getLastSyncInfo();
        if (!$info['synced_at']) return true;
        $interval = (int)(PluginAdmanagerConfig::get('ad_sync_interval') ?: self::DEFAULT_AUTO_SYNC_INTERVAL);
        return (time() - strtotime($info['synced_at'])) > $interval;
    }

    // ── 同步核心 ──────────────────────────────────────────────────────────────

    /**
     * 执行全量同步（用户 + 计算机）
     * $triggeredBy: 'manual'|'auto'|'cron'
     */
    public static function syncAll(string $triggeredBy = 'manual'): array {
        if (self::isSyncing()) {
            return ['ok' => false, 'message' => '同步正在进行中，请稍候'];
        }

        // 加锁
        Config::setConfigurationValues('plugin:admanager', [self::LOCK_KEY => (string)time()]);
        $t0 = microtime(true);

        try {
            $ldap = PluginAdmanagerAdLdap::getInstance(true);
            $ldap->connect();

            // 同步用户
            $users = $ldap->searchUsersInConfiguredOUs('');
            self::upsertBatch('user', $users);
            // 删除 AD 已不存在的（以 dn 为准）
            self::pruneDeleted('user', array_column($users, 'dn'));

            // 同步计算机
            $computers = $ldap->searchComputersInConfiguredOUs('');
            self::upsertBatch('computer', $computers);
            self::pruneDeleted('computer', array_column($computers, 'dn'));

            $ldap->disconnect();

            $duration = round(microtime(true) - $t0, 1);
            $total    = count($users) + count($computers);

            // 写同步日志
            self::writeLog('full', $total, $duration, $triggeredBy, 'ok');

            // 释放锁
            Config::setConfigurationValues('plugin:admanager', [self::LOCK_KEY => '0']);

            return [
                'ok'           => true,
                'message'      => "同步完成：用户 " . count($users) . " 个，计算机 " . count($computers) . " 台，耗时 {$duration}s",
                'user_count'   => count($users),
                'computer_count'=> count($computers),
                'duration_sec' => $duration,
            ];
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $t0, 1);
            self::writeLog('full', 0, $duration, $triggeredBy, 'error', $e->getMessage());
            Config::setConfigurationValues('plugin:admanager', [self::LOCK_KEY => '0']);
            return ['ok' => false, 'message' => '同步失败：' . $e->getMessage()];
        }
    }

    // ── 单条缓存刷新（AD 写操作后调用）────────────────────────────────────

    public static function refreshUserByDn(string $dn): void {
        $ldap = PluginAdmanagerAdLdap::getInstance();
        $user = $ldap->getUserDetail($dn);
        if ($user) {
            self::upsertBatch('user', [$user]);
        }
    }

    // ── 私有辅助 ──────────────────────────────────────────────────────────────

    private static function upsertBatch(string $type, array $items): void {
        global $DB;
        $now = date('Y-m-d H:i:s');
        foreach ($items as $item) {
            $dn = $item['dn'] ?? $item['distinguishedname'] ?? '';
            if (!$dn) continue;

            $row = [
                'cache_type'     => $type,
                'sam'            => $item['samaccountname'] ?? $item['name'] ?? '',
                'dn'             => $dn,
                'display_name'   => $item['displayname']    ?? $item['name'] ?? '',
                'department'     => $item['department']     ?? '',
                'mail'           => $item['mail']           ?? '',
                'title'          => $item['title']          ?? '',
                'is_disabled'    => (int)($item['is_disabled'] ?? 0),
                'is_locked'      => (int)($item['is_locked']   ?? 0),
                'last_logon_unix'=> (int)($item['last_logon_unix'] ?? 0),
                'os'             => $item['operatingsystem'] ?? '',
                'dns_hostname'   => $item['dnshostname']    ?? '',
                'raw_json'       => json_encode($item, JSON_UNESCAPED_UNICODE),
                'synced_at'      => $now,
            ];

            // ON DUPLICATE KEY UPDATE（用 dn 唯一索引）
            $existing = $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['cache_type' => $type, 'dn' => $dn],
                'LIMIT' => 1,
            ])->current();

            if ($existing) {
                $DB->update(self::TABLE, $row, ['id' => $existing['id']]);
            } else {
                $DB->insert(self::TABLE, $row);
            }
        }
    }

    private static function pruneDeleted(string $type, array $activeDns): void {
        global $DB;
        if (empty($activeDns)) return;
        // 找 DB 里有但 AD 里没有的（已删除）
        $all = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['cache_type' => $type], 'FIELDS' => ['id','dn']]);
        $toDelete = [];
        foreach ($all as $row) {
            if (!in_array($row['dn'], $activeDns)) $toDelete[] = $row['id'];
        }
        if ($toDelete) {
            $DB->delete(self::TABLE, ['id' => $toDelete]);
        }
    }

    private static function writeLog(string $type, int $total, float $duration,
                                      string $by, string $status, string $err = ''): void {
        global $DB;
        // 使用 DB->insert() 而不是手动拼接 SQL，让 GLPI 自动处理转义
        $DB->insert(self::LOG_TABLE, [
            'sync_type'     => $type,
            'total_count'   => $total,
            'duration_sec'  => $duration,
            'triggered_by'  => mb_substr($by, 0, 128),
            'synced_at'     => date('Y-m-d H:i:s'),
            'status'        => $status,
            'error_msg'     => mb_substr($err, 0, 500),
        ]);
    }
}

