<?php
/**
 * inc/config.class.php — 插件配置管理
 * 处理 AD 连接参数、FastAPI 连接参数、部署参数（通过 GLPI sodium 加密存储）
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerConfig extends CommonDBTM
{
    const CONTEXT          = 'plugin:admanager';
    const ENCRYPTED_FIELDS = ['ad_password', 'fastapi_token', 'wecom_corpsecret', 'dingtalk_appsecret', 'feishu_appsecret', 'im_notify_smtp_pass', 'openclaw_token'];
    const DEFAULTS = [
        // AD 连接
        'ad_host'             => '',
        'ad_port'             => '389',
        'ad_use_ssl'          => '0',
        'ad_base_dn'          => '',
        'ad_bind_dn'          => '',
        'ad_password'         => '',
        'ad_ca_cert'         => '',  // CA 证书文件名
        // FastAPI
        'fastapi_url'         => '',
        'fastapi_token'       => '',
        'fastapi_timeout'     => '15',
        'diff_alert_days'     => '7',
        // ── 漏洞修复 AI 解析（openclaw 网关）──
        'openclaw_url'        => 'http://90.90.90.90:18789/v1',
        'openclaw_model'      => 'openclaw',
        'openclaw_token'      => '',   // 加密存储
        'openclaw_timeout'    => '180',
        'vuln_llm_enabled'    => '1',
        'openclaw_prompt'     => '',   // AI 解析系统提示补充（企业背景/人格设定）
        'ou_favorites'        => '[]',
        'ad_sync_interval'    => '21600',  // AD 缓存自动同步间隔（秒），0=禁用自动同步
        'ad_user_ous'         => '',   // 用户搜索 OU，逗号分隔，空=base_dn
        'ad_computer_ous'     => '',   // 计算机搜索 OU，逗号分隔，空=base_dn
        // ── 部署配置 ──
        'deploy_defer_minutes'    => '60',   // 用户推迟间隔（分钟）
        'deploy_defer_max_count'  => '3',    // 最大推迟次数
        'deploy_dialog_title'     => '',     // 安装弹窗标题（空=默认）
        'deploy_dialog_message'   => '',     // 安装弹窗内容（空=默认，支持{software}{version}占位符）
        'deploy_reboot_title'     => '',     // 重启弹窗标题
        'deploy_reboot_message'   => '',     // 重启弹窗内容
        'deploy_default_timeout'  => '600',  // 默认超时（秒）
        'pkg_dir'               => '/home/glpidev/itasset-api/packages',  // 安装包存放目录
        'deploy_jitter_max'       => '60',   // 防惊群最大抖动秒数
        'deploy_silent_override'  => '0',    // 静默覆盖（忽略用户推迟）
        // ── 通讯平台（IM）──
        'wecom_enabled'      => '0',
        'wecom_corpid'       => '',
        'wecom_corpsecret'   => '',   // 加密存储
        'wecom_agentid'      => '',
        'dingtalk_enabled'   => '0',
        'dingtalk_appkey'    => '',
        'dingtalk_appsecret' => '',   // 加密存储
        'dingtalk_agentid'   => '',
        'feishu_enabled'     => '0',
        'feishu_appid'       => '',
        'feishu_appsecret'   => '',   // 加密存储
        // ── IM 通知 ──
        'im_notify_email'        => '',
        'im_notify_smtp_host'    => '',
        'im_notify_smtp_port'    => '465',
        'im_notify_smtp_user'    => '',
        'im_notify_smtp_pass'    => '',   // 加密存储
        'im_notify_smtp_ssl'     => '1',
        'im_notify_from'         => '',
        'im_notify_on_create'    => '1',
        'im_notify_on_disable'   => '1',
        'im_welcome_tpl'         => '',   // 欢迎邮件模板（Markdown）
    ];

    // ── 部署配置默认值（作为独立方法暴露，方便 deploy 页面使用）──
    const DEPLOY_DEFAULTS = [
        'defer_minutes'    => 60,
        'defer_max_count'  => 3,
        'dialog_title'     => '软件安装确认',
        'dialog_message'   => '管理员向你推送了 {software} {version}，是否现在安装？',
        'reboot_title'     => '重启提醒',
        'reboot_message'   => '软件安装完成，需要重启计算机以完成配置。是否立即重启？',
        'default_timeout'  => 600,
        'jitter_max'       => 60,
        'silent_override'  => false,
    ];

    public static function getAll(): array
    {
        $values = Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));
        return array_merge(self::DEFAULTS, $values);
    }

    public static function get(string $key): mixed
    {
        return self::getAll()[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    /**
     * 解密单个加密字段
     */
    private static function decryptField(string $value): string
    {
        if (empty($value)) return '';
        try {
            $key = new GLPIKey();
            $dec = $key->decrypt($value);
            return !empty($dec) ? $dec : $value;
        } catch (\Throwable $e) {
            return $value;
        }
    }

    public static function getAdConfig(): array
    {
        $c = self::getAll();
        return [
            'host'    => $c['ad_host'],
            'port'    => (int)($c['ad_port'] ?: 389),
            'use_ssl' => (bool)$c['ad_use_ssl'],
            'base_dn' => $c['ad_base_dn'],
            'bind_dn' => $c['ad_bind_dn'],
            'password'    => self::decryptField($c['ad_password']),
            'ca_cert_path'=> $c['ad_ca_cert']
                ? PLUGIN_ADMANAGER_DIR . '/certificates/' . basename($c['ad_ca_cert'])
                : '',
            'user_ous'    => array_filter(array_map('trim', explode(',', $c['ad_user_ous']     ?? ''))),
            'computer_ous'=> array_filter(array_map('trim', explode(',', $c['ad_computer_ous']  ?? ''))),
        ];
    }

    public static function getFastApiConfig(): array
    {
        $c = self::getAll();
        return [
            'url'    => rtrim(trim($c['fastapi_url']), '/'),
            // trim()：曾经 .env 是 CRLF 换行导致 GLPI_API_TOKEN 读出来带了隐藏的 \r，
            // 如果这里存的 token 也是当时复制粘贴带出来的，两边永远对不上，或者
            // 更糟——带着 \r 的值拼进 Authorization 头会被 h11 当成非法请求直接拒绝。
            // 这里防御性 trim 一下，不管这份配置是什么时候录入的都不再受影响。
            'token'  => trim(self::decryptField($c['fastapi_token'])),
            'timeout'=> (int)($c['fastapi_timeout'] ?: 15),
        ];
    }

    /**
     * 可信反向代理名单：直连或非名单来源一律回落 REMOTE_ADDR（即真实 IP）。
     * 新增反代 / 负载均衡时只需在此追加，无需改动各调用点。
     */
    private const TRUSTED_PROXIES = ['127.0.0.1', '::1', '172.29.147.120'];

    /**
     * 解析真实客户端 IP（审计日志 / AI 对话审计共用，避免反代后全记成代理 IP）。
     *
     * 安全前提：本站前面只有可信的反向代理（nginx），nginx 以 $remote_addr
     * 覆盖 X-Real-IP，客户端无法伪造。仅在 REMOTE_ADDR 命中可信名单时才信任
     * X-Real-IP / X-Forwarded-For；直连或来源不可信时回落 REMOTE_ADDR（即真实 IP）。
     *
     * 此前该逻辑在 auditlog.class.php 与 agent_proxy.php 各写了一份（逐字相同），
     * 现已统一到此单一真相源。
     *
     * @return string
     */
    public static function resolveClientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($remote, self::TRUSTED_PROXIES, true)) {
            return $remote;
        }
        // 优先取 X-Real-IP（nginx 设置的连接对端，不可伪造）
        $real = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) {
            return $real;
        }
        // 退而取 X-Forwarded-For 最左段（原始客户端）。
        // 用 explode 而非 strtok：避免 strtok 的全局静态指针在多次调用间串扰。
        $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $remote;
    }

    /**
     * 获取部署全局配置（合并默认值）
     */
    public static function getDeployConfig(): array
    {
        $c    = self::getAll();
        $def  = self::DEPLOY_DEFAULTS;
        return [
            'defer_minutes'    => (int)($c['deploy_defer_minutes']    ?: $def['defer_minutes']),
            'defer_max_count'  => (int)($c['deploy_defer_max_count']  ?: $def['defer_max_count']),
            'dialog_title'     => $c['deploy_dialog_title']     ?: $def['dialog_title'],
            'dialog_message'   => $c['deploy_dialog_message']   ?: $def['dialog_message'],
            'reboot_title'     => $c['deploy_reboot_title']     ?: $def['reboot_title'],
            'reboot_message'   => $c['deploy_reboot_message']   ?: $def['reboot_message'],
            'default_timeout'  => (int)($c['deploy_default_timeout']  ?: $def['default_timeout']),
            'jitter_max'       => (int)($c['deploy_jitter_max']       ?: $def['jitter_max']),
            'silent_override'  => (bool)($c['deploy_silent_override'] ?? $def['silent_override']),
            'pkg_dir'          => $c['pkg_dir'] ?: '/home/glpidev/itasset-api/packages',
        ];
    }

    /**
     * 获取连接配置（for config.form.php）
     */
    public static function getConnectionConfig(): array
    {
        $all = self::getAll();
        return [
            'ad_host'         => $all['ad_host'],
            'ad_port'         => $all['ad_port'],
            'ad_use_ssl'      => $all['ad_use_ssl'],
            'ad_base_dn'      => $all['ad_base_dn'],
            'ad_bind_dn'      => $all['ad_bind_dn'],
            'ad_password'     => '',  // 不回显密码
            'ad_ca_cert'      => $all['ad_ca_cert']      ?? '',
            'fastapi_url'     => $all['fastapi_url'],
            'fastapi_token'   => '',  // 不回显 token
            'fastapi_timeout'  => $all['fastapi_timeout'],
            'diff_alert_days'  => $all['diff_alert_days'],
            'ad_user_ous'      => $all['ad_user_ous']      ?? '',
            'ad_computer_ous'  => $all['ad_computer_ous']  ?? '',
        ];
    }

    public static function saveAll(array $input): void
    {
        $allowed = array_intersect_key($input, self::DEFAULTS);

        // 复选框字段防御：浏览器对未勾选的 checkbox 不提交任何值，
        // 导致 $input 里没有该 key，array_intersect_key 过滤后也不在 $allowed 里，
        // Config::setConfigurationValues 就不会更新，数据库值永远保持上次的 1。
        // 此处显式置 0，配合模板里的 hidden 兜底形成双重保险。
        foreach (['ad_use_ssl', 'deploy_silent_override', 'wecom_enabled', 'dingtalk_enabled', 'feishu_enabled', 'im_notify_smtp_ssl', 'im_notify_on_create', 'im_notify_on_disable', 'vuln_llm_enabled'] as $boolField) {
            if (!isset($allowed[$boolField])) {
                $allowed[$boolField] = '0';
            }
        }

        // 文件上传处理：CA 证书（新上传）
        if (isset($_FILES['ad_ca_cert']) && $_FILES['ad_ca_cert']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['ad_ca_cert'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pem','crt','cer','p7b','p7c'], true)) {
                throw new \RuntimeException('CA 证书格式不支持，请上传 .pem .crt .cer .p7b .p7c');
            }
            $certDir = PLUGIN_ADMANAGER_DIR . '/certificates';
            if (!is_dir($certDir)) mkdir($certDir, 0755, true);
            $filename = 'ca_' . time() . '.' . $ext;
            $dest = $certDir . '/' . $filename;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                throw new \RuntimeException('证书上传失败');
            }
            chmod($dest, 0644);
            $allowed['ad_ca_cert'] = $filename;
            // 自动转换 DER→PEM，并部署到 trusted 哈希目录供 libldap 使用
            self::setupTrustedCert($dest);
        }
        // 选择已有证书（目录里已存在的）
        elseif (!empty($input['ad_ca_cert_select'])) {
            $selected = basename($input['ad_ca_cert_select']); // 防路径穿越
            $certPath = PLUGIN_ADMANAGER_DIR . '/certificates/' . $selected;
            if (file_exists($certPath)) {
                $allowed['ad_ca_cert'] = $selected;
                // 重新部署到 trusted 哈希目录（切换证书时同步更新）
                self::setupTrustedCert($certPath);
            }
        }

        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (isset($allowed[$field]) && empty($allowed[$field])) {
                unset($allowed[$field]);
            }
        }
        Config::setConfigurationValues(self::CONTEXT, $allowed);
        PluginAdmanagerAuditLog::write('update_config', 'Config', 'plugin:admanager', '全局配置', array_keys($allowed));

        // AI 解析设置这几个字段需要同步推给 itasset（vuln_service.py 运行时从 itasset 自己的 DB 读，不会来读 GLPI 这边）
        $aiKeys = ['openclaw_url', 'openclaw_model', 'openclaw_timeout', 'vuln_llm_enabled', 'openclaw_token', 'openclaw_prompt'];
        if (array_intersect($aiKeys, array_keys($allowed))) {
            self::syncAiConfigToItasset();
        }
    }

    /** 把当前（解密后的）AI 配置推给 itasset 的 /api/settings/ai，保持两边一致 */
    private static function syncAiConfigToItasset(): void
    {
        try {
            $ai = self::getAiConfig();
            $payload = [
                'openclaw_url'     => $ai['url'],
                'openclaw_model'   => $ai['model'],
                'openclaw_timeout' => $ai['timeout'],
                'llm_enabled'      => $ai['llm_enabled'],
                'openclaw_prompt'  => $ai['prompt'] ?? '',
            ];
            if (!empty($ai['token'])) {
                $payload['openclaw_token'] = $ai['token'];
            }
            PluginAdmanagerFastApiClient::getInstance()->put('/api/settings/ai', $payload);
        } catch (\Throwable $e) {
            // 同步失败不阻断保存本身，只记日志；下次保存或手动测试连接会再次尝试
            Toolbox::logInFile('admanager', 'AI配置同步到itasset失败: ' . $e->getMessage());
        }
    }

    /** 获取 AI 解析配置（供页面展示 + 同步用，token 已解密） */
    public static function getAiConfig(): array
    {
        $c = self::getAll();
        return [
            'url'         => $c['openclaw_url']     ?: 'http://90.90.90.90:18789/v1',
            'model'       => $c['openclaw_model']    ?: 'openclaw',
            'token'       => self::decryptField($c['openclaw_token'] ?? ''),
        'timeout'     => (int)($c['openclaw_timeout'] ?: 180),
        'llm_enabled' => (bool)($c['vuln_llm_enabled'] ?? true),
        'prompt'      => $c['openclaw_prompt']   ?? '',
    ];
    }

    public static function testAiConnection(): array
    {
        try {
            $ai = self::getAiConfig();
            if (empty($ai['token'])) {
                return ['ok' => false, 'message' => '尚未配置 token'];
            }
            $result = PluginAdmanagerFastApiClient::getInstance()->post('/api/settings/ai/test', [
                'openclaw_url'     => $ai['url'],
                'openclaw_model'   => $ai['model'],
                'openclaw_timeout' => min($ai['timeout'], 30),
                'llm_enabled'      => true,
                'openclaw_token'   => $ai['token'],
            ]);
            if (!empty($result['ok'])) {
                return ['ok' => true, 'message' => 'AI 网关连接成功（耗时 ' . ($result['latency_ms'] ?? '?') . ' ms）'];
            }
            return ['ok' => false, 'message' => 'AI 网关连接失败：' . ($result['error'] ?? '未知错误')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '请求失败：' . $e->getMessage()];
        }
    }


    /** 列出 certificates 目录下的证书文件 */
    public static function listCertificates(): array {
        $dir = PLUGIN_ADMANAGER_DIR . '/certificates';
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/*.{pem,crt,cer,p7b,p7c}', GLOB_BRACE) ?: [];
        return array_map('basename', $files);
    }

    /**
     * 将上传的 CA 证书部署到 certificates/trusted/ 哈希目录。
     *
     * 解决三个叠加问题：
     *  1. DER 格式：libldap 只认 PEM，自动用 openssl 转换
     *  2. CACERTFILE 不可靠：改用 c_rehash 风格的哈希目录（LDAPTLS_CACERTDIR）
     *  3. Hostname 校验：connect() 里用 LDAPTLS_REQCERT=allow，此处只负责证书链
     *
     * @param string $srcPath  刚上传/选中的原始证书路径
     */
    private static function setupTrustedCert(string $srcPath): void
    {
        $trustedDir = PLUGIN_ADMANAGER_DIR . '/certificates/trusted';
        if (!is_dir($trustedDir)) {
            mkdir($trustedDir, 0755, true);
        }

        $pemPath = $trustedDir . '/ca.pem';

        // ── Step 1: 格式检测与转换 ──────────────────────────────────────────
        $content = file_get_contents($srcPath);
        if ($content === false) return;

        if (strpos($content, '-----BEGIN') !== false) {
            // 已是 PEM 格式，直接复制
            copy($srcPath, $pemPath);
        } else {
            // DER 格式（.cer/.der 从 AD 导出时常见），转换为 PEM
            $out = [];
            exec(sprintf(
                'openssl x509 -inform DER -in %s -out %s 2>&1',
                escapeshellarg($srcPath),
                escapeshellarg($pemPath)
            ), $out, $rc);

            if ($rc !== 0 || !file_exists($pemPath)) {
                // 兜底：尝试 PKCS#7 容器（.p7b 有时打包多证书）
                exec(sprintf(
                    'openssl pkcs7 -inform DER -in %s -print_certs -out %s 2>&1',
                    escapeshellarg($srcPath),
                    escapeshellarg($pemPath)
                ), $out, $rc);
            }
        }

        if (!file_exists($pemPath) || filesize($pemPath) === 0) {
            // 转换失败，不继续（connect() 里会降级到 REQCERT=never）
            return;
        }
        chmod($pemPath, 0644);

        // ── Step 2: 生成 c_rehash 风格的哈希符号链接 ────────────────────────
        // 清理旧链接（避免过期 CA 残留）
        foreach (glob($trustedDir . '/*.0') ?: [] as $old) {
            unlink($old);
        }

        // openssl x509 -hash 输出 libldap 查找时使用的 subject hash
        $hash = trim(shell_exec(sprintf(
            'openssl x509 -hash -noout -in %s 2>/dev/null',
            escapeshellarg($pemPath)
        )) ?? '');

        if ($hash && preg_match('/^[a-f0-9]+$/i', $hash)) {
            // 相对符号链接，目录移动后依然有效
            symlink('ca.pem', $trustedDir . '/' . $hash . '.0');
        }
    }

    public static function getTypeName($nb = 0): string { return '连接配置'; }

    public static function testAdConnection(): array
    {
        try {
            $ldap = PluginAdmanagerAdLdap::getInstance(true);
            $ldap->connect();
            $ldap->disconnect();
            return ['ok' => true, 'message' => 'AD 连接成功'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'AD 连接失败：' . $e->getMessage()];
        }
    }

    public static function testFastApiConnection(): array
    {
        try {
            PluginAdmanagerFastApiClient::getInstance()->get('/api/dashboard');
            return ['ok' => true, 'message' => 'FastAPI 连接成功'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'FastAPI 连接失败：' . $e->getMessage()];
        }
    }
}

