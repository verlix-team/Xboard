<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;

class RequestLog
{
    private const SENSITIVE_KEYS = ['password', 'token', 'secret', 'key', 'api_key'];

    public function handle($request, Closure $next)
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $response = $next($request);

        try {
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                return $response;
            }

            $action = $this->resolveAction($request->path());
            $data = collect($request->all())->except(self::SENSITIVE_KEYS)->toArray();
            if ($action === 'plan.save') {
                // 翻译审计只记录语言、默认项和预期版本，不保存完整描述或富文本请求。
                $data['translationLocales'] = array_values(array_map(
                    fn ($row) => is_array($row) ? ($row['locale'] ?? '') : '',
                    is_array($data['translations'] ?? null) ? $data['translations'] : []
                ));
                $data['responseStatus'] = $response->getStatusCode();
                unset($data['translations'], $data['content']);
            }

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'request_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    private function resolveAction(string $path): string
    {
        // api/v2/{secure_path}/user/update → user.update
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path);
        // gift-card/create-template → gift_card.create_template
        $path = str_replace('-', '_', $path);
        // user/update → user.update, server/manage/sort → server_manage.sort
        $segments = explode('/', $path);
        $method = array_pop($segments);
        $resource = implode('_', $segments);

        return $resource . '.' . $method;
    }
}
