<?php
/**
 * inc/imservice.class.php — IM 平台业务层
 * 平台无关的核心逻辑：绑定管理、用户同步、状态联动、邮件通知
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerIMService
{
    /* ── 工厂：根据配置构造已启用的连接器 ────────────────── */
    public static function getConnectors(): array {
        $cfg  = PluginAdmanagerConfig::getAll();
        $list = [];

        // 加密字段必须解密后使用
        $key = new GLPIKey();
        $decrypt = function(string $val) use ($key): string {
            if (empty($val)) return '';
            try { $dec = $key->decrypt($val); return !empty($dec) ? $dec : $val; }
            catch (\Throwable $e) { return $val; }
        };

        if (($cfg['wecom_enabled'] ?? '0') === '1') {
            $list['wecom'] = new PluginAdmanagerWecom([
                'corpid'     => $cfg['wecom_corpid']              ?? '',
                'corpsecret' => $decrypt($cfg['wecom_corpsecret'] ?? ''),
                'agentid'    => $cfg['wecom_agentid']             ?? '',
            ]);
        }
        if (($cfg['dingtalk_enabled'] ?? '0') === '1') {
            $list['dingtalk'] = new PluginAdmanagerDingtalk([
                'appkey'    => $cfg['dingtalk_appkey']               ?? '',
                'appsecret' => $decrypt($cfg['dingtalk_appsecret']   ?? ''),
                'agentid'   => $cfg['dingtalk_agentid']              ?? '',
            ]);
        }
        if (($cfg['feishu_enabled'] ?? '0') === '1') {
            $list['feishu'] = new PluginAdmanagerFeishu([
                'appid'     => $cfg['feishu_appid']              ?? '',
                'appsecret' => $decrypt($cfg['feishu_appsecret'] ?? ''),
            ]);
        }
        return $list;
    }

    public static function getConnector(string $platform): ?PluginAdmanagerIMConnector {
        return self::getConnectors()[$platform] ?? null;
    }

    /* ══════════════════════════════════════════════════════
       绑定管理
    ══════════════════════════════════════════════════════ */

    /** 获取 AD 用户在所有/指定平台的绑定 */
    public static function getBindings(string $sam = '', string $platform = ''): array {
        global $DB;
        $where = [];
        if ($sam)      $where['sam']      = $sam;
        if ($platform) $where['platform'] = $platform;
        $rows = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_admanager_im_bindings', 'WHERE' => $where]) as $r) {
            $rows[] = $r;
        }
        return $rows;
    }

    /** 写入/更新绑定记录 */
    public static function saveBinding(string $sam, string $platform, string $uid,
                                        string $name = '', string $dept_id = '',
                                        string $status = 'active'): void {
        global $DB;
        $now  = date('Y-m-d H:i:s');
        $existing = iterator_to_array($DB->request([
            'FROM'  => 'glpi_plugin_admanager_im_bindings',
            'WHERE' => ['sam' => $sam, 'platform' => $platform],
            'LIMIT' => 1,
        ]));
        if ($existing) {
            $DB->update('glpi_plugin_admanager_im_bindings', [
                'platform_uid'  => $uid,
                'platform_name' => $name,
                'dept_id'       => $dept_id,
                'status'        => $status,
                'date_mod'      => $now,
            ], ['sam' => $sam, 'platform' => $platform]);
        } else {
            $DB->insert('glpi_plugin_admanager_im_bindings', [
                'sam'           => $sam,
                'platform'      => $platform,
                'platform_uid'  => $uid,
                'platform_name' => $name,
                'dept_id'       => $dept_id,
                'status'        => $status,
                'bound_at'      => $now,
                'date_mod'      => $now,
            ]);
        }
    }

    /** 解绑 */
    public static function removeBinding(string $sam, string $platform): void {
        global $DB;
        $DB->delete('glpi_plugin_admanager_im_bindings', ['sam' => $sam, 'platform' => $platform]);
    }

    /* ══════════════════════════════════════════════════════
       OU ↔ 平台部门映射
    ══════════════════════════════════════════════════════ */

    public static function getDeptMappings(string $platform = ''): array {
        global $DB;
        $where = $platform ? ['platform' => $platform] : [];
        return iterator_to_array($DB->request(['FROM' => 'glpi_plugin_admanager_im_depts', 'WHERE' => $where]));
    }

    public static function saveDeptMapping(string $ou_dn, string $platform,
                                            string $dept_id, string $dept_name = ''): void {
        global $DB;
        $now = date('Y-m-d H:i:s');
        $ex  = iterator_to_array($DB->request([
            'FROM'  => 'glpi_plugin_admanager_im_depts',
            'WHERE' => ['ou_dn' => $ou_dn, 'platform' => $platform],
            'LIMIT' => 1,
        ]));
        if ($ex) {
            $DB->update('glpi_plugin_admanager_im_depts',
                ['dept_id' => $dept_id, 'dept_name' => $dept_name, 'date_mod' => $now],
                ['ou_dn' => $ou_dn, 'platform' => $platform]);
        } else {
            $DB->insert('glpi_plugin_admanager_im_depts', [
                'ou_dn' => $ou_dn, 'platform' => $platform,
                'dept_id' => $dept_id, 'dept_name' => $dept_name, 'date_mod' => $now,
            ]);
        }
    }

    /* ══════════════════════════════════════════════════════
       新用户入职联动
    ══════════════════════════════════════════════════════ */

    /**
     * 新建 AD 用户后调用此方法
     * 对所有已启用平台：查找 OU 对应部门 → 创建平台账号 → 发消息 + 邮件
     *
     * @param array $adUser  ['sam','display','mail','mobile','ou_dn','password','position']
     */
    public static function onUserCreated(array $adUser): array {
        $results = [];
        $cfg     = PluginAdmanagerConfig::getAll();

        foreach (self::getConnectors() as $platform => $connector) {
            try {
                // 查找 OU → 平台部门映射
                $dept_id = self::findDeptId($adUser['ou_dn'] ?? '', $platform);

                // 创建平台账号
                $uid = $connector->createUser([
                    'name'     => $adUser['display'] ?? $adUser['sam'],
                    'mobile'   => $adUser['mobile']   ?? '',
                    'email'    => $adUser['mail']      ?? '',
                    'position' => $adUser['position']  ?? '',
                    'dept_ids' => $dept_id ? [$dept_id] : [],
                ]);

                // 写绑定记录
                self::saveBinding($adUser['sam'], $platform, $uid,
                    $adUser['display'] ?? $adUser['sam'], $dept_id ?? '');

                // 平台内发送欢迎消息
                if ($cfg['im_notify_on_create'] ?? '1') {
                    $connector->sendMessage($uid, self::buildWelcomeText($adUser, $platform));
                }

                $results[$platform] = ['ok' => true, 'uid' => $uid];
            } catch (\Throwable $e) {
                $results[$platform] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        // 发邮件通知
        if ($cfg['im_notify_email'] ?? '') {
            self::sendNotifyEmail($adUser, $results, $cfg);
        }

        return $results;
    }

    /* ══════════════════════════════════════════════════════
       状态联动：AD 禁用/启用 → 同步到平台
    ══════════════════════════════════════════════════════ */

    public static function onUserDisabled(string $sam): array {
        return self::syncUserStatus($sam, false);
    }

    public static function onUserEnabled(string $sam): array {
        return self::syncUserStatus($sam, true);
    }

    private static function syncUserStatus(string $sam, bool $enabled): array {
        $bindings = self::getBindings($sam);
        $results  = [];
        foreach ($bindings as $b) {
            $connector = self::getConnector($b['platform']);
            if (!$connector) continue;
            try {
                $ok = $connector->setUserEnabled($b['platform_uid'], $enabled);
                if ($ok) {
                    global $DB;
                    $DB->update('glpi_plugin_admanager_im_bindings',
                        ['status' => $enabled ? 'active' : 'disabled', 'date_mod' => date('Y-m-d H:i:s')],
                        ['id' => $b['id']]);
                }
                $results[$b['platform']] = ['ok' => $ok];
            } catch (\Throwable $e) {
                $results[$b['platform']] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        PluginAdmanagerAuditLog::write(
            $enabled ? 'im_enable' : 'im_disable', 'IMBinding', $sam, $sam,
            $results, !empty(array_filter($results, fn($r) => $r['ok']))
        );
        return $results;
    }

    /* ══════════════════════════════════════════════════════
       平台用户 → AD 状态联动（平台侧禁用 → AD 同步禁用）
    ══════════════════════════════════════════════════════ */

    /**
     * 轮询检查平台用户状态，与 AD 同步
     * 建议由 cron 每小时调用一次
     */
    public static function syncPlatformStatusToAD(): array {
        $results = [];
        $ldap    = PluginAdmanagerAdLdap::getInstance();

        foreach (self::getConnectors() as $platform => $connector) {
            try {
                $platformUsers = $connector->getAllUsers();
                foreach ($platformUsers as $pu) {
                    // 找对应 AD 绑定
                    $bindings = self::getBindings('', $platform);
                    $match = array_filter($bindings, fn($b) => $b['platform_uid'] === $pu['userid']);
                    if (!$match) continue;
                    $b   = reset($match);
                    $sam = $b['sam'];

                    // 查 AD 当前状态
                    $adUsers = $ldap->searchUsers($sam);
                    if (empty($adUsers)) continue;
                    $adUser     = $adUsers[0];
                    $adDisabled = (bool)(($adUser['useraccountcontrol'] ?? 0) & 0x2);
                    $imDisabled = !$pu['enabled'];

                    if ($imDisabled && !$adDisabled) {
                        // 平台禁用 → AD 禁用 → 级联禁用其他平台
                        $ldap->disableUser($adUser['dn']);
                        self::onUserDisabled($sam);
                        $results[] = "AD + 全平台禁用 {$sam}（{$platform} 侧已禁用）";
                        PluginAdmanagerAuditLog::write('im_sync_disable','IMBinding',$sam,$sam,
                            ['platform'=>$platform,'platform_uid'=>$pu['userid']],true);
                    } elseif (!$imDisabled && $adDisabled) {
                        // 平台启用 → AD 启用（可选，按策略决定）
                        // 默认不自动启用 AD（防止误操作），只记录日志
                        $results[] = "注意：{$sam} 在 {$platform} 已启用但 AD 仍禁用，需手动处理";
                    }
                }
            } catch (\Throwable $e) {
                $results[] = "[{$platform}] 同步失败：" . $e->getMessage();
            }
        }
        return $results;
    }

    /* ══════════════════════════════════════════════════════
       自动匹配：按手机/邮箱将平台用户与 AD 用户匹配
    ══════════════════════════════════════════════════════ */

    public static function autoMatch(string $platform): array {
        $connector = self::getConnector($platform);
        if (!$connector) return [];

        $ldap        = PluginAdmanagerAdLdap::getInstance();
        $platformUsers = $connector->getAllUsers();
        $matches     = [];

        foreach ($platformUsers as $pu) {
            // 已绑定跳过
            $bound = self::getBindings('', $platform);
            if (array_filter($bound, fn($b) => $b['platform_uid'] === $pu['userid'])) {
                continue;
            }

            $candidates = [];
            // 按手机号匹配
            if ($pu['mobile']) {
                $found = $ldap->searchUsers($pu['mobile']);
                foreach ($found as $au) {
                    $candidates[] = ['ad' => $au, 'score' => 90, 'match_by' => 'mobile'];
                }
            }
            // 按邮箱匹配
            if ($pu['email'] && empty($candidates)) {
                $found = $ldap->searchUsers($pu['email']);
                foreach ($found as $au) {
                    $candidates[] = ['ad' => $au, 'score' => 85, 'match_by' => 'email'];
                }
            }
            // 按姓名模糊匹配
            if ($pu['name'] && empty($candidates)) {
                $found = $ldap->searchUsers($pu['name']);
                foreach ($found as $au) {
                    similar_text($pu['name'], $au['display'] ?? $au['cn'], $pct);
                    if ($pct > 70) {
                        $candidates[] = ['ad' => $au, 'score' => (int)$pct, 'match_by' => 'name'];
                    }
                }
            }

            $matches[] = [
                'platform_user' => $pu,
                'candidates'    => array_slice(
                    array_map(fn($c) => [
                        'sam'      => $c['ad']['sam']     ?? $c['ad']['samaccountname'] ?? '',
                        'display'  => $c['ad']['display'] ?? $c['ad']['cn'] ?? '',
                        'dn'       => $c['ad']['dn']       ?? '',
                        'mail'     => $c['ad']['mail']     ?? '',
                        'score'    => $c['score'],
                        'match_by' => $c['match_by'],
                    ], $candidates),
                    0, 3
                ),
                'best_score' => !empty($candidates) ? max(array_column($candidates, 'score')) : 0,
            ];
        }

        // 按最佳匹配分排序，高置信度在前
        usort($matches, fn($a, $b) => $b['best_score'] <=> $a['best_score']);
        return $matches;
    }

    /* ══════════════════════════════════════════════════════
       邮件通知（独立 SMTP，不依赖 GLPI）
    ══════════════════════════════════════════════════════ */

    public static function sendNotifyEmail(array $adUser, array $imResults, array $cfg): bool {
        $to      = $cfg['im_notify_email'] ?? '';
        $host    = $cfg['im_notify_smtp_host'] ?? '';
        $port    = (int)($cfg['im_notify_smtp_port'] ?? 465);
        $user    = $cfg['im_notify_smtp_user'] ?? '';
        $pass    = $cfg['im_notify_smtp_pass'] ?? '';
        $ssl     = ($cfg['im_notify_smtp_ssl'] ?? '1') === '1';
        $from    = $cfg['im_notify_from']       ?? $user;

        if (!$to || !$host || !$user) return false;

        $subject = '新员工账号创建通知：' . ($adUser['display'] ?? $adUser['sam']);
        $body    = self::buildEmailHtml($adUser, $imResults);

        // 用 PHPMailer（GLPI 自带）或 socket 发送
        // GLPI 已包含 PHPMailer，直接用
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;
            $mail->SMTPSecure = $ssl ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                                     : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($from, '运维管理系统');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('[IMService] 邮件发送失败：' . $e->getMessage());
            return false;
        }
    }

    /* ── 私有辅助 ─────────────────────────────────────── */

    private static function findDeptId(string $ou_dn, string $platform): ?string {
        global $DB;
        $rows = iterator_to_array($DB->request([
            'FROM'  => 'glpi_plugin_admanager_im_depts',
            'WHERE' => ['ou_dn' => $ou_dn, 'platform' => $platform],
            'LIMIT' => 1,
        ]));
        return $rows ? ($rows[0]['dept_id'] ?? null) : null;
    }

    private static function buildWelcomeText(array $u, string $platform): string {
        $pname = ['wecom'=>'企业微信','dingtalk'=>'钉钉','feishu'=>'飞书'][$platform] ?? $platform;
        return "您好，{$u['display']}！\n\n"
            . "您的账号已创建成功，以下是您的登录信息：\n"
            . "AD账号：{$u['sam']}\n"
            . "初始密码：{$u['password']}\n\n"
            . "请登录后及时修改密码。\n如有问题请联系 IT 部门。\n\n"
            . "【此消息由 {$pname} 系统自动发送】";
    }

    private static function buildEmailHtml(array $u, array $imResults): string {
        $rows = '';
        foreach ($imResults as $platform => $r) {
            $pname  = ['wecom'=>'企业微信','dingtalk'=>'钉钉','feishu'=>'飞书'][$platform] ?? $platform;
            $status = $r['ok']
                ? "<span style='color:green'>✓ 创建成功（ID: {$r['uid']}）</span>"
                : "<span style='color:red'>✗ {$r['error']}</span>";
            $rows .= "<tr><td>{$pname}</td><td>{$status}</td></tr>";
        }
        $sam     = htmlspecialchars($u['sam']      ?? '');
        $display = htmlspecialchars($u['display']  ?? '');
        $mail    = htmlspecialchars($u['mail']     ?? '');
        $ou      = htmlspecialchars($u['ou_dn']    ?? '');
        $now     = date('Y-m-d H:i:s');
        return <<<HTML
<html><body style="font-family:sans-serif;color:#333">
<h2 style="color:#0d6efd">新员工账号创建通知</h2>
<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:600px">
  <tr style="background:#f0f0f0"><th>字段</th><th>值</th></tr>
  <tr><td>姓名</td><td><b>{$display}</b></td></tr>
  <tr><td>AD 账号</td><td><b>{$sam}</b></td></tr>
  <tr><td>邮箱</td><td>{$mail}</td></tr>
  <tr><td>所属 OU</td><td>{$ou}</td></tr>
  <tr><td>创建时间</td><td>{$now}</td></tr>
</table>
<h3 style="margin-top:20px">通讯平台账号状态</h3>
<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:600px">
  <tr style="background:#f0f0f0"><th>平台</th><th>状态</th></tr>
  {$rows}
</table>
<p style="color:#888;font-size:12px;margin-top:20px">此邮件由运维管理系统自动发送，请勿回复。</p>
</body></html>
HTML;
    }
}
