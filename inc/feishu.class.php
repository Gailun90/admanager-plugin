<?php
/**
 * inc/feishu.class.php — 飞书适配器
 * API 文档：https://open.feishu.cn/document/server-docs/api-call-guide/calling-process/get-access-token
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerFeishu extends PluginAdmanagerIMConnector
{
    private const BASE = 'https://open.feishu.cn/open-apis';
    private ?string $token = null;
    private int $tokenExp  = 0;

    public function platform(): string { return 'feishu'; }

    private function token(): string {
        if ($this->token && time() < $this->tokenExp) return $this->token;
        $r = $this->post(self::BASE . '/auth/v3/tenant_access_token/internal', [
            'app_id'     => $this->cfg['appid'],
            'app_secret' => $this->cfg['appsecret'],
        ]);
        if (($r['code'] ?? -1) !== 0)
            throw new \RuntimeException('飞书获取 token 失败：' . ($r['msg'] ?? json_encode($r)));
        $this->token    = $r['tenant_access_token'];
        $this->tokenExp = time() + ($r['expire'] ?? 7200) - 60;
        return $this->token;
    }

    private function bearer(): array {
        return ['Authorization: Bearer ' . $this->token()];
    }

    public function connect(): bool { $this->token(); return true; }

    public function getDepartments(): array {
        $depts = [];
        $token = null;
        do {
            $params = ['fetch_child' => 'true', 'department_id_type' => 'open_department_id'];
            if ($token) $params['page_token'] = $token;
            $r = $this->get(self::BASE . '/contact/v3/departments', $params, $this->bearer());
            if (($r['code'] ?? -1) !== 0)
                throw new \RuntimeException('获取飞书部门失败：' . ($r['msg'] ?? ''));
            foreach (($r['data']['items'] ?? []) as $d) {
                $depts[] = [
                    'id'        => $d['open_department_id'] ?? $d['department_id'],
                    'name'      => $d['name'],
                    'parent_id' => $d['parent_open_department_id'] ?? $d['parent_department_id'] ?? '0',
                    'order'     => $d['order'] ?? 0,
                ];
            }
            $token = $r['data']['page_token'] ?? null;
        } while ($r['data']['has_more'] ?? false);
        return $depts;
    }

    public function getDeptUsers(string $dept_id, bool $recursive = true): array {
        // 拉取当前部门的直属成员（翻页完整获取）
        $users = [];
        $token = null;
        do {
            $params = [
                'department_id'      => $dept_id,
                'department_id_type' => 'open_department_id',
                'user_id_type'       => 'open_id',
                'page_size'          => 50,
            ];
            if ($token) $params['page_token'] = $token;
            $r = $this->get(self::BASE . '/contact/v3/users', $params, $this->bearer());
            if (($r['code'] ?? -1) !== 0) break;
            foreach (($r['data']['items'] ?? []) as $u) {
                $users[] = $this->normalizeUser($u);
            }
            $token = $r['data']['page_token'] ?? null;
        } while ($r['data']['has_more'] ?? false);

        // 递归获取子部门成员
        if ($recursive) {
            $subDepts = $this->getSubDepts($dept_id);
            foreach ($subDepts as $sub) {
                $subUsers = $this->getDeptUsers($sub['open_department_id'], true);
                foreach ($subUsers as $u) {
                    $users[$u['userid']] = $u;   // userid 去重
                }
            }
        }
        return array_values($users);
    }

    /** 获取指定部门的直属子部门列表 */
    private function getSubDepts(string $dept_id): array {
        $r = $this->get(self::BASE . '/contact/v3/departments', [
            'parent_department_id'   => $dept_id,
            'department_id_type'     => 'open_department_id',
            'user_id_type'           => 'open_id',
            'page_size'              => 50,
        ], $this->bearer());
        return $r['data']['items'] ?? [];
    }

    public function getAllUsers(int $page = 1, int $size = 100): array {
        // 从根部门（'0'）递归拉取所有成员，$page/$size 保留兼容
        $users = $this->getDeptUsers('0', true);
        // 按 userid 去重（getDeptUsers 内部已去重，这里二次保险）
        $deduped = [];
        foreach ($users as $u) { $deduped[$u['userid']] = $u; }
        return array_values($deduped);
    }

    public function getUser(string $userid): ?array {
        $r = $this->get(
            self::BASE . '/contact/v3/users/' . urlencode($userid),
            ['user_id_type' => 'open_id'],
            $this->bearer()
        );
        if (($r['code'] ?? -1) !== 0) return null;
        return $this->normalizeUser($r['data']['user'] ?? []);
    }

    public function createUser(array $data): string {
        $payload = [
            'name'          => $data['name'],
            'mobile'        => $data['mobile']   ?? '',
            'email'         => $data['email']     ?? '',
            'department_ids'=> (array)($data['dept_ids'] ?? ['0']),
            'job_title'     => $data['position']  ?? '',
            'employee_no'   => $data['jobnumber'] ?? '',
            'user_id'       => $data['userid']    ?? '',
        ];
        $r = $this->post(
            self::BASE . '/contact/v3/users?user_id_type=open_id',
            $payload,
            $this->bearer()
        );
        if (($r['code'] ?? -1) !== 0)
            throw new \RuntimeException('创建飞书用户失败：' . ($r['msg'] ?? json_encode($r)));
        return $r['data']['user']['open_id'] ?? $r['data']['user']['user_id'];
    }

    public function setUserEnabled(string $userid, bool $enabled): bool {
        $res = $this->patch(
            self::BASE . '/contact/v3/users/' . urlencode($userid) . '?user_id_type=open_id',
            ['status' => ['is_frozen' => !$enabled]],
            $this->bearer()
        );
        return ($res['code'] ?? -1) === 0;
    }

    public function sendMessage(string $userid, string $content): bool {
        $r = $this->post(
            self::BASE . '/im/v1/messages?receive_id_type=open_id',
            [
                'receive_id' => $userid,
                'msg_type'   => 'text',
                'content'    => json_encode(['text' => $content]),
            ],
            $this->bearer()
        );
        return ($r['code'] ?? -1) === 0;
    }

    private function normalizeUser(array $u): array {
        $depts = $u['department_ids'] ?? [];
        return [
            'userid'   => $u['open_id']    ?? $u['user_id'] ?? '',
            'name'     => $u['name']        ?? '',
            'mobile'   => $u['mobile']      ?? '',
            'email'    => $u['email']       ?? $u['enterprise_email'] ?? '',
            'dept_ids' => array_map('strval', (array)$depts),
            'position' => $u['job_title']   ?? '',
            'enabled'  => !($u['status']['is_frozen'] ?? false),
            'avatar'   => $u['avatar']['avatar_72'] ?? '',
            'platform' => 'feishu',
        ];
    }
}
