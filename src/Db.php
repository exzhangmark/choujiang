<?php
namespace App;

require_once __DIR__ . '/../config.php';

/**
 * 轻量 SQLite 数据访问层。
 * 四个表：
 *  - settings : 键值配置（后台密码、客服号、须知文案、站点标题等）
 *  - prizes   : 奖项（等级 / 名称 / 价值 / 多图 / 颜色 / 限量）
 *  - links    : 抽奖链接（token / 次数 / 每次预设奖品 / 客户信息）
 *  - draws    : 抽奖记录（某个链接的第几次抽到了哪个奖品）
 */
class Db
{
    private static $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new \PDO('sqlite:' . DB_FILE);
            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            // 并发友好：写锁等待 + WAL 模式（多 Worker / 多进程安全）
            self::$pdo->exec("PRAGMA busy_timeout = 5000;");
            self::$pdo->exec("PRAGMA journal_mode = WAL;");
            self::init();
            self::migrate();
        }
        return self::$pdo;
    }

    private static function init(): void
    {
        $pdo = self::$pdo;
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            k TEXT PRIMARY KEY,
            v TEXT
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS prizes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            level TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL DEFAULT '',
            value TEXT NOT NULL DEFAULT '',
            images TEXT NOT NULL DEFAULT '[]',
            color TEXT NOT NULL DEFAULT '#f0a500',
            prize_desc TEXT NOT NULL DEFAULT '',
            sort INTEGER NOT NULL DEFAULT 0,
            limited INTEGER NOT NULL DEFAULT 0,
            total_qty INTEGER NOT NULL DEFAULT 9999,
            used_qty INTEGER NOT NULL DEFAULT 0
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL DEFAULT '',
            total_draws INTEGER NOT NULL DEFAULT 0,
            draws_config TEXT NOT NULL DEFAULT '[]',
            customer_name TEXT NOT NULL DEFAULT '',
            customer_amount TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT '',
            expires_at TEXT
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS draws (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            link_id INTEGER NOT NULL,
            draw_index INTEGER NOT NULL,
            prize_id INTEGER NOT NULL,
            drawn_at TEXT NOT NULL DEFAULT '',
            UNIQUE(link_id, draw_index)
        )");

        // 默认配置
        if (self::get('admin_secret') === null) {
            self::set('admin_secret', bin2hex(random_bytes(16)));
        }
        if (self::get('admin_password') === null) {
            self::set('admin_password', password_hash('admin888', PASSWORD_DEFAULT));
        }
        if (self::get('site_title') === null) {
            self::set('site_title', '万圣节抽奖');
        }
        if (self::get('customer_service') === null) {
            self::set('customer_service', "客服微信 / Line：请替换为真实客服号\n工作时间：10:00 - 22:00（韩国时间）");
        }
        if (self::get('intro_text') === null) {
            self::set('intro_text', self::defaultIntro());
        }
        if (self::get('show_cs') === null) {
            self::set('show_cs', '1');
        }
        if (self::countPrizes() === 0) {
            self::seedPrizes();
        }
    }

    // ---------- settings ----------
    private static function migrate(): void
    {
        $pdo = self::pdo();
        try {
            $pdo->exec("ALTER TABLE prizes ADD COLUMN prize_desc TEXT NOT NULL DEFAULT ''");
        } catch (\PDOException $e) { /* 列已存在则忽略 */ }
    }

    /** 前台展示文案（后台可编辑；未设置则用默认中文） */
    public static function getFrontendTexts(): array
    {
        $raw = self::get('frontend_texts');
        $t = $raw ? json_decode($raw, true) : [];
        $defaults = [
            'gate_title' => '您还没有抽奖机会',
            'gate_desc' => "本次活动需通过专属抽奖链接参与。\n请联系客服领取您的抽奖链接。",
            'gate_contact_label' => '客服联系方式',
            'customer_title' => '领取信息',
            'prize_col_title' => '🎁 我的奖品栏',
            'prize_col_hint' => '抽奖完成后请截图保存本页发给工作人员核对。',
            'cs_title' => '客服联系方式',
            'spin_button' => '抽奖',
            'trial_badge' => '试玩模式 · 不计奖品',
            'trial_note' => '这是试玩演示，转盘结果仅供展示，实际奖品以您的专属抽奖链接为准 🎃',
            'trial_spin_button' => '🎃 免费试玩',
            'spinning_text' => '抽奖中…',
            'draws_left' => '剩余抽奖次数：{left} / 共 {total} 次',
            'result_claim' => '收入奖品栏',
            'notice_title' => '📜 抽奖活动须知',
            'prize_detail_btn' => '🎁 奖品详情',
            'prize_detail_title' => '奖品详情',
        ];
        return is_array($t) ? array_merge($defaults, $t) : $defaults;
    }

    public static function get(string $k)
    {
        $stmt = self::pdo()->prepare("SELECT v FROM settings WHERE k = ?");
        $stmt->execute([$k]);
        $row = $stmt->fetch();
        return $row === false ? null : $row['v'];
    }

    public static function set(string $k, $v): void
    {
        $stmt = self::pdo()->prepare("INSERT INTO settings (k, v) VALUES (?, ?)
            ON CONFLICT(k) DO UPDATE SET v = excluded.v");
        $stmt->execute([$k, is_array($v) || is_object($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v]);
    }

    // ---------- prizes ----------
    public static function countPrizes(): int
    {
        return (int)self::pdo()->query("SELECT COUNT(*) FROM prizes")->fetchColumn();
    }

    /** 奖品池（转盘上展示的所有奖项，按 sort 升序） */
    public static function prizePool(): array
    {
        $stmt = self::pdo()->query("SELECT * FROM prizes ORDER BY sort ASC, id ASC");
        return array_map([self::class, 'decodePrize'], $stmt->fetchAll());
    }

    public static function allPrizes(): array
    {
        return self::prizePool();
    }

    public static function getPrize(int $id): ?array
    {
        $stmt = self::pdo()->prepare("SELECT * FROM prizes WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::decodePrize($row) : null;
    }

    private static function decodePrize(array $row): array
    {
        $row['images'] = json_decode($row['images'], true) ?: [];
        $row['desc'] = $row['prize_desc'] ?? '';
        $row['limited'] = (int)$row['limited'];
        $row['total_qty'] = (int)$row['total_qty'];
        $row['used_qty'] = (int)$row['used_qty'];
        return $row;
    }

    public static function savePrize(array $data): int
    {
        $pdo = self::pdo();
        if (!empty($data['id'])) {
            $stmt = $pdo->prepare("UPDATE prizes SET level=?, name=?, value=?, images=?, color=?, sort=?, limited=?, total_qty=?, prize_desc=? WHERE id=?");
            $stmt->execute([
                $data['level'], $data['name'], $data['value'],
                json_encode($data['images'], JSON_UNESCAPED_UNICODE),
                $data['color'], (int)$data['sort'], (int)$data['limited'], (int)$data['total_qty'],
                $data['prize_desc'] ?? '',
                (int)$data['id']
            ]);
            return (int)$data['id'];
        }
        $stmt = $pdo->prepare("INSERT INTO prizes (level, name, value, images, color, sort, limited, total_qty, prize_desc) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $data['level'], $data['name'], $data['value'],
            json_encode($data['images'], JSON_UNESCAPED_UNICODE),
            $data['color'], (int)$data['sort'], (int)$data['limited'], (int)$data['total_qty'],
            $data['prize_desc'] ?? ''
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function deletePrize(int $id): void
    {
        self::pdo()->prepare("DELETE FROM prizes WHERE id = ?")->execute([$id]);
    }

    /** 限量奖品被抽完后用于兜底返回的奖品（优先“参与/谢谢”，否则取 sort 最大的） */
    public static function fallbackPrize(): ?array
    {
        $pdo = self::pdo();
        $row = $pdo->query("SELECT * FROM prizes WHERE name LIKE '%参与%' OR name LIKE '%谢谢%' OR level LIKE '%参与%' ORDER BY sort ASC LIMIT 1")->fetch();
        if ($row) {
            return self::decodePrize($row);
        }
        $row = $pdo->query("SELECT * FROM prizes ORDER BY sort DESC, id DESC LIMIT 1")->fetch();
        return $row ? self::decodePrize($row) : null;
    }

    /**
     * 原子占用一个奖品名额。
     * 仅当「不限量」或「限量且 used_qty < total_qty」时才 +1，返回是否成功占用。
     * 利用单条带条件 UPDATE 的原子性，避免多个用户同时抽中“仅剩 1 个”的奖项时超发。
     */
    public static function claimUnit(int $prizeId): bool
    {
        $stmt = self::pdo()->prepare(
            "UPDATE prizes SET used_qty = used_qty + 1 WHERE id = ? AND (limited = 0 OR used_qty < total_qty)"
        );
        $stmt->execute([$prizeId]);
        return $stmt->rowCount() > 0;
    }

    /** 找到一个仍有余量的奖项（不限量 或 限量未满），用于兜底链末端 */
    public static function getAnyAvailable(): ?array
    {
        $row = self::pdo()->query("SELECT * FROM prizes WHERE limited = 0 OR used_qty < total_qty ORDER BY sort ASC, id ASC LIMIT 1")->fetch();
        return $row ? self::decodePrize($row) : null;
    }

    // ---------- links ----------
    public static function createLink(string $title, int $totalDraws, array $drawsConfig): string
    {
        $token = self::genToken();
        $stmt = self::pdo()->prepare("INSERT INTO links (token, title, total_draws, draws_config, created_at) VALUES (?,?,?,?,?)");
        $stmt->execute([
            $token, $title, $totalDraws, json_encode($drawsConfig, JSON_UNESCAPED_UNICODE),
            date('Y-m-d H:i:s')
        ]);
        return $token;
    }

    public static function getLinkByToken(string $token): ?array
    {
        $stmt = self::pdo()->prepare("SELECT * FROM links WHERE token = ?");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['draws_config'] = json_decode($row['draws_config'], true) ?: [];
        $row['total_draws'] = (int)$row['total_draws'];
        return $row;
    }

    public static function allLinks(): array
    {
        $stmt = self::pdo()->query("SELECT * FROM links ORDER BY id DESC");
        return array_map(function ($r) {
            $r['draws_config'] = json_decode($r['draws_config'], true) ?: [];
            $r['total_draws'] = (int)$r['total_draws'];
            return $r;
        }, $stmt->fetchAll());
    }

    public static function deleteLink(int $id): void
    {
        $pdo = self::pdo();
        $pdo->prepare("DELETE FROM draws WHERE link_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM links WHERE id = ?")->execute([$id]);
    }

    public static function saveCustomer(int $linkId, string $name, string $amount): void
    {
        $stmt = self::pdo()->prepare("UPDATE links SET customer_name = ?, customer_amount = ? WHERE id = ?");
        $stmt->execute([$name, $amount, $linkId]);
    }

    // ---------- draws ----------
    public static function drawExists(int $linkId, int $index): bool
    {
        $stmt = self::pdo()->prepare("SELECT 1 FROM draws WHERE link_id = ? AND draw_index = ?");
        $stmt->execute([$linkId, $index]);
        return (bool)$stmt->fetch();
    }

    public static function getDraw(int $linkId, int $index): ?array
    {
        $stmt = self::pdo()->prepare("SELECT * FROM draws WHERE link_id = ? AND draw_index = ?");
        $stmt->execute([$linkId, $index]);
        return $stmt->fetch() ?: null;
    }

    public static function usedDraws(int $linkId): int
    {
        $stmt = self::pdo()->prepare("SELECT COUNT(*) FROM draws WHERE link_id = ?");
        $stmt->execute([$linkId]);
        return (int)$stmt->fetchColumn();
    }

    public static function linkDraws(int $linkId): array
    {
        $stmt = self::pdo()->prepare("SELECT * FROM draws WHERE link_id = ? ORDER BY draw_index ASC");
        $stmt->execute([$linkId]);
        return $stmt->fetchAll();
    }

    public static function recordDraw(int $linkId, int $index, int $prizeId): void
    {
        $stmt = self::pdo()->prepare("INSERT OR IGNORE INTO draws (link_id, draw_index, prize_id, drawn_at) VALUES (?,?,?,?)");
        $stmt->execute([$linkId, $index, $prizeId, date('Y-m-d H:i:s')]);
    }

    public static function incrementUsed(int $prizeId): void
    {
        self::pdo()->prepare("UPDATE prizes SET used_qty = used_qty + 1 WHERE id = ?")->execute([$prizeId]);
    }

    // ---------- helpers ----------
    private static function genToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function seedPrizes(): void
    {
        $defaults = [
            ['特等奖', 'iPhone 16 Pro', '价值 4000 元', '#8e2de2', 1, 1, 1],
            ['一等奖', '智能手表', '价值 1000 元', '#ff6b6b', 0, 5, 0],
            ['二等奖', '蓝牙耳机', '价值 500 元', '#feca57', 0, 20, 0],
            ['三等奖', '万圣节礼盒', '价值 200 元', '#1dd1a1', 0, 100, 0],
            ['参与奖', '神秘小礼物', '价值 50 元', '#54a0ff', 0, 9999, 0],
        ];
        $sort = 0;
        foreach ($defaults as $d) {
            self::pdo()->prepare("INSERT INTO prizes (level, name, value, images, color, sort, limited, total_qty) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$d[0], $d[1], $d[2], '[]', $d[3], $sort++, $d[4], $d[5]]);
        }
    }

    private static function defaultIntro(): string
    {
        return <<<TXT
万圣节抽奖须知
感谢您的支持！参与抽奖前，请仔细阅读以下说明：

一、活动时间
2026年10月30日00:00至11月1日23:59（韩国时间），共3天。

二、填写真实信息
请填写您的姓名及实际消费金额，金额单位为韩元。姓名须与订单配送收件人姓名一致；如不一致，中奖后不予配送奖品。

三、抽奖次数
消费满59,000韩元，可抽奖1次。
消费满118,000韩元，可抽奖2次。
消费满177,000韩元及以上，可抽奖3次。
不满59,000韩元暂不具备抽奖资格，每位客户最多可抽奖3次。
消费金额以工作人员核实的实际订单金额为准，同一笔消费不可重复领取抽奖机会。

四、特等奖限量1份
本次活动特等奖仅有1个名额，抽中后即从奖池移除，不再参与后续抽奖。

五、截图领取奖品
抽奖完成后，请截图保存完整抽奖结果，并发送给工作人员。工作人员核对姓名、订单及中奖记录后，安排奖品发放。

六、以系统记录为准
抽奖结果以系统实际记录为准。请勿冒用他人信息、虚报消费金额或重复领取奖品。
TXT;
    }
}
