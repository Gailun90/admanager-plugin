<?php
/**
 * inc/dingtalk.class.php — 钉钉适配器
 * API 文档：https://open.dingtalk.com/document/orgapp/obtain-orgapp-token
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerDingtalk extends PluginAdmanagerIMConnector
{
    private const BASE_OLD = 'https://oapi.dingtalk.com';   // 旧版 API
    private const BASE_NEW = 'https://api.dingtalk.com/v1.0'; // 新版 API
    private ?string $tokenOld = null;
    private ?string $tokenNew = null;
    private int $tokenOldExp = 0;
    private int $tokenNewExp = 0;

    public function platform(): string { return 'dingtalk'; }

    /** 旧版 access_token（部门/用户接口仍大量使用旧版） */
    private function tokenOld(): string {
        if ($this->tokenOld && time() < $this->tokenOldExp) return $this->tokenOld;
        $r = $this->get(self::BASE_OLD . '/gettoken', [
            'appkey'    => $this->cfg['appkey'],
            'appsecret' => $this->cfg['appsecret'],
        ]);
        if (($r['errcode'] ?? -1) !== 0)
            throw new \RuntimeException('钉钉获取 token 失败：' . ($r['errmsg'] ?? json_encode($r)));
        $this->tokenOld = $r['access_token'];
        $this->tokenOldExp = time() + ($r['expires_in'] ?? 7200) - 60;
        return $this->tokenOld;
    }

    /** 新版 Bearer token */
    private function tokenNew(): string {
        if ($this->tokenNew && time() < $this->tokenNewExp) return $this->tokenNew;
        $r = $this->post(self::BASE_NEW . '/oauth2/accessToken', [
            'appKey'    => $this->cfg['appkey'],
            'appSecret' => $this->cfg['appsecret'],
        ]);
        if (!isset($r['accessToken']))
            throw new \RuntimeException('钉钉新版 token 失败：' . json_encode($r));
        $this->tokenNew = $r['accessToken'];
        $this->tokenNewExp = time() + ($r['expireIn'] ?? 7200) - 60;
        return $this->tokenNew;
    }

    private function authHeader(): array {
        return ['x-acs-dingtalk-access-token: ' . $this->tokenNew()];
    }

    public function connect(): bool {
        $this->tokenOld();
        // 用获取根部门用户来验证连接和权限
        $r = $this->get(self::BASE_OLD . '/user/list', [
            'access_token'  => $this->tokenOld(),
            'department_id' => 1,
            'offset'        => 0,
            'size'          => 1,
            'order'         => 'custom',
        ]);
        if (($r['errcode'] ?? -1) !== 0)
            throw new \RuntimeException('钉钉连接验证失败：' . ($r['errmsg'] ?? json_encode($r)));
        $this->_userCount = count($r['userlist'] ?? []);
        $this->_hasMore   = $r['hasMore'] ?? false;
        return true;
    }

    public int $_userCount = 0;
    public bool $_hasMore  = false;

    public function getDepartments(): array {
        $r = $this->get(self::BASE_OLD . '/department/list', [
            'access_token' => $this->tokenOld(),
            'fetch_child'  => 'true',
            'id'           => 1,
        ]);
        if (($r['errcode'] ?? -1) !== 0)
            throw new \RuntimeException('获取钉钉部门失败：' . $r['errmsg']);
        return array_map(fn($d) => [
            'id'        => (string)$d['id'],
            'name'      => $d['name'],
            'parent_id' => (string)($d['parentid'] ?? '0'),
            'order'     => $d['order'] ?? 0,
        ], $r['department'] ?? []);
    }

    public function getDeptUsers(string $dept_id, bool $recursive = true): array {
        $users  = [];
        $offset = 0;
        $size   = 100;
        do {
            $r = $this->get(self::BASE_OLD . '/user/list', [
                'access_token'  => $this->tokenOld(),
                'department_id' => $dept_id,
                'offset'        => $offset,
                'size'          => $size,
                'order'         => 'custom',
            ]);
            if (($r['errcode'] ?? -1) !== 0) break;
            $batch  = $r['userlist'] ?? [];
            $users  = array_merge($users, $this->normalizeUsers($batch));
            $offset += count($batch);
        } while ($r['hasMore'] ?? false);
        return $users;
    }

    public function getAllUsers(int $page = 1, int $size = 100): array {
        // 遍历所有部门，按 userid 去重，返回全量用户（$page/$size 参数保留兼容，内部完整拉取）
        try {
            $depts = $this->getDepartments();
        } catch (\Throwable $e) {
            $depts = [['id' => '1']];
        }
        $all = [];
        foreach ($depts as $dept) {
            foreach ($this->getDeptUsers($dept['id'], false) as $u) {
                $all[$u['userid']] = $u;   // userid 去重
            }
        }
        return array_values($all);
    }

    public function getUser(string $userid): ?array {
        $r = $this->get(self::BASE_OLD . '/user/get', [
            'access_token' => $this->tokenOld(),
            'userid'       => $userid,
        ]);
        if (($r['errcode'] ?? -1) !== 0) return null;
        return $this->normalizeUser($r);
    }

    public function createUser(array $data): string {
        $payload = [
            'name'           => $data['name'],
            'mobile'         => $data['mobile']   ?? '',
            'department'     => array_map('intval', (array)($data['dept_ids'] ?? [1])),
            'email'          => $data['email']     ?? '',
            'jobnumber'      => $data['jobnumber'] ?? '',
            'position'       => $data['position']  ?? '',
            'userid'         => $data['userid']    ?? '',
        ];
        $r = $this->post(
            self::BASE_OLD . '/user/create?access_token=' . $this->tokenOld(),
            $payload
        );
        if (($r['errcode'] ?? -1) !== 0)
            throw new \RuntimeException('创建钉钉用户失败：' . ($r['errmsg'] ?? json_encode($r)));
        return $r['userid'];
    }

    public function setUserEnabled(string $userid, bool $enabled): bool {
        // 钉钉没有直接禁用接口，通过离职/复职实现
        // 实际通过 HR 流程，这里标记状态
        // 使用新版接口更新用户状态
        try {
            $r = $this->post(
                self::BASE_NEW . '/contact/users/' . urlencode($userid),
                ['active' => $enabled],
                $this->authHeader()
            );
            return isset($r['userId']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function sendMessage(string $userid, string $content): bool {
        $r = $this->post(
            self::BASE_OLD . '/message/send_to_conversation?access_token=' . $this->tokenOld(),
            [
                'sender'       => $this->cfg['admin_userid'] ?? '',
                'cid'          => '',
                'msg'          => ['msgtype' => 'text', 'text' => ['content' => $content]],
            ]
        );
        // 改用工作通知
        $r = $this->post(
            self::BASE_OLD . '/topapi/message/corpconversation/asyncsend_v2?access_token=' . $this->tokenOld(),
            [
                'agent_id'    => (int)($this->cfg['agentid'] ?? 0),
                'userid_list' => $userid,
                'msg'         => ['msgtype' => 'text', 'text' => ['content' => $content]],
            ]
        );
        return ($r['errcode'] ?? -1) === 0;
    }

    private function normalizeUsers(array $list): array {
        return array_map(fn($u) => $this->normalizeUser($u), $list);
    }

    private function normalizeUser(array $u): array {
        // 兼容旧版 /user/list、topapi/v2/user/list、新版 API 字段
        $depts = $u['dept_id_list'] ?? $u['deptIdList'] ?? $u['department'] ?? [];
        $mobile = $u['mobile'] ?? $u['telephone'] ?? '';
        $email  = $u['email']  ?? $u['org_email'] ?? $u['orgEmail'] ?? '';
        // topapi v2 的 active 字段
        $enabled = true;
        if (isset($u['active']))          $enabled = (bool)$u['active'];
        elseif (isset($u['job_number']))  $enabled = true;  // 有工号一般是在职
        return [
            'userid'     => $u['userid']      ?? $u['userId']    ?? '',
            'name'       => $u['name']         ?? '',
            'mobile'     => $mobile,
            'email'      => $email,
            'dept_ids'   => array_map('strval', (array)$depts),
            'position'   => $u['title']        ?? $u['position']  ?? '',
            'job_number' => $u['job_number']   ?? $u['jobnumber'] ?? '',
            'enabled'    => $enabled,
            'avatar'     => $u['avatar']       ?? '',
            'platform'   => 'dingtalk',
        ];
    }
}
