<?php
/**
 * inc/wecom.class.php — 企业微信适配器
 * API 文档：https://developer.work.weixin.qq.com/document/path/90664
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerWecom extends PluginAdmanagerIMConnector
{
    private const BASE = 'https://qyapi.weixin.qq.com/cgi-bin';
    private ?string $token = null;
    private int $tokenExp  = 0;

    public function platform(): string { return 'wecom'; }

    private function token(): string {
        if ($this->token && time() < $this->tokenExp) return $this->token;
        $r = $this->get(self::BASE . '/gettoken', [
            'corpid'     => $this->cfg['corpid'],
            'corpsecret' => $this->cfg['corpsecret'],
        ]);
        if (($r['errcode'] ?? -1) !== 0)
            throw new \RuntimeException('企业微信获取 token 失败：' . ($r['errmsg'] ?? json_encode($r)));
        $this->token    = $r['access_token'];
        $this->tokenExp = time() + ($r['expires_in'] ?? 7200) - 60;
        return $this->token;
    }

    private function q(array $p = []): array { return array_merge(['access_token' => $this->token()], $p); }

    public function connect(): bool { $this->token(); return true; }

    public function getDepartments(): array {
        $r = $this->get(self::BASE . '/department/list', $this->q(['id' => 1]));
        if (($r['errcode'] ?? -1) !== 0) throw new \RuntimeException('获取部门失败：' . $r['errmsg']);
        return array_map(fn($d) => [
            'id'        => (string)$d['id'],
            'name'      => $d['name'],
            'parent_id' => (string)($d['parentid'] ?? '0'),
            'order'     => $d['order'] ?? 0,
        ], $r['department'] ?? []);
    }

    public function getDeptUsers(string $dept_id, bool $recursive = true): array {
        $r = $this->get(self::BASE . '/user/list', $this->q([
            'department_id' => $dept_id,
            'fetch_child'   => $recursive ? 1 : 0,
        ]));
        if (($r['errcode'] ?? -1) !== 0) throw new \RuntimeException('获取用户列表失败：' . $r['errmsg']);
        return $this->normalizeUsers($r['userlist'] ?? []);
    }

    public function getAllUsers(int $page = 1, int $size = 100): array {
        // 企微没有全量分页接口，从根部门递归拉
        return $this->getDeptUsers('1', true);
    }

    public function getUser(string $userid): ?array {
        $r = $this->get(self::BASE . '/user/get', $this->q(['userid' => $userid]));
        if (($r['errcode'] ?? -1) !== 0) return null;
        return $this->normalizeUser($r);
    }

    public function createUser(array $data): string {
        // $data: name,userid(可选),mobile,email,dept_ids,position,gender
        $payload = [
            'userid'       => $data['userid']   ?? $data['mobile'] ?? '',
            'name'         => $data['name'],
            'mobile'       => $data['mobile']   ?? '',
            'email'        => $data['email']     ?? '',
            'department'   => array_map('intval', (array)($data['dept_ids'] ?? [1])),
            'position'     => $data['position']  ?? '',
            'gender'       => $data['gender']    ?? '0',
            'enable'       => 1,
        ];
        $r = $this->post(self::BASE . '/user/create?access_token=' . $this->token(), $payload);
        if (($r['errcode'] ?? -1) !== 0)
            throw new \RuntimeException('创建企微用户失败：' . ($r['errmsg'] ?? json_encode($r)));
        return $payload['userid'];
    }

    public function setUserEnabled(string $userid, bool $enabled): bool {
        // 企微没有直接禁用接口，通过 enable 字段
        $r = $this->post(self::BASE . '/user/update?access_token=' . $this->token(), [
            'userid' => $userid,
            'enable' => $enabled ? 1 : 0,
        ]);
        return ($r['errcode'] ?? -1) === 0;
    }

    public function sendMessage(string $userid, string $content): bool {
        $r = $this->post(self::BASE . '/message/send?access_token=' . $this->token(), [
            'touser'  => $userid,
            'msgtype' => 'text',
            'agentid' => (int)($this->cfg['agentid'] ?? 0),
            'text'    => ['content' => $content],
        ]);
        return ($r['errcode'] ?? -1) === 0;
    }

    private function normalizeUsers(array $list): array {
        return array_map(fn($u) => $this->normalizeUser($u), $list);
    }
    private function normalizeUser(array $u): array {
        return [
            'userid'   => $u['userid']     ?? '',
            'name'     => $u['name']       ?? '',
            'mobile'   => $u['mobile']     ?? '',
            'email'    => $u['email']       ?? $u['biz_mail'] ?? '',
            'dept_ids' => array_map('strval', $u['department'] ?? []),
            'position' => $u['position']   ?? '',
            'enabled'  => ($u['enable']    ?? 1) == 1,
            'avatar'   => $u['avatar']     ?? '',
            'platform' => 'wecom',
        ];
    }
}
