<?php
/**
 * inc/imconnector.class.php — IM 平台适配器抽象接口
 * 企业微信 / 钉钉 / 飞书 均实现此接口
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

abstract class PluginAdmanagerIMConnector
{
    protected array $cfg = [];

    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }

    /** 平台标识：wecom | dingtalk | feishu */
    abstract public function platform(): string;

    /** 测试连接/获取 access_token */
    abstract public function connect(): bool;

    /** 获取全量部门列表 [['id','name','parent_id']] */
    abstract public function getDepartments(): array;

    /** 获取部门下用户列表 [['userid','name','mobile','email','dept_ids']] */
    abstract public function getDeptUsers(string $dept_id, bool $recursive = true): array;

    /** 获取全量用户（分页） */
    abstract public function getAllUsers(int $page = 1, int $size = 100): array;

    /** 创建用户 → 返回平台 userid */
    abstract public function createUser(array $data): string;

    /** 禁用/启用用户 */
    abstract public function setUserEnabled(string $userid, bool $enabled): bool;

    /** 发送消息给用户（文本消息） */
    abstract public function sendMessage(string $userid, string $content): bool;

    /** 获取单个用户详情 */
    abstract public function getUser(string $userid): ?array;

    // ── 通用 HTTP helper ────────────────────────────────────────────
    protected function http(string $method, string $url, array $data = [],
                             array $headers = [], int $timeout = 15): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        ]);
        if ($data && in_array(strtoupper($method), ['POST','PUT','PATCH']))
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) throw new \RuntimeException("[{$this->platform()}] cURL: {$err}");
        $res = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE)
            throw new \RuntimeException("[{$this->platform()}] 非 JSON 响应 HTTP {$code}: ".substr($raw,0,200));
        return $res;
    }

    protected function get(string $url, array $query = [], array $headers = []): array {
        if ($query) $url .= '?' . http_build_query($query);
        return $this->http('GET', $url, [], $headers);
    }

    protected function post(string $url, array $data = [], array $headers = []): array {
        return $this->http('POST', $url, $data, $headers);
    }

    protected function patch(string $url, array $data = [], array $headers = []): array {
        return $this->http('PATCH', $url, $data, $headers);
    }
}
