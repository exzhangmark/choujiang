<?php
namespace App;

use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * 请求路由：前端页 / 后台 / API / 静态资源
 */
class App
{
    public function handle(Request $req): Response
    {
        $path = $req->path();

        // 静态资源（图片、css、js 等）
        if (strpos($path, '/assets/') === 0) {
            return $this->serveAsset($path);
        }

        // API
        if (strpos($path, '/api/') === 0) {
            return $this->routeApi($req, $path);
        }

        // 后台
        if ($path === '/admin' || strpos($path, '/admin/') === 0) {
            return (new Admin())->dispatch($req, $path);
        }

        // 前端
        if ($path === '/' || $path === '') {
            return (new Frontend())->index($req);
        }

        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], '404 Not Found');
    }

    private function routeApi(Request $req, string $path): Response
    {
        $api = new Api();
        switch ($path) {
            case '/api/link':
                return $api->link($req);
            case '/api/draw':
                return $api->draw($req);
            case '/api/customer':
                return $api->customer($req);
            default:
                return Json::error('unknown api: ' . $path, 404);
        }
    }

    private function serveAsset(string $path): Response
    {
        $rel = substr($path, strlen('/assets/'));
        $rel = ltrim($rel, '/');
        // 防目录穿越
        if (strpos($rel, '..') !== false || strpos($rel, '\\') !== false) {
            return new Response(403, [], 'forbidden');
        }
        $file = PUBLIC_DIR . '/assets/' . $rel;
        if (!is_file($file)) {
            return new Response(404, [], 'not found');
        }
        $mime = $this->mime($file);
        $body = file_get_contents($file);
        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=3600',
        ];
        return new Response(200, $headers, $body);
    }

    private function mime(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $map = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }
}
