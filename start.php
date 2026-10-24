<?php
/**
 * Workerman 启动入口
 * 直接作为 HTTP 服务运行（无需 Apache/Nginx）。
 *
 * 常用命令：
 *   php start.php start      # 前台启动
 *   php start.php start -d   # 守护进程（后台）启动
 *   php start.php stop       # 停止
 *   php start.php restart -d # 重启
 *
 * 环境变量：
 *   PORT   监听端口（默认 8080，Cloudflare 可代理此端口）
 *   WORKERS Worker 进程数（默认 1，使用 SQLite 建议保持 1）
 */
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Json.php';
require_once __DIR__ . '/src/Api.php';
require_once __DIR__ . '/src/Frontend.php';
require_once __DIR__ . '/src/Admin.php';
require_once __DIR__ . '/src/App.php';

$port = (int)(getenv('PORT') ?: 8080);
$worker = new Worker("http://127.0.0.1:$port");
$worker->count = (int)(getenv('WORKERS') ?: 1);
$worker->name = 'halloween-lottery';

$worker->onMessage = function (TcpConnection $conn, Request $req) {
    try {
        $app = new \App\App();
        $resp = $app->handle($req);
    } catch (\Throwable $e) {
        $resp = new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Server Error: ' . $e->getMessage());
    }
    $conn->send($resp);
};

Worker::runAll();
