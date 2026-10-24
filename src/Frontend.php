<?php
namespace App;

use Workerman\Protocols\Http\Request;

/**
 * 前端页面：
 *  - 无有效链接 → 提示“暂无抽奖机会，联系客服”
 *  - 有有效链接 → 大转盘 + 奖品栏 + 客户信息 + 须知
 */
class Frontend
{
    public function index(Request $req): \Workerman\Protocols\Http\Response
    {
        $token = $req->get('t') ?? '';
        $siteTitle = Db::get('site_title') ?: '万圣节抽奖';
        $cs = Db::get('customer_service') ?: '';
        $intro = Db::get('intro_text') ?: '';

        $link = $token !== '' ? Db::getLinkByToken($token) : null;

        if (!$link) {
            return $this->gatePage($siteTitle, $cs, $intro);
        }
        return $this->wheelPage($siteTitle, $cs, $intro, $link, $token);
    }

    private function gatePage(string $title, string $cs, string $intro): \Workerman\Protocols\Http\Response
    {
        $html = $this->layout($title, '', '
            <div class="gate">
                <div class="gate-card">
                    <h1>' . $this->e($title) . '</h1>
                    <div class="gate-icon">🎃</div>
                    <p class="gate-title">您还没有抽奖机会</p>
                    <p class="gate-desc">本次活动需通过专属抽奖链接参与。<br>请联系客服领取您的抽奖链接。</p>
                    <div class="cs-box">' . $this->nl2br($this->e($cs)) . '</div>
                    ' . $this->introBlock($intro) . '
                </div>
            </div>
        ');
        return new \Workerman\Protocols\Http\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    private function wheelPage(string $title, string $cs, string $intro, array $link, string $token): \Workerman\Protocols\Http\Response
    {
        $used = Db::usedDraws($link['id']);
        $pool = Db::prizePool();
        $draws = [];
        foreach (Db::linkDraws($link['id']) as $d) {
            $p = Db::getPrize($d['prize_id']);
            if ($p) {
                $draws[] = ['draw_index' => (int)$d['draw_index'], 'prize' => $p];
            }
        }

        $bootstrap = json_encode([
            'token' => $token,
            'title' => $link['title'],
            'total_draws' => $link['total_draws'],
            'used_draws' => $used,
            'customer_name' => $link['customer_name'],
            'customer_amount' => $link['customer_amount'],
            'prizes' => array_map(function ($p) {
                return [
                    'id' => $p['id'], 'level' => $p['level'], 'name' => $p['name'],
                    'value' => $p['value'], 'images' => $p['images'], 'color' => $p['color'],
                ];
            }, $pool),
            'draws' => array_map(function ($d) {
                return [
                    'draw_index' => $d['draw_index'],
                    'prize' => [
                        'id' => $d['prize']['id'], 'level' => $d['prize']['level'],
                        'name' => $d['prize']['name'], 'value' => $d['prize']['value'],
                        'images' => $d['prize']['images'], 'color' => $d['prize']['color'],
                    ],
                ];
            }, $draws),
            'intro_text' => $intro,
            'customer_service' => $cs,
        ], JSON_UNESCAPED_UNICODE);

        $body = '
        <div class="app">
            <header class="app-header">
                <h1 id="siteTitle">' . $this->e($title) . '</h1>
                <div class="link-title" id="linkTitle"></div>
            </header>

            <main class="main">
                <section class="wheel-area">
                    <div class="wheel-wrap">
                        <canvas id="wheel" width="420" height="420"></canvas>
                        <div class="wheel-pointer">▼</div>
                        <button id="spinBtn" class="spin-btn">抽奖</button>
                    </div>
                    <div class="draws-left" id="drawsLeft"></div>
                </section>

                <aside class="side">
                    <div class="card customer-card">
                        <h3>领取信息（请如实填写）</h3>
                        <p class="hint">姓名须与订单收件人一致，金额单位：韩元</p>
                        <input id="custName" class="input" placeholder="您的姓名" />
                        <input id="custAmount" class="input" placeholder="实际消费金额（韩元）" inputmode="numeric" />
                        <button id="saveCust" class="btn-ghost">保存信息</button>
                        <div id="custMsg" class="msg"></div>
                    </div>

                    <div class="card prize-col">
                        <h3>🎁 我的奖品栏</h3>
                        <ul id="prizeList" class="prize-list"></ul>
                        <p class="hint">抽奖完成后请<strong>截图保存本页</strong>发给工作人员核对。</p>
                    </div>

                    <div class="card cs-card">
                        <h3>客服联系方式</h3>
                        <div class="cs-text">' . $this->nl2br($this->e($cs)) . '</div>
                    </div>
                </aside>
            </main>

            ' . $this->introBlock($intro) . '
        </div>

        <!-- 结果弹窗 -->
        <div id="resultMask" class="modal-mask">
            <div class="modal">
                <div class="modal-badge" id="resBadge">🎉</div>
                <h2 id="resLevel"></h2>
                <p id="resName" class="res-name"></p>
                <p id="resValue" class="res-value"></p>
                <p id="resNote" class="res-note"></p>
                <div id="resImages" class="res-images"></div>
                <button id="resClose" class="btn-primary">收入奖品栏</button>
            </div>
        </div>

        <script>window.__LOTTERY__ = ' . $bootstrap . ';</script>
        <script src="/assets/js/wheel.js"></script>
        <script src="/assets/js/frontend.js"></script>
        ';

        $html = $this->layout($title, '', $body);
        return new \Workerman\Protocols\Http\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    private function introBlock(string $intro): string
    {
        if ($intro === '') {
            return '';
        }
        return '
        <section class="intro card">
            <details>
                <summary>📜 抽奖活动须知</summary>
                <pre class="intro-text">' . $this->e($intro) . '</pre>
            </details>
        </section>';
    }

    private function layout(string $title, string $headExtra, string $body): string
    {
        return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>' . $this->e($title) . '</title>
<link rel="stylesheet" href="/assets/css/style.css">
' . $headExtra . '
</head>
<body>
' . $body . '
</body>
</html>';
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private function nl2br(string $s): string
    {
        return nl2br($this->e($s));
    }
}
