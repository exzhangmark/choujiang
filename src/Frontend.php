<?php
namespace App;

use Workerman\Protocols\Http\Request;

/**
 * 前端页面：
 *  - 有有效链接且仍有次数 → 真实抽奖
 *  - 无链接 / 已抽完 → 试玩模式（仍展示转盘，可试转，但不发奖、不记录）
 *  - 所有展示文案均来自后台“前台文案”设置
 */
class Frontend
{
    public function index(Request $req): \Workerman\Protocols\Http\Response
    {
        $token = $req->get('t') ?? '';
        $T = Db::getFrontendTexts();
        $siteTitle = Db::get('site_title') ?: '万圣节抽奖';
        $cs = Db::get('customer_service') ?: '';
        $intro = Db::get('intro_text') ?: '';

        $link = $token !== '' ? Db::getLinkByToken($token) : null;
        $used = $link ? Db::usedDraws($link['id']) : 0;
        $canDraw = $link && $used < $link['total_draws'];

        return $this->wheelPage($T, $siteTitle, $cs, $intro, $link, $token, !$canDraw);
    }

    private function wheelPage(array $T, string $title, string $cs, string $intro, ?array $link, string $token, bool $trial): \Workerman\Protocols\Http\Response
    {
        $used = $link ? Db::usedDraws($link['id']) : 0;
        $pool = Db::prizePool();
        $totalDraws = $link ? (int)$link['total_draws'] : 0;
        $linkTitle = $link['title'] ?? '';

        $draws = [];
        if ($link) {
            foreach (Db::linkDraws($link['id']) as $d) {
                $p = Db::getPrize($d['prize_id']);
                if ($p) {
                    $draws[] = ['draw_index' => (int)$d['draw_index'], 'prize' => $p];
                }
            }
        }

        $bootstrap = json_encode([
            'token' => $token,
            'trial' => $trial,
            'title' => $linkTitle,
            'total_draws' => $totalDraws,
            'used_draws' => $used,
            'prizes' => array_map(function ($p) {
                return [
                    'id' => $p['id'], 'level' => $p['level'], 'name' => $p['name'],
                    'value' => $p['value'], 'images' => $p['images'], 'color' => $p['color'],
                    'desc' => $p['desc'] ?? '',
                ];
            }, $pool),
            'draws' => array_map(function ($d) {
                return [
                    'draw_index' => $d['draw_index'],
                    'prize' => [
                        'id' => $d['prize']['id'], 'level' => $d['prize']['level'],
                        'name' => $d['prize']['name'], 'value' => $d['prize']['value'],
                        'images' => $d['prize']['images'], 'color' => $d['prize']['color'],
                        'desc' => $d['prize']['desc'] ?? '',
                    ],
                ];
            }, $draws),
            'intro_text' => $intro,
            'customer_service' => $cs,
            'texts' => $T,
        ], JSON_UNESCAPED_UNICODE);

        $spinLabel = $trial ? '🎃 免费试玩' : ($T['spin_button'] ?: '抽奖');

        $body = '
        <div class="app">
            <header class="app-header">
                <h1 id="siteTitle">' . $this->e($title) . '</h1>
                ' . ($linkTitle !== '' ? '<div class="link-title" id="linkTitle">' . $this->e($linkTitle) . '</div>' : '<div class="link-title" id="linkTitle"></div>') . '
            </header>

            <main class="main">
                <section class="wheel-area">
                    <div class="wheel-wrap">
                        <canvas id="wheel" width="420" height="420"></canvas>
                        <div class="wheel-pointer">▼</div>
                        <button id="spinBtn" class="spin-btn">' . $this->e($spinLabel) . '</button>
                    </div>
                    <div class="draws-left" id="drawsLeft"></div>
                    ' . ($trial ? '<div class="trial-badge" id="trialBadge">🎃 ' . $this->e($T['trial_badge'] ?? '试玩模式') . '</div>' : '') . '
                    <button id="prizeDetailBtn" class="btn-ghost prize-detail-btn">' . $this->e($T['prize_detail_btn'] ?? '🎁 奖品详情') . '</button>
                </section>

                <aside class="side">
                    <div class="card customer-card">
                        <h3>' . $this->e($T['customer_title'] ?? '领取信息') . '</h3>
                        ' . ($linkTitle !== '' ? '<div class="link-note" id="linkNote">📌 ' . $this->e($linkTitle) . '</div>' : '') . '
                        ' . (!empty($T['customer_hint']) ? '<p class="hint">' . $this->e($T['customer_hint']) . '</p>' : '') . '
                    </div>

                    <div class="card prize-col">
                        <h3>' . $this->e($T['prize_col_title'] ?? '🎁 我的奖品栏') . '</h3>
                        <ul id="prizeList" class="prize-list"></ul>
                        <p class="hint">' . $this->e($T['prize_col_hint'] ?? '') . '</p>
                    </div>

                    <div class="card cs-card">
                        <h3>' . $this->e($T['cs_title'] ?? '客服联系方式') . '</h3>
                        <div class="cs-text">' . $this->nl2br($this->e($cs)) . '</div>
                    </div>
                </aside>
            </main>

            ' . $this->introBlock($intro, $T) . '
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
                <button id="resClose" class="btn-primary">' . $this->e($T['result_claim'] ?? '收入奖品栏') . '</button>
            </div>
        </div>

        <!-- 奖品详情弹窗 -->
        <div id="detailMask" class="modal-mask">
            <div class="modal modal-wide">
                <h2>' . $this->e($T['prize_detail_title'] ?? '奖品详情') . '</h2>
                <div id="detailList" class="detail-list"></div>
                <button id="detailClose" class="btn-primary">关闭</button>
            </div>
        </div>

        <script>window.__LOTTERY__ = ' . $bootstrap . ';</script>
        <script src="/assets/js/wheel.js"></script>
        <script src="/assets/js/frontend.js"></script>
        ';

        $html = $this->layout($title, '', $body);
        return new \Workerman\Protocols\Http\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    private function introBlock(string $intro, array $T): string
    {
        if ($intro === '') {
            return '';
        }
        return '
        <section class="intro card">
            <details open>
                <summary>' . $this->e($T['notice_title'] ?? '📜 抽奖活动须知') . '</summary>
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
