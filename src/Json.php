<?php
namespace App;

use Workerman\Protocols\Http\Response;

/**
 * 统一的 JSON 响应辅助
 */
class Json
{
    public static function ok(array $data = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode([
            'ok' => true,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE));
    }

    public static function error(string $msg, int $code = 400, array $extra = []): Response
    {
        return new Response($code, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(array_merge([
            'ok' => false,
            'msg' => $msg,
        ], $extra), JSON_UNESCAPED_UNICODE));
    }
}
