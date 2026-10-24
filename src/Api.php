<?php
namespace App;

use Workerman\Protocols\Http\Request;

/**
 * 前端调用的 API
 */
class Api
{
    /** GET /api/link?t=TOKEN —— 返回链接信息 + 奖品池 + 站点配置 + 已抽记录 */
    public function link(Request $req): \Workerman\Protocols\Http\Response
    {
        $token = $req->get('t') ?? '';
        $link = Db::getLinkByToken($token);
        if (!$link) {
            return Json::error('无效的抽奖链接，请联系客服获取', 404);
        }
        $used = Db::usedDraws($link['id']);
        $pool = Db::prizePool();
        $draws = [];
        foreach (Db::linkDraws($link['id']) as $d) {
            $p = Db::getPrize($d['prize_id']);
            if ($p) {
                $draws[] = [
                    'draw_index' => (int)$d['draw_index'],
                    'drawn_at' => $d['drawn_at'],
                    'prize' => $this->prizeView($p),
                ];
            }
        }
        return Json::ok([
            'site_title' => Db::get('site_title'),
            'customer_service' => Db::get('customer_service'),
            'intro_text' => Db::get('intro_text'),
            'link' => [
                'title' => $link['title'],
                'total_draws' => $link['total_draws'],
                'used_draws' => $used,
                'can_draw' => $used < $link['total_draws'],
                'customer_name' => $link['customer_name'],
                'customer_amount' => $link['customer_amount'],
            ],
            'prizes' => array_map([$this, 'prizeView'], $pool),
            'draws' => $draws,
        ]);
    }

    /** POST /api/draw {token, draw_index} —— 按链接预设奖品返回结果并记录 */
    public function draw(Request $req): \Workerman\Protocols\Http\Response
    {
        $token = (string)($req->post('token') ?? '');
        $idx = (int)($req->post('draw_index') ?? -1);
        $link = Db::getLinkByToken($token);
        if (!$link) {
            return Json::error('无效的抽奖链接', 404);
        }
        if ($idx < 0 || $idx >= $link['total_draws']) {
            return Json::error('抽奖序号超出范围', 400);
        }
        // 已抽过：直接返回历史结果（刷新不重复抽）
        $existing = Db::getDraw($link['id'], $idx);
        if ($existing) {
            $p = Db::getPrize($existing['prize_id']);
            if ($p) {
                return Json::ok([
                    'draw_index' => $idx,
                    'replayed' => true,
                    'prize' => $this->prizeView($p),
                    'remaining' => $link['total_draws'] - Db::usedDraws($link['id']),
                ]);
            }
        }

        $config = $link['draws_config'];
        $presetId = $config[$idx] ?? null;
        if ($presetId === null) {
            return Json::error('该次抽奖未配置奖品', 400);
        }

        $preferred = Db::getPrize((int)$presetId);
        if (!$preferred) {
            $preferred = Db::fallbackPrize();
        }

        // 候选链：预设奖品 → 兜底奖品 → 任意还有余量的奖品
        $candidates = [$preferred];
        $fb = Db::fallbackPrize();
        if ($fb && $fb['id'] !== $preferred['id']) {
            $candidates[] = $fb;
        }
        $any = Db::getAnyAvailable();
        if ($any && $any['id'] !== $preferred['id'] && (!$fb || $any['id'] !== $fb['id'])) {
            $candidates[] = $any;
        }

        // 原子占用：同一时刻多个用户抢“仅剩 1 个”的奖项时，只有 1 人成功，其余自动回退
        $chosen = null;
        $exhausted = false;
        foreach ($candidates as $c) {
            if (Db::claimUnit($c['id'])) {
                $chosen = $c;
                break;
            }
        }
        if ($chosen === null) {
            // 极端情况：所有奖项都已抽完，仍记录预设奖品但不再占用名额
            $chosen = $preferred;
            $exhausted = true;
        } elseif ($chosen['id'] !== $preferred['id']) {
            $exhausted = true; // 实际发放的是回退奖品（预设的已被抢光）
        }

        Db::recordDraw($link['id'], $idx, $chosen['id']);

        return Json::ok([
            'draw_index' => $idx,
            'replayed' => false,
            'exhausted' => $exhausted,
            'prize' => $this->prizeView($chosen),
            'remaining' => $link['total_draws'] - Db::usedDraws($link['id']),
        ]);
    }

    /** POST /api/customer {token, name, amount} —— 保存客户姓名/消费金额 */
    public function customer(Request $req): \Workerman\Protocols\Http\Response
    {
        $token = (string)($req->post('token') ?? '');
        $name = trim((string)($req->post('name') ?? ''));
        $amount = trim((string)($req->post('amount') ?? ''));
        $link = Db::getLinkByToken($token);
        if (!$link) {
            return Json::error('无效的抽奖链接', 404);
        }
        Db::saveCustomer($link['id'], $name, $amount);
        return Json::ok(['saved' => true]);
    }

    private function prizeView(array $p): array
    {
        return [
            'id' => $p['id'],
            'level' => $p['level'],
            'name' => $p['name'],
            'value' => $p['value'],
            'images' => $p['images'],
            'color' => $p['color'],
        ];
    }
}
