<?php
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerDeploy
{
    public static function getPackages(): array {
        try {
            return PluginAdmanagerFastApiClient::getInstance()->get('/api/packages');
        } catch (Exception $e) { return []; }
    }

    /**
     * 上传安装包：先拷贝到本地 packages 目录，再向 FastAPI 注册
     * 修复：增加超时、注册失败不删文件（包已落地，可后台重试注册）
     */
    public static function uploadPackage(array $file, string $name, string $version,
                                          string $silentArgs = '', string $desc = ''): array {
        $safeName = basename($file['name']);
        $cfg      = PluginAdmanagerConfig::getDeployConfig();
        $pkgDir   = rtrim($cfg['pkg_dir'] ?: '/home/glpidev/itasset-api/packages', '/');
        $dest     = $pkgDir . '/' . $safeName;

        $maxSize = 524288000; // 500MB
        if ($file['size'] > $maxSize) {
            return ['ok' => false, 'message' => '文件超过 500MB 上限'];
        }

        if (!is_dir($pkgDir)) {
            if (!mkdir($pkgDir, 0755, true)) {
                return ['ok' => false, 'message' => '无法创建安装包目录'];
            }
        }


        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            if (!copy($file['tmp_name'], $dest)) {
                return ['ok' => false, 'message' => '文件写入失败，请检查目录权限'];
            }
        }
        chmod($dest, 0644);
        $fileSize = filesize($dest);

        // 2. 异步后台执行 hash + FastAPI 注册，不阻塞响应
        //    用 nohup + php cli 脚本，输出重定向到日志
        $cfg         = PluginAdmanagerConfig::getFastApiConfig();
        $logFile     = '/tmp/pkg_register_' . preg_replace('/[^a-z0-9]/i', '_', $safeName) . '.log';
        $scriptArgs  = implode(' ', array_map('escapeshellarg', [
            $dest, $safeName, $name, $version, $silentArgs, $desc,
            $fileSize, $cfg['url'], $cfg['token'],
        ]));
        $phpBin = is_file('/usr/bin/php8.1') ? '/usr/bin/php8.1' : '/usr/bin/php';
        if (!is_executable($phpBin)) {
            PluginAdmanagerAuditLog::write(
                'upload_package', 'package', $safeName, $safeName,
                ['error' => "PHP binary not executable: {$phpBin}"], false, 'php_not_executable');
            return ['ok' => false, 'message' => 'PHP 解释器不可执行，请联系管理员'];
        }
        $registerScript = __DIR__ . '/register_package_async.php';
        $cmd = "nohup {$phpBin} {$registerScript} {$scriptArgs} > {$logFile} 2>&1 &";
        exec($cmd);

        PluginAdmanagerAuditLog::write(
            'upload_package', 'package', $safeName, $safeName,
            ['name' => $name, 'version' => $version, 'size' => $fileSize],
            true, 'file_saved_async_register'
        );

        return ['ok' => true, 'message' => "文件已上传（{$name} {$version}），正在后台注册，刷新页面后可见。"];
    }


        public static function getTasks(int $limit = 50): array {
        try {
            return PluginAdmanagerFastApiClient::getInstance()
                ->get('/api/tasks/admin/list', ['limit' => $limit]);
        } catch (Exception $e) { return []; }
    }

    public static function getTaskTargets(int $taskId): array {
        try {
            return PluginAdmanagerFastApiClient::getInstance()
                ->get("/api/tasks/admin/{$taskId}/targets");
        } catch (Exception $e) { return []; }
    }

    
    public static function getGroups(): array {
        try {
            return PluginAdmanagerFastApiClient::getInstance()->get('/api/groups') ?: [];
        } catch (Exception $e) { return []; }
    }

    public static function getClients(): array {
        try {
            $res = PluginAdmanagerFastApiClient::getInstance()
                ->get('/api/export/clients', ['page' => 1, 'limit' => 200]);
            $items = $res['items'] ?? [];
            // 统一字段名: client_id → id，前端模板全部用 id
            return array_map(function($c) {
                if (isset($c['client_id']) && !isset($c['id'])) {
                    $c['id'] = $c['client_id'];
                }
                return $c;
            }, $items);
        } catch (Exception $e) { return []; }
    }

    /**
     * 创建部署任务（支持配置交互参数：弹窗内容/推迟参数/静默覆盖等）
     */
    public static function createTask(string $name, int $packageId, string $targetType,
                                       ?int $targetId, bool $interactive,
                                       bool $needReboot, int $timeout,
                                       array $extra = []): array {
        // target_type 非 all 时，必须提供有效的 target_id（null 或空字符串都不合法）
        if (in_array($targetType, ['group', 'client'], true) && ($targetId === null || $targetId <= 0)) {
            return [
                'ok' => false,
                'message' => '目标类型为“按分组”或“指定终端”时，请选择具体的分组或终端。',
            ];
        }
        try {
            $params = [
                'name'          => $name,
                'package_id'    => $packageId,
                'target_type'   => $targetType,
                'interactive'   => $interactive ? 'true' : 'false',
                'need_reboot'   => $needReboot  ? 'true' : 'false',
                'timeout'       => $timeout,
            ];
            if ($targetId !== null) {
                $params['target_id'] = $targetId;
            }

            // 交互任务额外参数
            if ($interactive) {
                if (!empty($extra['defer_minutes']))   $params['defer_minutes']   = (int)$extra['defer_minutes'];
                if (!empty($extra['defer_max_count']))  $params['defer_max_count'] = (int)$extra['defer_max_count'];
                if (!empty($extra['dialog_title']))     $params['dialog_title']    = $extra['dialog_title'];
                if (!empty($extra['dialog_message']))   $params['dialog_message']  = $extra['dialog_message'];
            }
            if (!empty($extra['silent_override'])) {
                $params['silent_override'] = 'true';
            }
            if (!empty($extra['jitter_max'])) {
                $params['jitter_max'] = (int)$extra['jitter_max'];
            }

            $data = PluginAdmanagerFastApiClient::getInstance()->postQuery('/api/tasks/admin/create', $params);

            PluginAdmanagerAuditLog::write(
                'deploy_task', 'package', (string)$packageId, $name,
                ['task_name' => $name, 'target' => $targetType,
                 'interactive' => $interactive, 'need_reboot' => $needReboot],
                true, ''
            );
            return ['ok' => true, 'message' => $data['message'] ?? '任务已创建'];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public static function resetFailed(int $taskId): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->patch('/api/tasks/admin/reset-failed', ['task_id' => $taskId]);
            PluginAdmanagerAuditLog::write('reset_failed', 'DeployTask', 'task_id=' . $taskId, '', [], true);
            return ['ok' => true, 'message' => $data['message'] ?? '已重置'];
        } catch (Exception $e) {
            PluginAdmanagerAuditLog::write('reset_failed', 'DeployTask', 'task_id=' . $taskId, '', [], false, $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public static function calcProgress(array $prog): int {
        $total = (int)($prog['total'] ?? 0);
        if ($total === 0) return 0;
        $done = (int)($prog['success'] ?? 0) + (int)($prog['failed'] ?? 0);
        return (int)round($done / $total * 100);
    }

    public static function createUninstallTask(string $softwareName, int $clientId): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->postQuery('/api/tasks/admin/create-uninstall', [
                'software_name' => $softwareName,
                'client_id'     => $clientId,
            ]);
            PluginAdmanagerAuditLog::write(
                'deploy_task', 'Task', (string)$clientId,
                '卸载 ' . $softwareName,
                ['target_type' => 'client', 'uninstall' => true, 'client_id' => $clientId],
                true
            );
            return ['ok' => true, 'message' => $data['message'] ?? '卸载任务已创建'];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public static function cancelTask(int $taskId): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->patch('/api/tasks/admin/cancel', ['task_id' => $taskId]);
            PluginAdmanagerAuditLog::write(
                'deploy_task', 'Task', (string)$taskId,
                '取消任务', ['action' => 'cancel'], true
            );
            return ['ok' => true, 'message' => $data['message'] ?? '任务已取消'];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public static function deleteTask(int $taskId): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->delete('/api/tasks/admin/' . $taskId);
            PluginAdmanagerAuditLog::write(
                'deploy_task', 'Task', (string)$taskId,
                '删除任务', ['action' => 'delete'], true
            );
            return ['ok' => true, 'message' => $data['message'] ?? '任务已删除'];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 获取部署配置（全局默认值，可被任务级参数覆盖）
     */
    public static function getDeployConfig(): array {
        return PluginAdmanagerConfig::getDeployConfig();
    }

    // ── 包管理：删除 ────────────────────────────────────────────────────────
    public static function deletePackage(int $id): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->delete('/api/packages/' . $id);
            return ['ok' => true, 'message' => $data['message'] ?? '已删除'];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ── 包管理：编辑元数据 ───────────────────────────────────────────────────
    public static function updatePackage(int $id, string $name, string $version,
                                          string $silentArgs = '', string $desc = ''): array {
        try {
            $data = PluginAdmanagerFastApiClient::getInstance()->patch('/api/packages/' . $id, [
                'name'        => $name,
                'version'     => $version,
                'silent_args' => $silentArgs,
                'description' => $desc,
            ]);
            return ['ok' => true, 'message' => $data['message'] ?? '已更新'];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

}
