<?php
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerSyncState extends CommonDBTM
{
    static $table = 'glpi_plugin_admanager_syncstates';

    public static function getTypeName($nb = 0): string { return '同步状态'; }

    /**
     * 当 FastAPI 上报终端数据时更新同步状态
     * 由 FastAPI 侧的 webhook 或插件定期拉取调用
     */
    public static function upsertFromApi(array $client): void {
        global $DB;
        $serial = trim($client['serial'] ?? '');
        if (!$serial) return;

        // 查找 GLPI 中对应 Computer：优先用 bios_serial 匹配 serial 字段，回退用 otherserial（hash）
        $bios_serial = trim($client['real_serial'] ?? ($client['bios_serial'] ?? ''));
        $glpi_id = 0;
        if ($bios_serial) {
            $iter = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_computers',
                'WHERE'=>['serial'=>$bios_serial,'is_deleted'=>0],'LIMIT'=>1]);
            $glpi_id = ($iter->current()['id'] ?? 0);
        }
        if (!$glpi_id) {
            $iter = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_computers',
                'WHERE'=>['otherserial'=>$serial,'is_deleted'=>0],'LIMIT'=>1]);
            $glpi_id = ($iter->current()['id'] ?? 0);
        }

        // 与 GLPI 记录对比，判断是否存在差异
        $has_diff  = 0;
        $diff_info = [];
        if ($glpi_id) {
            $comp = new Computer();
            $comp->getFromDB($glpi_id);
            // 差异判断：只比较序列号（bios_serial → GLPI serial 字段）
            $api_serial  = trim(strval($client['real_serial'] ?? ($client['bios_serial'] ?? '')));
            $glpi_serial = trim(strval($comp->fields['serial'] ?? ''));
            if ($api_serial !== '' && $glpi_serial !== $api_serial) {
                $has_diff = 1;
                $diff_info['serial'] = ['glpi' => $glpi_serial, 'api' => $api_serial];
            }
            // 自动同步：已有 GLPI 记录且发现差异 → 自动更新
            if ($has_diff && $glpi_id > 0) {
                try {
                    PluginAdmanagerImportBridge::syncComputer($client);
                    $has_diff  = 0;
                    $diff_info = [];
                } catch (\Throwable $e) {
                    // 自动同步失败，保留差异标记等待手动处理
                    trigger_error('自动同步失败: ' . $e->getMessage(), E_USER_WARNING);
                }
            }

            // 检查到期未同步（超过预警天数）
            $exist_state = $DB->request([
                'SELECT' => ['last_imported'],
                'FROM'   => self::$table,
                'WHERE'  => ['serial' => $serial],
                'LIMIT'  => 1,
            ]);
            if ($row2 = $exist_state->current()) {
                $last_imported = $row2['last_imported'];
                if ($last_imported) {
                    $alert_days = (int)PluginAdmanagerConfig::get('diff_alert_days');
                    $since = (time() - strtotime($last_imported)) / 86400;
                    if ($since > $alert_days) {
                        $has_diff = 1;
                        $diff_info['stale'] = ['since_days' => (int)$since, 'alert_days' => $alert_days];
                    }
                }
            }
        } else {
            // GLPI 中不存在此终端 → 有差异（未导入）
            $has_diff  = 1;
            $diff_info = ['not_imported'];
        }

        $now  = date('Y-m-d H:i:s');
        $data = [
            'serial'        => $serial,
            'hostname'      => $client['hostname'] ?? '',
            'last_seen_api' => isset($client['last_seen']) ? date('Y-m-d H:i:s', strtotime($client['last_seen'])) : $now,
            'glpi_items_id' => $glpi_id,
            'has_diff'      => $has_diff,
            'diff_fields'   => json_encode($diff_info),
            'date_mod'      => $now,
        ];

        // upsert
        $exist = $DB->request(['SELECT'=>['id'],'FROM'=>self::$table,'WHERE'=>['serial'=>$serial],'LIMIT'=>1]);
        $obj   = new self();
        if ($row = $exist->current()) {
            $obj->update(['id' => $row['id']] + $data);
        } else {
            $obj->add($data);
        }
    }

    /**
     * 导入完成后标记为已同步
     */
    public static function markImported(string $serial, int $glpi_id): void {
        global $DB;
        $exist = $DB->request(['SELECT'=>['id'],'FROM'=>self::$table,'WHERE'=>['serial'=>$serial],'LIMIT'=>1]);
        if ($row = $exist->current()) {
            $obj = new self();
            $obj->update([
                'id'            => $row['id'],
                'last_imported' => date('Y-m-d H:i:s'),
                'glpi_items_id' => $glpi_id,
                'has_diff'      => 0,
                'diff_fields'   => '[]',
                'date_mod'      => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Computer Hook 回调：有新 Computer 被导入时更新同步状态
     */
    public static function onComputerChange(Computer $computer, string $action): void {
        $serial = $computer->fields['serial'] ?? '';
        if ($serial && $action === 'add') {
            self::markImported($serial, $computer->getID());
        }
    }

    /** 获取差异统计（仪表盘用） */
    public static function getDiffCount(): int {
        global $DB;
        // 注意：GLPI DBmysql 的 ['COUNT'=>'id'] 查询返回列名是 'id'（非 'cpt'），
        // 直接读 $res->current()['cpt'] 会永远得到 0。这里改为遍历计数，结果可靠。
        $count = 0;
        foreach ($DB->request(['FROM'=>self::$table,'WHERE'=>['has_diff'=>1]]) as $row) {
            $count++;
        }
        return $count;
    }

    /** 获取最后同步时间 */
    public static function getLastImportTime(): ?string {
        global $DB;
        $res = $DB->request([
            'SELECT' => ['last_imported'],
            'FROM'   => self::$table,
            'WHERE'  => ['last_imported' => ['!=', null]],
            'ORDER'  => 'last_imported DESC',
            'LIMIT'  => 1,
        ]);
        return $res->current()['last_imported'] ?? null;
    }

    /** 获取差异终端列表（导入预览页用） */
    public static function getDiffList(int $limit = 100): array {
        global $DB;
        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::$table,
            'WHERE' => ['has_diff' => 1],
            'ORDER' => 'date_mod DESC',
            'LIMIT' => $limit,
        ]) as $row) {
            $row['diff_fields'] = json_decode($row['diff_fields'] ?? '[]', true);
            $rows[] = $row;
        }
        return $rows;
    }
}
