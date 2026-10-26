<?php
namespace App;

use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * 后台管理：登录 / 奖项设置 / 站点设置 / 抽奖链接生成
 */
class Admin
{
    public function dispatch(Request $req, string $path): Response
    {
        if ($path === '/admin/login') {
            return $this->doLogin($req);
        }
        if ($path === '/admin/logout') {
            return $this->doLogout();
        }

        if ($req->method() === 'POST') {
            if (!$this->isAuthed($req)) {
                return $this->jsonError('未登录', 401);
            }
            return $this->handleAction($req);
        }

        // GET
        if (!$this->isAuthed($req)) {
            return $this->loginPage();
        }
        if (($req->get('view') ?? '') === 'update_log') {
            return $this->updateLog();
        }
        return $this->dashboard($req);
    }

    // ---------------- 鉴权 ----------------
    private function adminToken(): string
    {
        return hash_hmac('sha256', (string)Db::get('admin_password'), (string)Db::get('admin_secret'));
    }

    private function isAuthed(Request $req): bool
    {
        $c = $req->cookie(ADMIN_COOKIE);
        return $c !== null && hash_equals($this->adminToken(), $c);
    }

    private function doLogin(Request $req): Response
    {
        $pw = (string)($req->post('password') ?? '');
        $hash = Db::get('admin_password');
        if ($hash && password_verify($pw, $hash)) {
            // 登录后保留 webedit 模式
            $location = ($req->get('webedit') !== null) ? '/admin?webedit' : '/admin';
            $resp = new Response(302, ['Location' => $location]);
            return $resp->cookie(ADMIN_COOKIE, $this->adminToken(), ADMIN_COOKIE_TTL, '/', '', false, true);
        }
        return $this->loginPage('密码错误');
    }

    private function doLogout(): Response
    {
        $resp = new Response(302, ['Location' => '/admin']);
        return $resp->cookie(ADMIN_COOKIE, '', -1, '/', '', false, true);
    }

    private function requireAuth(Request $req): ?Response
    {
        if (!$this->isAuthed($req)) {
            return new Response(302, ['Location' => '/admin']);
        }
        return null;
    }

    // ---------------- 动作 ----------------
    private function handleAction(Request $req): Response
    {
        $action = $req->post('action') ?? '';
        switch ($action) {
            case 'prize_save':
                return $this->prizeSave($req);
            case 'prize_delete':
                return $this->prizeDelete($req);
            case 'settings_save':
                return $this->settingsSave($req);
            case 'texts_save':
                return $this->textsSave($req);
            case 'link_generate':
                return $this->linkGenerate($req);
            case 'link_delete':
                return $this->linkDelete($req);
            case 'update':
                return $this->doUpdate($req);
            default:
                return $this->dashboard($req, '未知操作');
        }
    }

