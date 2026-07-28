<?php
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerFastApiClient
{
    private static ?self $instance = null;
    private array $cfg = [];

    private function __construct() {
        $cfg = PluginAdmanagerConfig::getFastApiConfig();
        // fastapi_token 在 GLPI 中以 sodium 加密存储，需解密后使用
        if (!empty($cfg['token'])) {
            $key = new GLPIKey();
            $decrypted = $key->decrypt($cfg['token']);
            $cfg['token'] = !empty($decrypted) ? $decrypted : $cfg['token'];
        }
        $this->cfg = $cfg;
    }

    public static function getInstance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public function get(string $path, array $query = []): array {
        return $this->request('GET', $path, $query);
    }
    /** POST 请求走 query params（而非 JSON body） */
    public function postQuery(string $path, array $query = []): array {
        return $this->request('POST', $path, $query, []);
    }


    public function post(string $path, array $body = []): array {
        return $this->request('POST', $path, [], $body);
    }

    /** PUT 请求（JSON body），漏洞修复规则库更新用 */
    public function put(string $path, array $body = []): array {
        return $this->request('PUT', $path, [], $body);
    }

    private function request(string $method, string $path, array $query = [], array $body = []): array {
        $url = $this->cfg['url'] . $path;
        if ($query) $url .= '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->cfg['timeout'],
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->cfg['token'],
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("cURL 错误：{$err}");
        if ($code >= 400) throw new \RuntimeException("FastAPI 返回 HTTP {$code}：{$raw}");

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE)
            throw new \RuntimeException('FastAPI 返回非 JSON 响应');
        return $data;
    }

    // ── 封装常用接口 ──────────────────────────────────────────────────────────


    /** 删除指定客户端（及关联数据） */
    public function deleteClient(int $client_id): array {
        return $this->delete("/api/clients/{$client_id}");
    }

    /** 获取终端列表（用于手动导入选择） */

    public function patch(string $path, array $query = []): array {
        return $this->request('PATCH', $path, $query);
    }

    public function delete(string $path, array $query = []): array {
        return $this->request('DELETE', $path, $query);
    }

    public function getClients(int $page = 1, int $limit = 50): array {
        return $this->get('/api/export/clients', ['page' => $page, 'limit' => $limit]);
    }

    /** 获取指定终端软件清单 */
    public function getClientSoftware(int $client_id): array {
        return $this->get("/api/export/software/{$client_id}");
    }

    /** 获取差异报告统计 */
    public function getDiffStats(): array {
        return $this->get('/api/dashboard/diff');
    }

    /** 获取仪表盘概览 */
    public function getDashboard(): array {
        return $this->get('/api/dashboard');
    }

    /**
     * 分页拉取【全部】客户端
     * 修复：原 getClients 仅取第 1 页（上限 200 条），超过 200 台的终端既无法展示也无法删除。
     * 这里自动翻页，直到取完全部，返回 ['items'=>[], 'total'=>int]
     */
    public function getClientsAll(int $perPage = 200): array {
        $all  = [];
        $page = 1;
        $total = 0;
        do {
            $res   = $this->get('/api/export/clients', ['page' => $page, 'limit' => $perPage]);
            $items = $res['items'] ?? [];
            if ($page === 1) {
                $total = (int)($res['total'] ?? 0);
            }
            foreach ($items as $it) {
                $all[] = $it;
            }
            // 没有更多页 / 安全阀
            if (count($items) < $perPage) break;
            if (++$page > 1000) break;
        } while ($total === 0 || count($all) < $total);

        return ['items' => $all, 'total' => $total ?: count($all)];
    }

    /**
     * 并行批量删除客户端 —— 彻底解决“删除终端非常慢”的问题
     *
     * 原逻辑：前端逐台提交，每台一次 HTTP 往返 + 整页刷新。
     *         删除 N 台 = N 次串行请求 + N 次整页重载（每次重载还要再拉 4+ 个接口）。
     * 现逻辑：一次性收集所有 client_id，用 curl_multi 并发删除（默认并发 16），
     *         无论删多少台，网络往返次数 ≈ ceil(N/16)，秒级完成。
     *
     * @param int[] $clientIds   要删除的客户端 ID 列表
     * @param int   $concurrency 并发数
     * @return array ['ok'=>int,'fail'=>int,'errors'=>string[]]
     */
    public function deleteClientsParallel(array $clientIds, int $concurrency = 16): array {
        $clientIds = array_values(array_filter(array_map('intval', $clientIds), fn($id) => $id > 0));
        $result    = ['ok' => 0, 'fail' => 0, 'errors' => []];
        if (empty($clientIds)) {
            return $result;
        }

        $base   = rtrim($this->cfg['url'], '/');
        $token  = $this->cfg['token'];
        $timeout = (int)($this->cfg['timeout'] ?? 15);
        $concurrency = max(1, min(50, $concurrency));

        foreach (array_chunk($clientIds, $concurrency) as $batch) {
            $mh      = curl_multi_init();
            $handles = [];
            foreach ($batch as $id) {
                $ch = curl_init($base . '/api/clients/' . $id);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_CUSTOMREQUEST  => 'DELETE',
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $token,
                        'Accept: application/json',
                    ],
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$id] = $ch;
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            foreach ($handles as $id => $ch) {
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                if ($err || ($code >= 400 && $code !== 404)) {
                    $result['fail']++;
                    $result['errors'][] = "client #{$id}: " . ($err ?: "HTTP {$code}");
                } else {
                    // 404 视为已删除，计入成功
                    $result['ok']++;
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }

        return $result;
    }
}
