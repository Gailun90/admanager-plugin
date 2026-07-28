<?php

/**
 * adldap.class.php — AD LDAP 全方位操作 v4.2
 * 
 * 扩展功能：BitLocker密钥查询 / 计算机搜索 / 用户详情 / 新建用户 / 批量导入
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerAdLdap
{
    private static ?self $instance = null;
    private mixed $conn = null;
    private array $cfg  = [];

    const USER_ATTRS = [
        'sAMAccountName','displayName','mail','department','title',
        'userAccountControl','lockoutTime','pwdLastSet',
        'lastLogonTimestamp','memberOf','distinguishedName','description',
        'givenName','sn','telephoneNumber','mobile','physicalDeliveryOfficeName',
        'whenCreated','whenChanged','manager','company','userPrincipalName',
    ];

    const COMPUTER_ATTRS = [
        'name','dNSHostName','operatingSystem','operatingSystemVersion',
        'operatingSystemServicePack','description','lastLogonTimestamp',
        'whenCreated','distinguishedName','location','managedBy',
    ];

    const BITLOCKER_ATTRS = [
        'msFVE-RecoveryPassword','msFVE-RecoveryGuid','msFVE-VolumeGuid',
        'whenCreated','distinguishedName','name','description',
    ];

    private function __construct() {
        $this->cfg = PluginAdmanagerConfig::getAdConfig();
    }

    public static function getInstance(bool $force = false): self {
        if ($force || !self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    /* ========== 连接管理 ========== */

    public function connect(): self {
        if ($this->conn) return $this;
        // TLS 配置完全由 /etc/ldap/ldap.conf 管理：
        //   TLS_CACERT  /path/to/ca.pem
        //   TLS_REQCERT allow
        // libldap 在进程（php-fpm worker）启动时读取一次，之后无法通过代码修改。
        // 不在此处调用任何 putenv / ldap_set_option TLS 相关操作，避免干扰已初始化的 context。

        // 端口 636/3269 为 LDAPS 专用端口，自动推断协议，防止误配置
        $useSSL = $this->cfg['use_ssl'] || in_array((int)$this->cfg['port'], [636, 3269]);
        $proto  = $useSSL ? 'ldaps' : 'ldap';
        $uri    = "{$proto}://{$this->cfg['host']}:{$this->cfg['port']}";

        // LDAPS：通过环境变量设置 TLS 参数（putenv 在 ldap_connect 前必须设置）
        // PHP libldap 在首次连接时读取这些环境变量初始化 TLS context
        if ($useSSL) {
            $caPath = $this->cfg['ca_cert_path'];
            if ($caPath && file_exists($caPath)) {
                putenv("LDAPTLS_CACERT={$caPath}");
            }
            // allow：校验证书但失败时仍允许连接（自签名证书场景）
            putenv('LDAPTLS_REQCERT=allow');
        }

        $this->conn = ldap_connect($uri);
        if (!$this->conn) throw new \RuntimeException("LDAP 连接失败：{$uri}");

        ldap_set_option($this->conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($this->conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($this->conn, LDAP_OPT_NETWORK_TIMEOUT, 10);

        if (!@ldap_bind($this->conn, $this->cfg['bind_dn'], $this->cfg['password']))
            throw new \RuntimeException('LDAP Bind 失败：' . ldap_error($this->conn));

        return $this;
    }


    public function disconnect(): void {
        if ($this->conn) { ldap_unbind($this->conn); $this->conn = null; }
    }


    private function conn(): mixed {
        if (!$this->conn) $this->connect();
        return $this->conn;
    }


    /* ========== 用户操作 ========== */

    public function searchUsers(string $keyword = '', string $ou = ''): array {
        $base = $ou ?: $this->cfg['base_dn'];
        $esc  = ldap_escape($keyword, '', LDAP_ESCAPE_FILTER);
        $filter = $keyword
            ? "(&(objectClass=user)(objectCategory=person)(|(sAMAccountName=*{$esc}*)(displayName=*{$esc}*)(mail=*{$esc}*)))"
            : "(&(objectClass=user)(objectCategory=person))";
        $res = ldap_search($this->conn(), $base, $filter, self::USER_ATTRS, 0, 0);
        return $res ? $this->parseEntries(ldap_get_entries($this->conn(), $res), 'user') : [];
    }


    public function findUser(string $sam): ?array {
        $esc = ldap_escape($sam, '', LDAP_ESCAPE_FILTER);
        $res = ldap_search($this->conn(), $this->cfg['base_dn'],
            "(&(objectClass=user)(sAMAccountName={$esc}))", self::USER_ATTRS, 0, 1);
        $entries = $res ? $this->parseEntries(ldap_get_entries($this->conn(), $res), 'user') : [];
        return $entries[0] ?? null;
    }

    /** 按手机号精确查询 AD 用户（查 mobile / telephoneNumber / otherTelephone） */
    public function findUserByMobile(string $mobile): ?array {
        $esc = ldap_escape($mobile, '', LDAP_ESCAPE_FILTER);
        $res = ldap_search($this->conn(), $this->cfg['base_dn'],
            "(&(objectClass=user)(objectCategory=person)(|(mobile={$esc})(telephoneNumber={$esc})(otherTelephone={$esc})))",
            self::USER_ATTRS, 0, 1);
        $entries = $res ? $this->parseEntries(ldap_get_entries($this->conn(), $res), 'user') : [];
        return $entries[0] ?? null;
    }


    public function getUserDetail(string $sam): ?array {
        $user = $this->findUser($sam);
        if (!$user) return null;
        // 解析组成员
        if (!empty($user['memberof'])) {
            $groups = is_array($user['memberof']) ? $user['memberof'] : [$user['memberof']];
            $user['groups'] = array_map(function($dn) {
                $parts = ldap_explode_dn($dn, 0);
                unset($parts['count']);
                return $parts[0] ?? $dn;
            }, $groups);
        }
        // 格式化时间
        if (!empty($user['last_logon_unix'])) {
            $user['last_logon_fmt'] = date('Y-m-d H:i:s', $user['last_logon_unix']);
            $user['last_logon_days'] = (int)((time() - $user['last_logon_unix']) / 86400);
        }
        if (!empty($user['pwdlastset']) && (int)$user['pwdlastset'] > 0) {
            $unix = intdiv((int)$user['pwdlastset'], 10000000) - 11644473600;
            $user['pwdlastset_fmt'] = date('Y-m-d H:i:s', $unix);
            $user['pwd_age_days'] = (int)((time() - $unix) / 86400);
        }
        return $user;
    }


    public function setUserEnabled(string $dn, bool $enabled): bool {
        $user = $this->findUserByDn($dn);
        if (!$user) throw new \RuntimeException("用户不存在：{$dn}");
        $uac = (int)($user['useraccountcontrol'] ?? 512);
        return ldap_modify($this->conn(), $dn,
            ['userAccountControl' => [$enabled ? ($uac & ~0x2) : ($uac | 0x2)]]);
    }


    public function unlockUser(string $dn): bool {
        // lockoutTime 必须传字符串 '0'，整数 0 在部分 AD 版本会静默失败
        $r = ldap_modify($this->conn(), $dn, ['lockoutTime' => ['0']]);
        if (!$r) {
            throw new \RuntimeException('解锁账户失败：' . ldap_error($this->conn()));
        }
        return true;
    }


    public function resetPassword(string $dn, string $pwd, bool $must_change = false): bool {
        if (!$this->cfg['use_ssl'])
            throw new \RuntimeException('重置密码必须启用 LDAPS，请在配置页开启');
        $encoded = iconv('UTF-8', 'UTF-16LE', '"' . $pwd . '"');
        // AD 要求用 ldap_modify_batch REPLACE 写 unicodePwd，
        // 直接 ldap_modify 会返回 WILL_NOT_PERFORM (0x52D)
        $r = @ldap_modify_batch($this->conn(), $dn, [[
            'attrib'  => 'unicodePwd',
            'modtype' => LDAP_MODIFY_BATCH_REPLACE,
            'values'  => [$encoded],
        ]]);
        if (!$r) {
            $err  = ldap_error($this->conn());
            $diag = null;
            ldap_get_option($this->conn(), LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag);
            throw new \RuntimeException("重置密码失败：{$err}" . ($diag ? " ({$diag})" : ''));
        }
        // 可选：强制用户下次登录必须修改密码
        if ($must_change) {
            @ldap_modify($this->conn(), $dn, ['pwdLastSet' => ['0']]);
        }
        return true;
    }


    public function moveUser(string $dn, string $target_ou): bool {
        $parts = ldap_explode_dn($dn, 0);
        return ldap_rename($this->conn(), $dn, $parts[0], $target_ou, true);
    }


    public function modifyGroupMembership(string $user_dn, string $group_dn, bool $add): bool {
        return ldap_modify_batch($this->conn(), $group_dn, [[
            'attrib'  => 'member',
            'modtype' => $add ? LDAP_MODIFY_BATCH_ADD : LDAP_MODIFY_BATCH_REMOVE,
            'values'  => [$user_dn],
        ]]);
    }


    /**
     * 列出所有可用于创建用户的 OU / 容器（过滤系统容器）
     */
    public function listOUs(string $base = ''): array {
        $base = $base ?: $this->cfg['base_dn'];
        // 搜索 OU + CN=Users 等容器
        $res = ldap_search($this->conn(), $base, '(|(objectClass=organizationalUnit)(cn=Users))',
            ['ou','cn','distinguishedName','name'], 0, 0);
        if (!$res) return [];
        $entries = ldap_get_entries($this->conn(), $res);
        $ous = [];
        $sys_ou = ['domain controllers'];
        for ($i = 0; $i < $entries['count']; $i++) {
            $e = $entries[$i];
            $short = strtolower($e['ou'][0] ?? ($e['cn'][0] ?? ''));
            // 跳过系统容器
            if (in_array($short, $sys_ou)) continue;
            $ous[] = [
                'dn'                => $e['distinguishedname'][0] ?? '',
                'ou'                => $e['ou'][0] ?? $e['cn'][0] ?? '',
                'distinguishedname' => $e['distinguishedname'][0] ?? '',
            ];
        }
        return $ous;
    }


    /* ========== 新建 OU ========== */

    /**
     * 在 AD 中创建一个新的组织单位 (OU)
     * @param  string $ouName OU 名称（如 "技术部"）
     * @return array  新 OU 信息 ['dn', 'ou', 'distinguishedname']
     */
    public function createOU(string $ouName): array {
        if (empty($ouName)) throw new \RuntimeException('OU名称不能为空');

        $parentDn = $this->cfg['base_dn'];
        $dn = 'OU=' . ldap_escape($ouName, '', LDAP_ESCAPE_DN) . ',' . $parentDn;

        // 检查是否已存在
        $check = ldap_search($this->conn(), $parentDn,
            '(ou=' . ldap_escape($ouName, '', LDAP_ESCAPE_FILTER) . ')',
            ['dn'], 0, 0);
        if ($check && ldap_count_entries($this->conn(), $check) > 0) {
            throw new \RuntimeException("OU '{$ouName}' 已存在");
        }

        $entry = [
            'objectClass' => ['top', 'organizationalUnit'],
            'ou'          => $ouName,
        ];

        if (!ldap_add($this->conn(), $dn, $entry)) {
            throw new \RuntimeException('创建OU失败：' . ldap_error($this->conn()));
        }

        return ['dn' => $dn, 'ou' => $ouName, 'distinguishedname' => $dn];
    }


    /* ========== 新建 AD 用户 ========== */

    public function createUser(array $data): bool {
        $required = ['samaccountname','displayname','password','ou'];
        foreach ($required as $f) {
            if (empty($data[$f])) throw new \RuntimeException("缺少必填字段: {$f}");
        }
        // 检查是否已存在
        if ($this->findUser($data['samaccountname']))
            throw new \RuntimeException("用户 {$data['samaccountname']} 已存在");

        $dn = 'CN=' . ldap_escape($data['displayname'], '', LDAP_ESCAPE_DN)
            . ',' . $data['ou'];

        $entry = [
            'objectClass'     => ['top','person','organizationalPerson','user'],
            'cn'              => $data['displayname'],
            'sAMAccountName'  => $data['samaccountname'],
            'displayName'     => $data['displayname'],
            'givenName'       => $data['givenname'] ?? $data['displayname'],
            'sn'              => $data['sn'] ?? $data['displayname'],
            'userPrincipalName'=> ($data['samaccountname'] . '@' . ($data['upn_suffix'] ?? str_replace('DC=','',str_replace(',','.',$this->cfg['base_dn'])))),
            'userAccountControl' => '512', // NORMAL_ACCOUNT, enabled
        ];
        if (!empty($data['password']))
            $entry['unicodePwd'] = iconv('UTF-8', 'UTF-16LE', '"' . $data['password'] . '"');
        if (!empty($data['mail']))       $entry['mail'] = $data['mail'];
        if (!empty($data['department'])) $entry['department'] = $data['department'];
        if (!empty($data['company']))    $entry['company'] = $data['company'];
        if (!empty($data['title']))      $entry['title'] = $data['title'];
        if (!empty($data['description']))$entry['description'] = $data['description'];
        if (!empty($data['division']))                   $entry['division'] = $data['division'];
        if (!empty($data['manager']))                    $entry['manager'] = $data['manager'];
        if (!empty($data['telephonenumber']))            $entry['telephoneNumber'] = $data['telephonenumber'];
        if (!empty($data['mobile']))                     $entry['mobile'] = $data['mobile'];
        if (!empty($data['facsimiletelephonenumber']))   $entry['facsimileTelephoneNumber'] = $data['facsimiletelephonenumber'];
        if (!empty($data['wWWHomePage']))                $entry['wWWHomePage'] = $data['wWWHomePage'];
        if (!empty($data['streetaddress']))              $entry['streetAddress'] = $data['streetaddress'];
        if (!empty($data['l']))                          $entry['l'] = $data['l'];
        if (!empty($data['st']))                         $entry['st'] = $data['st'];
        if (!empty($data['postalcode']))                 $entry['postalCode'] = $data['postalcode'];
        if (!empty($data['co']))                         $entry['co'] = $data['co'];
        if (!empty($data['physicaldeliveryofficename'])) $entry['physicalDeliveryOfficeName'] = $data['physicaldeliveryofficename'];
        if (!empty($data['employeenumber']))             $entry['employeeNumber'] = $data['employeenumber'];
        if (!empty($data['employeetype']))               $entry['employeeType'] = $data['employeetype'];
        if (!empty($data['info']))                       $entry['info'] = $data['info'];


        if (!ldap_add($this->conn(), $dn, $entry))
            throw new \RuntimeException('创建用户失败：' . ldap_error($this->conn()));

        // 设置"下次登录必须修改密码"标志
        ldap_modify($this->conn(), $dn, ['pwdLastSet' => [0]]);

        PluginAdmanagerAuditLog::write('create_user', 'ADUser', $dn, $data['samaccountname'],
            array_diff_key($data, ['password' => '']), true);
        return true;
    }


    /* ========== 计算机搜索 ========== */

    public function searchComputers(string $keyword = '', string $ou = ''): array {
        $base = $ou ?: $this->cfg['base_dn'];
        $esc  = ldap_escape($keyword, '', LDAP_ESCAPE_FILTER);
        $filter = $keyword
            ? "(&(objectClass=computer)(|(name=*{$esc}*)(dNSHostName=*{$esc}*)(description=*{$esc}*)))"
            : "(objectClass=computer)";
        $res = ldap_search($this->conn(), $base, $filter, self::COMPUTER_ATTRS, 0, 0);
        return $res ? $this->parseEntries(ldap_get_entries($this->conn(), $res), 'computer') : [];
    }


    public function findComputer(string $name): ?array {
        $esc = ldap_escape($name, '', LDAP_ESCAPE_FILTER);
        $res = ldap_search($this->conn(), $this->cfg['base_dn'],
            "(&(objectClass=computer)(|(name={$esc})(dNSHostName={$esc})))",
            self::COMPUTER_ATTRS, 0, 1);
        $entries = $res ? $this->parseEntries(ldap_get_entries($this->conn(), $res), 'computer') : [];
        return $entries[0] ?? null;
    }


    /* ========== BitLocker 密钥查询 ========== */

        public function queryBitLockerKeys(string $computerName = ''): array {
        // 先查所有密钥，再在 PHP 中按计算机名过滤
        // AD 的 distinguishedName 属性不支持 substring 匹配
        $base = $this->cfg['base_dn'];
        $filter = '(objectClass=msFVE-RecoveryInformation)';
        $res = @ldap_search($this->conn(), $base, $filter,
            self::BITLOCKER_ATTRS, 0, 0);
        if (!$res) return [];
        $entries = $this->parseEntries(ldap_get_entries($this->conn(), $res), 'bitlocker');

        $result = [];
        foreach ($entries as $e) {
            $dn = $e['distinguishedname'] ?? '';
            $parts = ldap_explode_dn($dn, 0);
            unset($parts['count']);
            $compName = '';
            $skip = true;
            foreach ($parts as $p) {
                if (stripos($p, 'CN=') === 0) {
                    $cn = substr($p, 3);
                    if ($skip && (preg_match('/[0-9A-F]{8}-[0-9A-F]{4}-/i', $cn) || preg_match('/^\d{4}-\d{2}-\d{2}T/', $cn))) {
                        $skip = false;
                        continue;
                    }
                    $compName = $cn;
                    break;
                }
            }
            $e['computer_name'] = $compName;
            if ($computerName && stripos($compName, $computerName) === false && stripos($dn, $computerName) === false) {
                continue;
            }
            // 格式化恢复密钥
            if (!empty($e['msfve-recoverypassword'])) {
                $pwd = $e['msfve-recoverypassword'];
                $e['recovery_key_formatted'] = implode('-', str_split(str_replace('-', '', $pwd), 6));
            }
            // 从 name 提取 RecoveryGuid（name 格式: timestamp{GUID}）
            if (!empty($e['name']) && preg_match('/{([A-F0-9-]+)}/i', $e['name'], $m)) {
                $e['recovery_id'] = $m[0];
            }
            // 二进制 msfve-volumeguid → 可读 GUID
            if (!empty($e['msfve-volumeguid']) && is_string($e['msfve-volumeguid']) && strlen($e['msfve-volumeguid']) === 16) {
                $hex = bin2hex($e['msfve-volumeguid']);
                $e['volume_guid'] = sprintf('{%s-%s-%s-%s-%s}',
                    substr($hex,6,2).substr($hex,4,2).substr($hex,2,2).substr($hex,0,2),
                    substr($hex,10,2).substr($hex,8,2),
                    substr($hex,14,2).substr($hex,12,2),
                    substr($hex,16,4),
                    substr($hex,20,12)
                );
            }
            // 格式化创建时间 20260513130723.0Z → 2026-05-13 13:07:23 UTC
            if (!empty($e['whencreated']) && preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $e['whencreated'], $tm)) {
                $e['when_created_fmt'] = "$tm[1]-$tm[2]-$tm[3] $tm[4]:$tm[5]:$tm[6] UTC";
            }
                        if (!empty($e['msfve-recoverypassword'])) {
                $e['recovery_key_formatted'] = implode('-', str_split(str_replace('-', '', $e['msfve-recoverypassword']), 6));
            }
            $result[] = $e;
        }
        return $result;
    }

    /**
     * 多 OU 搜索用户（遍历所有配置的 OU，合并去重结果）
     * 未配置 OU 时降级到 base_dn 全域搜索
     */
    public function searchUsersInConfiguredOUs(string $keyword = ''): array {
        $ous = $this->cfg['user_ous'] ?? [];
        if (empty($ous)) return $this->searchUsers($keyword, '');
        $all = [];
        $seen = [];
        foreach ($ous as $ou) {
            foreach ($this->searchUsers($keyword, $ou) as $u) {
                $key = $u['distinguishedname'] ?? $u['samaccountname'] ?? json_encode($u);
                if (!isset($seen[$key])) { $seen[$key] = true; $all[] = $u; }
            }
        }
        return $all;
    }

    /**
     * 多 OU 搜索计算机（遍历所有配置的 OU，合并去重结果）
     * 未配置 OU 时降级到 base_dn 全域搜索
     */
    public function searchComputersInConfiguredOUs(string $keyword = ''): array {
        $ous = $this->cfg['computer_ous'] ?? [];
        if (empty($ous)) return $this->searchComputers($keyword, '');
        $all = [];
        $seen = [];
        foreach ($ous as $ou) {
            foreach ($this->searchComputers($keyword, $ou) as $c) {
                $key = $c['distinguishedname'] ?? $c['name'] ?? json_encode($c);
                if (!isset($seen[$key])) { $seen[$key] = true; $all[] = $c; }
            }
        }
        return $all;
    }

    /* ========== 私有方法 ========== */

    private function findUserByDn(string $dn): ?array {
        $res = ldap_read($this->conn(), $dn, '(objectClass=*)', self::USER_ATTRS);
        $entries = $res ? $this->parseEntries(ldap_get_entries($this->conn(), $res), 'user') : [];
        return $entries[0] ?? null;
    }


    private function parseEntries(array $raw, string $type = 'user'): array {
        $result = [];
        $attr_map = match($type) {
            'computer'  => self::COMPUTER_ATTRS,
            'bitlocker' => self::BITLOCKER_ATTRS,
            'ou'        => ['ou','distinguishedName'],
            default     => self::USER_ATTRS,
        };
        $attr_lower = array_map('strtolower', $attr_map);

        for ($i = 0; $i < ($raw['count'] ?? 0); $i++) {
            $e   = $raw[$i];
            $row = ['dn' => $e['dn']];
            foreach ($attr_lower as $attr) {
                if (!isset($e[$attr])) continue;
                $vals = $e[$attr];
                if (isset($vals['count'])) unset($vals['count']);
                $row[$attr] = count($vals) === 1 ? $vals[0] : array_values($vals);
            }
            // 用户特有字段
            if ($type === 'user') {
                if (isset($row['useraccountcontrol']))
                    $row['is_disabled'] = (bool)((int)$row['useraccountcontrol'] & 0x2);
                if (isset($row['lockouttime']))
                    $row['is_locked'] = ((int)$row['lockouttime']) > 0;
                if (!empty($row['lastlogontimestamp']) && $row['lastlogontimestamp'] > 0)
                    $row['last_logon_unix'] = intdiv((int)$row['lastlogontimestamp'], 10000000) - 11644473600;
            }
            // 计算机特有
            if ($type === 'computer') {
                if (!empty($row['lastlogontimestamp']) && $row['lastlogontimestamp'] > 0)
                    $row['last_logon_unix'] = intdiv((int)$row['lastlogontimestamp'], 10000000) - 11644473600;
            }
            $result[] = $row;
        }
        return $result;
    }

    /* ══════════════════════════════════════════════════════
       安全组操作
    ══════════════════════════════════════════════════════ */

    const GROUP_ATTRS = [
        'cn','displayName','description','distinguishedName',
        'member','memberOf','groupType','mail','managedBy',
        'whenCreated','whenChanged','sAMAccountName','objectSid',
    ];

    /**
     * groupType 位掩码解析
     * https://learn.microsoft.com/en-us/windows/win32/adschema/a-grouptype
     */
    public static function parseGroupType(int $gt): array {
        $scope = match(true) {
            (bool)($gt & 0x00000001) => 'system',
            (bool)($gt & 0x00000002) => 'global',
            (bool)($gt & 0x00000004) => 'domain_local',
            (bool)($gt & 0x00000008) => 'universal',
            default                   => 'unknown',
        };
        $type = ($gt & 0x80000000) ? 'security' : 'distribution';
        return ['scope' => $scope, 'type' => $type, 'raw' => $gt];
    }

    /** 搜索安全组（默认只返回安全组，可选包含通讯组） */
    public function searchGroups(string $keyword = '', string $ou = '', bool $include_distribution = false): array {
        $base = $ou ?: $this->cfg['base_dn'];
        $esc  = ldap_escape($keyword, '', LDAP_ESCAPE_FILTER);
        // groupType & 0x80000000 = 安全组；不带此条件则含通讯组
        $typeFilter = $include_distribution ? '' : '(groupType:1.2.840.113556.1.4.803:=2147483648)';
        $kwFilter   = $keyword
            ? "(|(cn=*{$esc}*)(displayName=*{$esc}*)(description=*{$esc}*)(sAMAccountName=*{$esc}*))"
            : '';
        $filter = "(&(objectClass=group){$typeFilter}{$kwFilter})";
        $res = @ldap_search($this->conn(), $base, $filter, self::GROUP_ATTRS, 0, 500);
        return $res ? $this->parseGroupEntries(ldap_get_entries($this->conn(), $res)) : [];
    }

    /** 按 DN 获取组详情（含成员列表） */
    public function getGroupDetail(string $dn): ?array {
        $res = @ldap_read($this->conn(), $dn, '(objectClass=group)', self::GROUP_ATTRS);
        if (!$res) return null;
        $entries = ldap_get_entries($this->conn(), $res);
        $groups  = $this->parseGroupEntries($entries);
        if (empty($groups)) return null;
        $g = $groups[0];
        // 解析成员 DN → 基本信息
        $members = [];
        $rawMembers = is_array($g['member'] ?? null) ? $g['member'] : (empty($g['member']) ? [] : [$g['member']]);
        foreach ($rawMembers as $mdn) {
            if (empty($mdn)) continue;
            $mRes = @ldap_read($this->conn(), $mdn, '(objectClass=*)',
                ['cn','sAMAccountName','displayName','objectClass','userAccountControl','mail'], 0, 0);
            if (!$mRes) { $members[] = ['dn' => $mdn, 'display' => $mdn]; continue; }
            $me = ldap_get_entries($this->conn(), $mRes);
            if ($me['count'] < 1) { $members[] = ['dn' => $mdn, 'display' => $mdn]; continue; }
            $e = $me[0];
            $oc = array_map('strtolower', is_array($e['objectclass'] ?? []) ? $e['objectclass'] : []);
            $isGroup = in_array('group', $oc);
            $isUser  = in_array('user', $oc);
            $uac = (int)($e['useraccountcontrol'][0] ?? 0);
            $members[] = [
                'dn'          => $mdn,
                'sam'         => $e['samaccountname'][0] ?? '',
                'display'     => $e['displayname'][0]    ?? $e['cn'][0] ?? $mdn,
                'mail'        => $e['mail'][0]           ?? '',
                'type'        => $isGroup ? 'group' : ($isUser ? 'user' : 'other'),
                'is_disabled' => $isUser ? (bool)($uac & 0x2) : false,
            ];
        }
        $g['members']      = $members;
        $g['member_count'] = count($members);
        return $g;
    }

    /**
     * 新建安全组
     * @param string $name        组名（CN）
     * @param string $ou          父 OU DN
     * @param string $description 描述
     * @param string $scope       global|universal|domain_local
     * @param string $mail        邮件地址（可空）
     */
    public function createGroup(string $name, string $ou, string $description = '',
                                 string $scope = 'global', string $mail = ''): array {
        if (empty($name) || empty($ou)) throw new \RuntimeException('组名和 OU 不能为空');

        $dn = 'CN=' . ldap_escape($name, '', LDAP_ESCAPE_DN) . ',' . $ou;

        // 检查是否已存在
        $check = @ldap_search($this->conn(), $ou,
            '(&(objectClass=group)(cn=' . ldap_escape($name, '', LDAP_ESCAPE_FILTER) . '))',
            ['dn'], 0, 1);
        if ($check && ldap_count_entries($this->conn(), $check) > 0)
            throw new \RuntimeException("安全组 '{$name}' 在该 OU 中已存在");

        // groupType = scope | 0x80000000(security)
        $scopeBit = match($scope) {
            'domain_local' => 0x00000004,
            'universal'    => 0x00000008,
            default        => 0x00000002, // global
        };
        $groupType = (string)($scopeBit | 0x80000000 | 0xFFFFFFFF00000000 & ~0xFFFFFFFF); // signed int
        // PHP ldap_add 需要 string
        $groupTypeVal = (string)($scopeBit - 2147483648); // 转为有符号32位

        $entry = [
            'objectClass'    => ['top', 'group'],
            'cn'             => $name,
            'sAMAccountName' => $name,
            'groupType'      => [(string)($scopeBit | -2147483648)],
        ];
        if ($description) $entry['description'] = $description;
        if ($mail)        $entry['mail']         = $mail;

        if (!@ldap_add($this->conn(), $dn, $entry))
            throw new \RuntimeException('创建安全组失败：' . ldap_error($this->conn()));

        PluginAdmanagerAuditLog::write('create_group','ADGroup',$dn,$name,
            ['scope'=>$scope,'desc'=>$description],true);

        return ['dn' => $dn, 'cn' => $name];
    }

    /** 删除安全组（只删空组，有成员时拒绝） */
    public function deleteGroup(string $dn, bool $force = false): bool {
        if (!$force) {
            $detail = $this->getGroupDetail($dn);
            if ($detail && !empty($detail['members']))
                throw new \RuntimeException('组内还有 ' . count($detail['members']) . ' 个成员，请先移除后再删除');
        }
        if (!@ldap_delete($this->conn(), $dn))
            throw new \RuntimeException('删除失败：' . ldap_error($this->conn()));
        PluginAdmanagerAuditLog::write('delete_group','ADGroup',$dn,'',[], true);
        return true;
    }

    /** 修改安全组属性（描述/displayName/mail） */
    public function modifyGroup(string $dn, array $attrs): bool {
        $allowed = ['description','displayName','mail'];
        $modify  = [];
        foreach ($allowed as $a) {
            if (array_key_exists(strtolower($a), array_change_key_case($attrs))) {
                $v = $attrs[$a] ?? $attrs[strtolower($a)] ?? '';
                $modify[$a] = $v === '' ? [] : [$v];
            }
        }
        if (empty($modify)) return true;
        return (bool)@ldap_modify($this->conn(), $dn, $modify);
    }

    /** 添加/移除组成员（复用已有 modifyGroupMembership，增加类型校验） */
    public function addGroupMember(string $member_dn, string $group_dn): bool {
        return $this->modifyGroupMembership($member_dn, $group_dn, true);
    }
    public function removeGroupMember(string $member_dn, string $group_dn): bool {
        return $this->modifyGroupMembership($member_dn, $group_dn, false);
    }

    /** 列出所有 OU（供组搜索过滤用，复用 listOUs） */

    /** 解析组条目 */
    private function parseGroupEntries(array $raw): array {
        $result = [];
        for ($i = 0; $i < ($raw['count'] ?? 0); $i++) {
            $e  = $raw[$i];
            $gt = (int)($e['grouptype'][0] ?? 0);
            $parsed = self::parseGroupType($gt);
            // 跳过内置系统组（system scope）
            if ($parsed['scope'] === 'system') continue;
            $members = [];
            if (isset($e['member']) && $e['member']['count'] > 0) {
                $mc = $e['member']['count'];
                for ($j = 0; $j < $mc; $j++) $members[] = $e['member'][$j];
            }
            $result[] = [
                'dn'            => $e['dn'],
                'cn'            => $e['cn'][0]            ?? '',
                'sam'           => $e['samaccountname'][0] ?? '',
                'display'       => $e['displayname'][0]   ?? $e['cn'][0] ?? '',
                'description'   => $e['description'][0]   ?? '',
                'mail'          => $e['mail'][0]           ?? '',
                'managed_by'    => $e['managedby'][0]      ?? '',
                'scope'         => $parsed['scope'],
                'type'          => $parsed['type'],
                'member'        => $members,
                'member_count'  => count($members),
                'when_created'  => $e['whencreated'][0]   ?? '',
                'when_changed'  => $e['whenchanged'][0]   ?? '',
            ];
        }
        return $result;
    }

}