    private function prizeSave(Request $req): Response
    {
        $id = (int)($req->post('id') ?? 0);
        $level = trim((string)($req->post('level') ?? ''));
        $name = trim((string)($req->post('name') ?? ''));
        $value = trim((string)($req->post('value') ?? ''));
        $color = trim((string)($req->post('color') ?? '#f0a500'));
        $sort = (int)($req->post('sort') ?? 0);
        $limited = $req->post('limited') ? 1 : 0;
        $totalQty = (int)($req->post('total_qty') ?? 0);
        $prizeDesc = trim((string)($req->post('prize_desc') ?? ''));

        // 图片：已有 + 新上传 + 新增URL
        $images = [];
        $existing = $req->post('existing_images');
        if ($existing) {
            $dec = json_decode($existing, true);
            if (is_array($dec)) {
                $images = array_values(array_filter($dec, fn($x) => is_string($x) && $x !== ''));
            }
        }
        // 勾选删除的
        $del = $req->post('del_images');
        if (is_array($del)) {
            $images = array_values(array_diff($images, $del));
        }
        $uploaded = $this->saveUploads($req);
        $images = array_merge($images, $uploaded);
        $urls = trim((string)($req->post('image_urls') ?? ''));
        if ($urls !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $urls) as $u) {
                $u = trim($u);
                if ($u !== '') {
                    $images[] = $u;
                }
            }
        }
        $images = array_values(array_unique($images));

        if ($id > 0) {
            Db::savePrize(compact('id', 'level', 'name', 'value', 'color', 'sort', 'limited', 'total_qty', 'images', 'prize_desc'));
            $msg = '奖项已更新';
        } else {
            Db::savePrize(compact('level', 'name', 'value', 'color', 'sort', 'limited', 'total_qty', 'images', 'prize_desc'));
            $msg = '奖项已添加';
        }
        return $this->dashboard($req, $msg);
    }

    private function prizeDelete(Request $req): Response
    {
        $id = (int)($req->post('id') ?? 0);
        if ($id > 0) {
            Db::deletePrize($id);
        }
        return $this->dashboard($req, '奖项已删除');
    }

    private function settingsSave(Request $req): Response
    {
        // 仅更新“本次表单实际提交的字段”，避免改密码/改文案时互相覆盖已存值
        $all = $req->post() ?: [];
        if (array_key_exists('site_title', $all)) {
            $title = trim((string)($all['site_title'] ?? ''));
            if ($title !== '') {
                Db::set('site_title', $title);
            }
        }
        if (array_key_exists('customer_service', $all)) {
            Db::set('customer_service', trim((string)($all['customer_service'] ?? '')));
        }
        if (array_key_exists('intro_text', $all)) {
            Db::set('intro_text', trim((string)($all['intro_text'] ?? '')));
        }
        // 站点设置表单提交（以 site_title 是否存在区分）：复选框未勾选时不传 show_cs 键，故按键存在与否判定开关
        if (array_key_exists('site_title', $all)) {
            Db::set('show_cs', array_key_exists('show_cs', $all) ? '1' : '0');
        }
        if (array_key_exists('admin_password', $all)) {
            $newPw = (string)($all['admin_password'] ?? '');
            if ($newPw !== '') {
                Db::set('admin_password', password_hash($newPw, PASSWORD_DEFAULT));
                // 修改密码后重新登录
                $resp = $this->dashboard($req, '设置已保存，密码已更新，请重新登录');
                return $resp->cookie(ADMIN_COOKIE, '', -1, '/', '', false, true);
            }
        }
        return $this->dashboard($req, '设置已保存');
    }

    private function linkGenerate(Request $req): Response
    {
        $title = trim((string)($req->post('title') ?? ''));
        $draws = $req->post('draws');
        if (!is_array($draws) || count($draws) === 0) {
            return $this->dashboard($req, '请至少设置一次抽奖');
        }
        $draws = array_values(array_map('intval', $draws));
        // 校验每个奖品存在
        foreach ($draws as $pid) {
            if (!Db::getPrize($pid)) {
                return $this->dashboard($req, '存在无效的奖项');
            }
        }
        $token = Db::createLink($title, count($draws), $draws);
        $url = $this->baseUrl($req) . '/?t=' . $token;
        return $this->dashboard($req, '', ['generated' => $url, 'genTitle' => $title]);
    }

    private function linkDelete(Request $req): Response
    {
        $id = (int)($req->post('id') ?? 0);
        if ($id > 0) {
            Db::deleteLink($id);
        }
        return $this->dashboard($req, '链接已删除');
    }

    // ---------------- 一键更新 ----------------
    private function doUpdate(Request $req): Response
    {
        $script = BASE_DIR . '/update.sh';
        if (!is_file($script)) {
            return $this->dashboard($req, '未找到 update.sh，请确认部署完整');
        }
        // 脱离当前请求在后台执行，避免重启 Workerman 中断本次 HTTP 响应
        $cmd = 'nohup setsid bash ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
        @exec($cmd);
        return $this->dashboard($req, '更新已在后台启动，请稍候在下方“更新日志”中查看进度，完成后刷新页面即可');
    }

    private function updateLog(): Response
    {
        $log = BASE_DIR . '/data/update.log';
        $content = is_file($log) ? @file_get_contents($log) : '';
        return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], $content ?: '');
    }

    // ---------------- 上传 ----------------
    private function saveUploads(Request $req): array
    {
        $out = [];
        $files = $req->file('images');
        if (empty($files)) {
            return $out;
        }
        // 兼容两种结构：
        //  A) Workerman v5：$req->file('images') 返回“每个文件”的数组列表
        //     [ ['name'=>..,'tmp_name'=>..,'error'=>0], ... ]
        //  B) 旧式 $_FILES 结构：tmp_name 为字符串或数组
        $list = [];
        if (isset($files['tmp_name'])) {
            $tmp = $files['tmp_name'];
            if (is_array($tmp)) {
                foreach ($tmp as $i => $path) {
                    if (($files['error'][$i] ?? 1) !== 0 || !is_file($path)) {
                        continue;
                    }
                    $list[] = ['tmp_name' => $path, 'name' => $files['name'][$i] ?? 'file', 'error' => 0];
                }
            } else {
                if (($files['error'] ?? 1) === 0 && is_file($tmp)) {
                    $list[] = ['tmp_name' => $tmp, 'name' => $files['name'] ?? 'file', 'error' => 0];
                }
            }
        } else {
            foreach ($files as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $list[] = $f;
            }
        }
        foreach ($list as $f) {
            $tmp = $f['tmp_name'] ?? null;
            if (($f['error'] ?? 1) !== 0 || !is_string($tmp) || !is_file($tmp)) {
                continue;
            }
            $out[] = $this->moveUploaded($tmp, $f['name'] ?? 'file');
        }
        return $out;
    }

    private function moveUploaded(string $tmpPath, string $origName): string
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $ext = preg_match('/^(jpg|jpeg|png|gif|webp)$/', $ext) ? $ext : 'png';
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = UPLOAD_DIR . '/' . $name;
        if (!@rename($tmpPath, $dest)) {
            copy($tmpPath, $dest);
            @unlink($tmpPath);
        }
        return UPLOAD_URL . '/' . $name;
    }

    // ---------------- 页面 ----------------
    private function loginPage(string $err = ''): Response
    {
        $html = $this->layout('后台登录', '
            <div class="login-wrap">
                <form class="login-form" method="post" action="/admin/login">
                    <h1>🔐 后台登录</h1>
                    ' . ($err ? '<p class="err">' . $this->e($err) . '</p>' : '') . '
                    <input class="input" type="password" name="password" placeholder="请输入后台密码" autofocus>
                    <button class="btn-primary" type="submit">登录</button>
                    <p class="hint">默认密码 admin888，可在“前台文案 → 修改后台密码”中修改</p>
                </form>
            </div>');
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    private function dashboard(Request $req, string $msg = '', array $extra = []): Response
    {
        $redirect = $this->requireAuth($req);
        if ($redirect) {
            return $redirect;
        }
        $prizes = Db::allPrizes();
        $links = Db::allLinks();
        $settings = [
            'site_title' => Db::get('site_title'),
            'customer_service' => Db::get('customer_service'),
            'intro_text' => Db::get('intro_text'),
            'show_cs' => Db::get('show_cs'),
        ];
        $frontendTexts = Db::getFrontendTexts();

        // “前台文案”模块默认隐藏，仅当通过 ?webedit 进入后台时才显示
        $webedit = $req->get('webedit') !== null || $req->post('webedit') !== null;

        // 编辑中的奖项
        $editId = (int)($req->get('edit_prize') ?? 0);
        $editPrize = $editId > 0 ? Db::getPrize($editId) : null;

        $html = $this->layout('后台管理', $this->adminCss() . '
            <div class="admin">
                <header class="admin-head">
                    <h1>🎃 抽奖后台</h1>
                    <div class="admin-nav">
                        <a href="#sec-links">抽奖链接</a>
                        <a href="#sec-prizes">奖项设置</a>
                        ' . ($webedit ? '<a href="#sec-texts">前台文案</a>' : '') . '
                        <a href="#sec-update">系统更新</a>
                        <a href="/" target="_blank">前台预览</a>
                        <form method="post" action="/admin/logout" style="display:inline">
                            <button class="btn-ghost" type="submit">退出</button>
                        </form>
                    </div>
                </header>
                ' . ($msg ? '<div class="alert">' . $this->e($msg) . '</div>' : '') . '

                <section id="sec-links" class="sec">
                    <h2>抽奖链接生成</h2>
                    ' . $this->linkGenerator($prizes, $extra) . '
                    <h3>已生成链接（' . count($links) . '）</h3>
                    ' . $this->linkTable($links, $req) . '
                </section>

                <section id="sec-prizes" class="sec">
                    <h2>奖项设置</h2>
                    ' . $this->prizeForm($editPrize) . '
                    <h3>现有奖项（' . count($prizes) . '）</h3>
                    ' . $this->prizeTable($prizes) . '
                </section>

                ' . ($webedit ? '
                <section id="sec-texts" class="sec">
                    <h2>前台文案（展示给访客的文字）</h2>
                    ' . $this->textsForm($frontendTexts) . '
                    <h3>站点基础设置</h3>
                    ' . $this->siteSettingsForm($settings) . '
                    <h3>修改后台密码</h3>
                    ' . $this->passwordForm() . '
                </section>' : '') . '

                <section id="sec-update" class="sec">
                    <h2>系统更新（一键更新）</h2>
                    <div class="card form">
                        <p class="hint">将服务器代码更新为远程仓库最新版本（git pull + 依赖更新 + 重启服务）。<br>本地改动请先提交并推送到远程仓库，再点此拉取。</p>
                        <button class="btn-primary" id="btnUpdate" type="button">🚀 一键更新</button>
                        <div id="updateStatus" class="msg"></div>
                        <pre id="updateLog" class="update-log"></pre>
                    </div>
                </section>
            </div>
            <script src="/assets/js/admin.js"></script>
        ');
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    private function prizeForm(?array $p): string
    {
        $p = $p ?? ['id' => 0, 'level' => '', 'name' => '', 'value' => '', 'color' => '#f0a500', 'sort' => 0, 'limited' => 0, 'total_qty' => 1, 'images' => []];
        $isEdit = ($p['id'] ?? 0) > 0;
        $existing = '';
        foreach ($p['images'] as $img) {
            $existing .= '
            <div class="img-item">
                <img src="' . $this->e($img) . '" alt="">
                <label><input type="checkbox" name="del_images[]" value="' . $this->e($img) . '"> 删除</label>
            </div>';
        }
        return '
        <form class="card form" method="post" action="/admin" enctype="multipart/form-data">
            <input type="hidden" name="action" value="prize_save">
            <input type="hidden" name="id" value="' . (int)$p['id'] . '">
            <div class="row">
                <label>等级（如：特等奖）<input class="input" name="level" value="' . $this->e($p['level']) . '" required></label>
                <label>名称（如：iPhone 16）<input class="input" name="name" value="' . $this->e($p['name']) . '" required></label>
            </div>
            <div class="row">
                <label>价值（如：价值 4000 元）<input class="input" name="value" value="' . $this->e($p['value']) . '"></label>
                <label>排序（越小越靠前）<input class="input" type="number" name="sort" value="' . (int)$p['sort'] . '"></label>
            </div>
            <label>奖项介绍（展示在“奖品详情”弹窗中）<textarea class="input" name="prize_desc" rows="2" placeholder="如：万圣节限量礼盒，含……">' . $this->e($p['desc'] ?? '') . '</textarea></label>
            <div class="row">
                <label>转盘颜色<input class="input" type="color" name="color" value="' . $this->e($p['color']) . '"></label>
                <label class="chk"><input type="checkbox" name="limited" value="1" ' . ($p['limited'] ? 'checked' : '') . '> 限量（抽完即止）</label>
                <label>限量数量<input class="input" type="number" name="total_qty" value="' . (int)$p['total_qty'] . '"></label>
            </div>
            <label>上传图片（可多选，数量不限）<input class="input" type="file" name="images[]" multiple accept="image/*"></label>
            <label>或填写图片URL（每行一个）<textarea class="input" name="image_urls" rows="2" placeholder="https://..."></textarea></label>
            ' . ($existing ? '<div class="img-grid">已有图片：' . $existing . '</div>
                <input type="hidden" name="existing_images" value="' . $this->e(json_encode($p['images'], JSON_UNESCAPED_UNICODE)) . '">' : '') . '
            <button class="btn-primary" type="submit">' . ($isEdit ? '保存修改' : '添加奖项') . '</button>
            ' . ($isEdit ? '<a class="btn-ghost" href="/admin">取消</a>' : '') . '
        </form>';
    }

    private function prizeTable(array $prizes): string
    {
        if (empty($prizes)) {
            return '<p class="hint">暂无奖项，请先添加。</p>';
        }
        $rows = '';
        foreach ($prizes as $p) {
            $thumb = $p['images'][0] ?? '';
            $rows .= '
            <tr>
                <td>' . ($thumb ? '<img class="thumb" src="' . $this->e($thumb) . '">' : '—') . '</td>
                <td>' . $this->e($p['level']) . '</td>
                <td>' . $this->e($p['name']) . '</td>
                <td>' . $this->e($p['value']) . '</td>
                <td><span class="dot" style="background:' . $this->e($p['color']) . '"></span></td>
                <td>' . ($p['limited'] ? '限量 ' . $p['used_qty'] . '/' . $p['total_qty'] : '不限') . '</td>
                <td class="desc-cell">' . $this->e($p['desc'] ?? '') . '</td>
                <td class="ops">
                    <a class="btn-ghost" href="/admin?edit_prize=' . $p['id'] . '">编辑</a>
                    <form method="post" action="/admin" style="display:inline" onsubmit="return confirm(\'确定删除？\');">
                        <input type="hidden" name="action" value="prize_delete">
                        <input type="hidden" name="id" value="' . $p['id'] . '">
                        <button class="btn-danger" type="submit">删除</button>
                    </form>
                </td>
            </tr>';
        }
        return '<table class="tbl"><thead><tr><th>图</th><th>等级</th><th>名称</th><th>价值</th><th>颜色</th><th>数量</th><th>介绍</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function linkGenerator(array $prizes, array $extra): string
    {
        $options = '';
        foreach ($prizes as $p) {
            $options .= '<option value="' . $p['id'] . '">' . $this->e($p['level'] . ' · ' . $p['name']) . '</option>';
        }
        $gen = '';
        if (!empty($extra['generated'])) {
            $gen = '
            <div class="gen-result">
                <p>✅ 链接已生成：<strong>' . $this->e($extra['genTitle'] ?? '') . '</strong></p>
                <div class="copy-row">
                    <input id="genUrl" class="input" readonly value="' . $this->e($extra['generated']) . '">
                    <button class="btn-primary" onclick="copyGen()">复制</button>
                </div>
                <p class="hint">把此链接发给对应客户，客户打开即可获得预设的抽奖次数与奖品（转盘仅作动画展示）。</p>
            </div>';
        }
        return '
        <form class="card form" method="post" action="/admin" id="linkForm">
            <input type="hidden" name="action" value="link_generate">
            <label>链接备注/标题（显示在前台页面）<input class="input" name="title" placeholder="如：客户A-订单12345"></label>
            <label>抽奖次数
                <select class="input" name="draws_count" id="drawsCount" onchange="buildDrawRows()">
                    ' . implode('', array_map(fn($i) => '<option value="' . $i . '">' . $i . ' 次</option>', range(1, 10))) . '
                </select>
            </label>
            <div id="drawRows" class="draw-rows"></div>
            <button class="btn-primary" type="submit">生成抽奖链接</button>
        </form>
        <script>window.__PRIZE_OPTIONS__ = `' . $options . '`;</script>
        ' . $gen;
    }

    private function linkTable(array $links, Request $req): string
    {
        if (empty($links)) {
            return '<p class="hint">还没有生成链接。</p>';
        }
        $base = $this->baseUrl($req);
        $rows = '';
        foreach ($links as $l) {
            $used = Db::usedDraws($l['id']);
            $url = $base . '/?t=' . $l['token'];
            // 展示每次预设的奖品名称
            $plan = [];
            foreach ($l['draws_config'] as $i => $pid) {
                $p = Db::getPrize($pid);
                $plan[] = '第' . ($i + 1) . '次：' . ($p ? $this->e($p['level'] . '·' . $p['name']) : '已删除');
            }
            $rows .= '
            <tr>
                <td>' . $this->e($l['title'] ?: '(未命名)') . '</td>
                <td>' . $l['total_draws'] . ' 次（已抽 ' . $used . '）</td>
                <td class="plan">' . implode('<br>', $plan) . '</td>
                <td>
                    <div class="copy-row">
                        <input class="input mini" readonly value="' . $this->e($url) . '">
                        <button class="btn-ghost" onclick="copyText(this)">复制</button>
                    </div>
                    <form method="post" action="/admin" style="display:inline" onsubmit="return confirm(\'删除后该客户的抽奖记录也会清除，确定？\');">
                        <input type="hidden" name="action" value="link_delete">
                        <input type="hidden" name="id" value="' . $l['id'] . '">
                        <button class="btn-danger" type="submit">删除</button>
                    </form>
                </td>
            </tr>';
        }
        return '<table class="tbl"><thead><tr><th>备注</th><th>次数</th><th>预设奖品</th><th>链接/操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function siteSettingsForm(array $s): string
    {
        return '
        <form class="card form" method="post" action="/admin">
            <input type="hidden" name="action" value="settings_save">
            <input type="hidden" name="webedit" value="1">
            <label>站点标题<input class="input" name="site_title" value="' . $this->e($s['site_title']) . '"></label>
            <label>客服联系方式（支持换行，可写微信/Line/电话等）<textarea class="input" name="customer_service" rows="3">' . $this->e($s['customer_service']) . '</textarea></label>
            <label>抽奖须知文案<textarea class="input" name="intro_text" rows="12">' . $this->e($s['intro_text']) . '</textarea></label>
            <label class="chk"><input type="checkbox" name="show_cs" value="1"' . (!empty($s['show_cs']) ? ' checked' : '') . '> 在前台显示客服联系方式模块</label>
            <button class="btn-primary" type="submit">保存设置</button>
        </form>';
    }

    private function passwordForm(): string
    {
        return '
        <form class="card form" method="post" action="/admin">
            <input type="hidden" name="action" value="settings_save">
            <input type="hidden" name="webedit" value="1">
            <label>修改后台密码（留空则不修改）<input class="input" type="password" name="admin_password" placeholder="新密码"></label>
            <button class="btn-primary" type="submit">更新密码</button>
        </form>';
    }

    private function textsForm(array $t): string
    {
        $fields = [
            'gate_title' => ['label' => '无机会页标题', 'type' => 'input'],
            'gate_desc' => ['label' => '无机会页说明（支持换行）', 'type' => 'textarea'],
            'gate_contact_label' => ['label' => '客服区标题', 'type' => 'input'],
            'customer_title' => ['label' => '领取信息标题', 'type' => 'input'],
            'prize_col_title' => ['label' => '奖品栏标题', 'type' => 'input'],
            'prize_col_hint' => ['label' => '奖品栏提示', 'type' => 'textarea'],
            'cs_title' => ['label' => '客服联系方式标题', 'type' => 'input'],
            'spin_button' => ['label' => '抽奖按钮文字', 'type' => 'input'],
            'trial_badge' => ['label' => '试玩模式标识', 'type' => 'input'],
            'trial_spin_button' => ['label' => '试玩模式按钮文字', 'type' => 'input'],
            'spinning_text' => ['label' => '抽奖中按钮文字', 'type' => 'input'],
            'trial_note' => ['label' => '试玩提示语', 'type' => 'textarea'],
            'draws_left' => ['label' => '剩余次数文案（{left}=剩余 {total}=总次数）', 'type' => 'input'],
            'result_claim' => ['label' => '结果弹窗按钮文字', 'type' => 'input'],
            'notice_title' => ['label' => '活动须知标题', 'type' => 'input'],
            'prize_detail_btn' => ['label' => '奖品详情按钮文字', 'type' => 'input'],
            'prize_detail_title' => ['label' => '奖品详情弹窗标题', 'type' => 'input'],
        ];
        $rows = '';
        foreach ($fields as $k => $f) {
            $val = $t[$k] ?? '';
            if ($f['type'] === 'textarea') {
                $rows .= '<label>' . $f['label'] . '<textarea class="input" name="' . $k . '" rows="2">' . $this->e($val) . '</textarea></label>';
            } else {
                $rows .= '<label>' . $f['label'] . '<input class="input" name="' . $k . '" value="' . $this->e($val) . '"></label>';
            }
        }
        return '
        <form class="card form" method="post" action="/admin">
            <input type="hidden" name="action" value="texts_save">
            <input type="hidden" name="webedit" value="1">
            ' . $rows . '
            <button class="btn-primary" type="submit">保存文案</button>
        </form>
        <p class="hint">这些文字会展示给访客，可随时修改；留空则使用默认中文。</p>';
    }

    private function textsSave(Request $req): Response
    {
        $keys = ['gate_title', 'gate_desc', 'gate_contact_label', 'customer_title', 'prize_col_title', 'prize_col_hint', 'cs_title', 'spin_button', 'trial_badge', 'trial_spin_button', 'spinning_text', 'trial_note', 'draws_left', 'result_claim', 'notice_title', 'prize_detail_btn', 'prize_detail_title'];
        $data = [];
        foreach ($keys as $k) {
            $data[$k] = trim((string)($req->post($k) ?? ''));
        }
        Db::set('frontend_texts', $data);
        return $this->dashboard($req, '文案已保存');
    }

    // ---------------- 工具 ----------------
    private function baseUrl(Request $req): string
    {
        $host = $req->host();
        $scheme = $req->header('x-forwarded-proto') ?: 'http';
        return $scheme . '://' . $host;
    }

    private function jsonError(string $msg, int $code = 400): Response
    {
        return new Response($code, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['ok' => false, 'msg' => $msg], JSON_UNESCAPED_UNICODE));
    }

    private function adminCss(): string
    {
        return '<link rel="stylesheet" href="/assets/css/admin.css">';
    }

    private function layout(string $title, string $body): string
    {
        return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . $this->e($title) . '</title>
<link rel="stylesheet" href="/assets/css/style.css">
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
}
