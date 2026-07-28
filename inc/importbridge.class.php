<?php
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerImportBridge
{
    /**
     * 导入单台终端资产到 GLPI（包含软件清单）
     * 唯一标识：serial（序列号）
     * 已存在 → 更新字段 + 同步软件；不存在 → 新建
     */
    public static function importComputer(array $client): array {
        $result = ['status' => 'error', 'glpi_id' => 0, 'message' => ''];
        try {
            global $DB;
            $hash_serial = $client['serial'] ?? '';   // hash_serial：认证/SyncState 用
            $bios_serial = $client['real_serial'] ?? ($client['bios_serial'] ?? '');  // 真实序列号：GLPI 展示 + 查重
            $existing = null;
            // 查重优先用 bios_serial 匹配 glpi_computers.serial（与 GLPI 原生 agent 兼容）
            if ($bios_serial) {
                $iter = $DB->request(['SELECT'=>['id','name','comment'],'FROM'=>'glpi_computers',
                    'WHERE'=>['serial'=>$bios_serial,'is_deleted'=>0],'LIMIT'=>1]);
                $existing = $iter->current();
            }
            // 如果没找到且有 hash_serial，回退用 otherserial 查（兼容旧数据）
            if (!$existing && $hash_serial) {
                $iter = $DB->request(['SELECT'=>['id','name','comment'],'FROM'=>'glpi_computers',
                    'WHERE'=>['otherserial'=>$hash_serial,'is_deleted'=>0],'LIMIT'=>1]);
                $existing = $iter->current();
            }

            $data = [
                'name'              => $client['hostname']     ?? '',
                'serial'            => $bios_serial ?: $hash_serial,   // 优先真实序列号
                'otherserial'       => $hash_serial,   // hash_serial 存 otherserial（备查）
                'manufacturers_id'  => self::getOrCreateManufacturer($client['manufacturer'] ?? ''),
                'computermodels_id' => self::getOrCreateModel($client['model'] ?? ''),
                'comment'           => '由 admanager 插件同步，来源：FastAPI | OS: ' . ($client['os_name'] ?? 'N/A') . ' ' . ($client['os_version'] ?? ''),
                'is_dynamic'        => 0,
                'entities_id'       => Session::getActiveEntity(),
            ];

            $computer = new Computer();
            $software_count = 0;
            if ($existing) {
                $data['id'] = $existing['id'];
                $ok = $computer->update($data);
                $glpi_id = $existing['id'];
                $status  = 'updated';
            } else {
                $glpi_id = $computer->add($data);
                $ok      = (bool)$glpi_id;
                $status  = 'created';
            }

            if (!$ok) throw new \RuntimeException('Computer::add/update 返回失败');

            // 导入操作系统
            if (!empty($client['os_name'])) {
                self::linkOperatingSystem($glpi_id, $client);
            }

            // 导入软件清单（从 FastAPI 拉取）
            if (!empty($client['client_id'])) {
                try {
                    $api = PluginAdmanagerFastApiClient::getInstance();
                    $sw_result = $api->getClientSoftware((int)$client['client_id']);
                    $sw_list = $sw_result['software'] ?? [];
                    if (!empty($sw_list)) {
                        $sw_import = self::importSoftware($glpi_id, $sw_list);
                        $software_count = $sw_import['imported'];
                    }
                } catch (\Exception $e) {
                    // 软件导入失败不影响计算机导入
                    trigger_error('软件导入失败: ' . $e->getMessage(), E_USER_WARNING);
                }
            }

            // 更新同步状态
            PluginAdmanagerSyncState::markImported($hash_serial, $glpi_id);

            // 写审计日志
            PluginAdmanagerAuditLog::write(
                'import_computer', 'Computer', $hash_serial,
                $client['hostname'] ?? '', $client, true
            );

            $result = ['status' => $status, 'glpi_id' => $glpi_id, 'message' => '',
                'software_imported' => $software_count];
        } catch (\Throwable $e) {
            PluginAdmanagerAuditLog::write(
                'import_computer', 'Computer', $client['serial'] ?? '',
                $client['hostname'] ?? '', $client, false, $e->getMessage()
            );
            $result['message'] = $e->getMessage();
            self::writeImportLog('computer', $client['serial'] ?? '', 'Computer',
                0, 'failed', $e->getMessage());
        }
        self::writeImportLog('computer', $client['serial'] ?? '', 'Computer',
            $result['glpi_id'], $result['status'] === 'error' ? 'failed' : 'success');
        return $result;
    }

    /**
     * 自动同步：根据 serial 查找已有 Computer，更新关键字段 + 软件清单
     * 与 importComputer 的区别：仅更新已存在的记录，不创建新记录
     */
    public static function syncComputer(array $client): array {
        $serial = $client['serial'] ?? '';
        if (!$serial) return ['status' => 'skip', 'message' => '无序列号'];

        global $DB;
        $bios_serial = $client['real_serial'] ?? ($client['bios_serial'] ?? '');
        $iter = null;
        // 优先用 bios_serial 查
        if ($bios_serial) {
            $iter = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_computers',
                'WHERE'=>['serial'=>$bios_serial,'is_deleted'=>0],'LIMIT'=>1]);
        }
        // 回退用 hash_serial（otherserial）
        if ((!$iter || !$iter->current()) && $serial) {
            $iter = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_computers',
                'WHERE'=>['otherserial'=>$serial,'is_deleted'=>0],'LIMIT'=>1]);
        }
        $existing = $iter->current();
        if (!$existing) return ['status' => 'skip', 'message' => 'GLPI中无此终端，请先手动导入'];

        $glpi_id = $existing['id'];
        $updated = [];

        // 更新主机名
        if (!empty($client['hostname'])) {
            $computer = new Computer();
            $computer->update([
                'id'   => $glpi_id,
                'name' => $client['hostname'],
                'comment' => '由 admanager 插件自动同步 | OS: ' . ($client['os_name'] ?? 'N/A') . ' ' . ($client['os_version'] ?? ''),
            ]);
            $updated[] = 'name';
        }

        // 更新操作系统
        if (!empty($client['os_name'])) {
            self::linkOperatingSystem($glpi_id, $client);
            $updated[] = 'os';
        }

        // 同步软件清单
        $software_count = 0;
        if (!empty($client['client_id'])) {
            try {
                $api = PluginAdmanagerFastApiClient::getInstance();
                $sw_result = $api->getClientSoftware((int)$client['client_id']);
                $sw_list = $sw_result['software'] ?? [];
                if (!empty($sw_list)) {
                    $sw_import = self::importSoftware($glpi_id, $sw_list);
                    $software_count = $sw_import['imported'];
                }
            } catch (\Exception $e) {
                trigger_error('自动同步软件失败: ' . $e->getMessage(), E_USER_WARNING);
            }
        }

        // 标记已同步
        PluginAdmanagerSyncState::markImported($serial, $glpi_id);

        PluginAdmanagerAuditLog::write(
            'sync_computer', 'Computer', $serial,
            $client['hostname'] ?? '', ['updated_fields' => $updated, 'software' => $software_count], true
        );

        return [
            'status' => 'synced',
            'glpi_id' => $glpi_id,
            'updated_fields' => $updated,
            'software_imported' => $software_count,
        ];
    }

    // 清除指定 Computer 下所有旧的软件版本关联（避免已卸载软件永久残留）
    private static function clearSoftwareLinks(int $comp_id): void {
        global $DB;
        $DB->delete('glpi_items_softwareversions', [
            'items_id' => $comp_id,
            'itemtype' => 'Computer',
        ]);
    }

    // 导入软件清单到指定 Computer
    public static function importSoftware(int $glpi_computer_id, array $software_list): array {
        self::clearSoftwareLinks($glpi_computer_id);  // ★ v2 fix: 先清旧数据再写新数据
        $imported = 0; $failed = 0; $errors = [];
        foreach ($software_list as $sw) {
            try {
                $sw_id  = self::getOrCreateSoftware($sw['name'] ?? '', $sw['publisher'] ?? '');
                $ver_id = self::getOrCreateSoftwareVersion($sw_id, $sw['version'] ?? '');
                self::linkSoftwareToComputer($glpi_computer_id, $sw_id, $ver_id);
                $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ($sw['name'] ?? '?') . ': ' . $e->getMessage();
            }
        }
        PluginAdmanagerAuditLog::write('import_software', 'Computer',
            (string)$glpi_computer_id, "导入软件 {$imported} 条", [], true);
        return ['imported' => $imported, 'failed' => $failed, 'errors' => $errors];
    }

    // 导入 AD 用户到 GLPI User
    public static function importAdUser(array $ad_user): array {
        $result = ['status' => 'error', 'glpi_id' => 0, 'message' => ''];
        try {
            global $DB;
            $sam = $ad_user['samaccountname'] ?? '';
            if (!$sam) throw new \RuntimeException('缺少 sAMAccountName');

            $iter = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_users',
                'WHERE'=>['name'=>$sam],'LIMIT'=>1]);
            $existing = $iter->current();

            $data = [
                'name'        => $sam,
                'realname'    => $ad_user['displayname'] ?? '',
                'email'       => $ad_user['mail']        ?? '',
                'comment'     => 'AD导入：' . ($ad_user['dn'] ?? ''),
                'is_active'   => isset($ad_user['is_disabled']) && $ad_user['is_disabled'] ? 0 : 1,
                'entities_id' => 0,
                'authtype'    => Auth::DB_GLPI,
            ];

            $user = new User();
            if ($existing) {
                $data['id'] = $existing['id'];
                if (!$user->update($data)) {
                    throw new \RuntimeException('User::update 失败');
                }
                $glpi_id = $existing['id'];
                $status  = 'updated';
            } else {
                $glpi_id = $user->add($data);
                if (!$glpi_id) {
                    throw new \RuntimeException('User::add 失败');
                }
                $status  = 'created';
            }

            PluginAdmanagerAuditLog::write('import_user','ADUser',$ad_user['dn']??'',$sam,$ad_user,true);
            $result = ['status' => $status, 'glpi_id' => $glpi_id, 'message' => ''];
        } catch (\Throwable $e) {
            $result['message'] = $e->getMessage();
            PluginAdmanagerAuditLog::write('import_user','ADUser',$ad_user['dn']??'',
                $ad_user['samaccountname']??'',$ad_user,false,$e->getMessage());
        }
        return $result;
    }

    // ── 私有辅助方法 ─────────────────────────────────────────

    private static function getOrCreateManufacturer(string $name): int {
        if (!$name) return 0;
        $m = new Manufacturer();
        return $m->import(['name' => $name]);
    }

    private static function getOrCreateModel(string $name): int {
        if (!$name) return 0;
        $m = new ComputerModel();
        return $m->import(['name' => $name]);
    }

    private static function getOrCreateSoftware(string $name, string $publisher): int {
        global $DB;
        $iter = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_softwares',
            'WHERE'=>['name'=>$name,'is_deleted'=>0],'LIMIT'=>1]);
        if ($row = $iter->current()) return (int)$row['id'];
        $sw = new Software();
        $id = $sw->add([
            'name'         => $name,
            'manufacturers_id' => self::getOrCreateManufacturer($publisher),
            'entities_id'  => 0,
            'is_recursive' => 1,
        ]);
        return $id ? (int)$id : 0;
    }

    private static function getOrCreateSoftwareVersion(int $sw_id, string $version): int {
        global $DB;
        $vname = $version ?: '_';
        $iter = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_softwareversions',
            'WHERE'=>['softwares_id'=>$sw_id,'name'=>$vname],'LIMIT'=>1]);
        if ($row = $iter->current()) return (int)$row['id'];
        $ver = new SoftwareVersion();
        $id = $ver->add(['name' => $vname, 'softwares_id' => $sw_id]);
        return $id ? (int)$id : 0;
    }

    private static function linkSoftwareToComputer(int $comp_id, int $sw_id, int $ver_id): void {
        global $DB;
        $exist = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_items_softwareversions',
            'WHERE'=>['items_id'=>$comp_id,'itemtype'=>'Computer','softwareversions_id'=>$ver_id],'LIMIT'=>1]);
        if (!$exist->current()) {
            $link = new Item_SoftwareVersion();
            $link->add(['items_id'=>$comp_id,'itemtype'=>'Computer','softwareversions_id'=>$ver_id]);
        }
    }

    private static function linkOperatingSystem(int $comp_id, array $client): void {
        global $DB;
        // 先删除旧的操作系统关联
        $old = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_items_operatingsystems',
            'WHERE'=>['items_id'=>$comp_id,'itemtype'=>'Computer'],'LIMIT'=>1]);
        if ($row = $old->current()) {
            $iolink = new Item_OperatingSystem();
            $iolink->delete(['id' => $row['id']]);
        }
        // 查找或创建操作系统
        $os_name = trim(($client['os_name'] ?? '') . ' ' . ($client['os_version'] ?? ''));
        if (!$os_name) return;
        $iter = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_operatingsystems',
            'WHERE'=>['name'=>$os_name],'LIMIT'=>1]);
        if ($row = $iter->current()) {
            $os_id = (int)$row['id'];
        } else {
            $os = new OperatingSystem();
            $os_id = $os->add(['name' => $os_name]);
        }
        if ($os_id) {
            $link = new Item_OperatingSystem();
            $link->add([
                'itemtype'           => 'Computer',
                'items_id'           => $comp_id,
                'operatingsystems_id'=> $os_id,
            ]);
        }
    }

    private static function writeImportLog(
        string $type, string $ref, string $itemtype,
        int $items_id, string $status, string $error = ''
    ): void {
        $log = new \PluginAdmanagerImportLog();
        $log->add([
            'date_mod'      => date('Y-m-d H:i:s'),
            'users_id'      => Session::getLoginUserID() ?: 0,
            'import_type'   => $type,
            'source_ref'    => $ref,
            'glpi_itemtype' => $itemtype,
            'glpi_items_id' => $items_id,
            'status'        => $status,
            'error_message' => $error,
        ]);
    }
}

class PluginAdmanagerImportLog extends CommonDBTM {
    static $table = 'glpi_plugin_admanager_importlogs';
    public static function getTypeName($nb = 0): string { return '导入记录'; }
}