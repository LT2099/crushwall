<?php
/**
 * 数据库初始化与公共函数
 * 使用 SQLite（PDO），无需额外数据库服务。
 */

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        // 高并发写保护：遇锁最多等待 30 秒（PHP pdo_sqlite 默认 60s；
        // 旧版 SQLite 3.7.17 WAL 并发写仍会偶发 SQLITE_BUSY，等待够久再报错）
        $pdo->exec('PRAGMA busy_timeout = 30000');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        // 进程内共享页缓存 8MB，减少磁盘读
        $pdo->exec('PRAGMA cache_size = -8000');
        initSchema($pdo);
        // 兼容旧表：补齐 anon_nickname 列
        $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('anon_nickname', $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN anon_nickname TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('avatar', $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('banned', $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN banned INTEGER NOT NULL DEFAULT 0");
        }
        // 兼容旧表：补齐 posts.ip 列
        $pcols = $pdo->query("PRAGMA table_info(posts)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('ip', $pcols, true)) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN ip TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：补齐 posts.video 列（视频帖）
        if (!in_array('video', $pcols, true)) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN video TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：补齐 posts.pinned 列（置顶帖）
        if (!in_array('pinned', $pcols, true)) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN pinned INTEGER NOT NULL DEFAULT 0");
        }
        // 兼容旧表：补齐 posts.vote_data 列（投票帖）
        if (!in_array('vote_data', $pcols, true)) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN vote_data TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：补齐 rate_limits.last_msg 列
        $rcols = $pdo->query("PRAGMA table_info(rate_limits)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('last_msg', $rcols, true)) {
            $pdo->exec("ALTER TABLE rate_limits ADD COLUMN last_msg TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('last_report', $rcols, true)) {
            $pdo->exec("ALTER TABLE rate_limits ADD COLUMN last_report TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('last_note', $rcols, true)) {
            $pdo->exec("ALTER TABLE rate_limits ADD COLUMN last_note TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：补齐 messages.content_type 列（私信图片/语音）
        $mcols = $pdo->query("PRAGMA table_info(messages)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('content_type', $mcols, true)) {
            $pdo->exec("ALTER TABLE messages ADD COLUMN content_type TEXT NOT NULL DEFAULT 'text'");
        }
        // 兼容旧表：补齐 users.score 列（积分）
        $ucols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('score', $ucols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN score INTEGER NOT NULL DEFAULT 0");
        }
        // 兼容旧表：补齐 users.vip_level 列（VIP 等级，>0 时覆盖普通等级显示，如站长 V100）
        if (!in_array('vip_level', $ucols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN vip_level INTEGER NOT NULL DEFAULT 0");
        }
        // 兼容旧表：补齐 users.signature 列（个性签名）
        if (!in_array('signature', $ucols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN signature TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：补齐 rate_limits.last_bottle / last_gift 列
        if (!in_array('last_bottle', $rcols, true)) {
            $pdo->exec("ALTER TABLE rate_limits ADD COLUMN last_bottle TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('last_gift', $rcols, true)) {
            $pdo->exec("ALTER TABLE rate_limits ADD COLUMN last_gift TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：补齐 rate_limits.last_room 列（话题房发言独立限速，聊天气氛更宽松）
        if (!in_array('last_room', $rcols, true)) {
            $pdo->exec("ALTER TABLE rate_limits ADD COLUMN last_room TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：补齐 rate_limits.last_capsule 列（时光胶囊）
        if (!in_array('last_capsule', $rcols, true)) {
            $pdo->exec("ALTER TABLE rate_limits ADD COLUMN last_capsule TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：补齐 rate_limits.last_anniv 列（纪念日）
        if (!in_array('last_anniv', $rcols, true)) {
            $pdo->exec("ALTER TABLE rate_limits ADD COLUMN last_anniv TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：补齐 rate_limits.last_log / last_checkin 列（恋爱实验室）
        if (!in_array('last_log', $rcols, true)) {
            $pdo->exec("ALTER TABLE rate_limits ADD COLUMN last_log TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('last_checkin', $rcols, true)) {
            $pdo->exec("ALTER TABLE rate_limits ADD COLUMN last_checkin TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('last_wish', $rcols, true)) {
            $pdo->exec("ALTER TABLE rate_limits ADD COLUMN last_wish TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：users 积分小卖部（avatar_frame 头像框永久 / nick_color+nick_color_until 昵称色 7 天）
        $ucols = [];
        $st = $pdo->query('PRAGMA table_info(users)');
        foreach ($st as $r) {
            $ucols[] = $r['name'];
        }
        if (!in_array('avatar_frame', $ucols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar_frame TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('nick_color', $ucols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN nick_color TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('nick_color_until', $ucols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN nick_color_until TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：每日盲盒（last_box 为上次开盒时间，服务端 24h 冷却依据）
        if (!in_array('last_box', $ucols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN last_box TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：气泡皮肤（盲盒产出，bubble_skin 为当前生效气泡样式）
        if (!in_array('bubble_skin', $ucols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN bubble_skin TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：盲盒称号（title 为当前生效称号的 css 类）
        if (!in_array('title', $ucols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN title TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：恋爱人格测试结果（love_type 为人格 key，如 ADR）
        if (!in_array('love_type', $ucols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN love_type TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：心动理由（crushes.reason，心动时附一句话，表白墙灵魂）
        $crcols = [];
        $st = $pdo->query('PRAGMA table_info(crushes)');
        foreach ($st as $r) {
            $crcols[] = $r['name'];
        }
        if (!in_array('reason', $crcols, true)) {
            $pdo->exec("ALTER TABLE crushes ADD COLUMN reason TEXT NOT NULL DEFAULT ''");
        }
        // 兼容旧表：comments 楼中楼（parent_id 挂顶层 / reply_to_id 直接回复对象 / like_count 评论点赞数）
        $ccols = [];
        $st = $pdo->query('PRAGMA table_info(comments)');
        foreach ($st as $r) {
            $ccols[] = $r['name'];
        }
        if (!in_array('parent_id', $ccols, true)) {
            $pdo->exec("ALTER TABLE comments ADD COLUMN parent_id INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('reply_to_id', $ccols, true)) {
            $pdo->exec("ALTER TABLE comments ADD COLUMN reply_to_id INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('like_count', $ccols, true)) {
            $pdo->exec("ALTER TABLE comments ADD COLUMN like_count INTEGER NOT NULL DEFAULT 0");
        }
        // 评论点赞表（防重复赞）
        $pdo->exec('CREATE TABLE IF NOT EXISTS comment_likes (
            comment_id INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT "",
            PRIMARY KEY (comment_id, user_id)
        )');
        // 预置礼物（幂等：空表才插入）
        global $GIFT_CATALOG;
        $giftCount = (int)$pdo->query('SELECT COUNT(*) FROM gifts')->fetchColumn();
        if ($giftCount === 0) {
            $st = $pdo->prepare('INSERT INTO gifts (name, icon, price) VALUES (?,?,?)');
            foreach ($GIFT_CATALOG as $g) {
                $st->execute([$g[0], $g[1], $g[2]]);
            }
        }
        // 预置盲盒物品池（幂等：空表才插入，概率权重以服务端配置为准）
        global $BLIND_BOX_POOL;
        $boxCount = (int)$pdo->query('SELECT COUNT(*) FROM blindbox_items')->fetchColumn();
        if ($boxCount === 0) {
            $st = $pdo->prepare('INSERT INTO blindbox_items (key_id, name, kind, rarity, weight, css, icon, desc) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($BLIND_BOX_POOL as $b) {
                $st->execute([$b['key'], $b['name'], $b['kind'], $b['rarity'], (int)$b['weight'], $b['css'], $b['icon'], $b['desc']]);
            }
        }
    }
    return $pdo;
}

/** 客户端 IP */
function clientIp(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** IP 是否被封禁 */
function isIpBanned(string $ip): bool
{
    if ($ip === '') {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM banned_ips WHERE ip = ?');
    $st->execute([$ip]);
    return (bool)$st->fetchColumn();
}

/** 用户是否被封禁 */
function isUserBanned(int $uid): bool
{
    $st = db()->prepare('SELECT banned FROM users WHERE id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn() === 1;
}

/** DDoS 自动防护：同一 IP 在 DDOS_WINDOW 秒内请求数超过 DDOS_THRESHOLD 自动永久封禁 */
function autoDdosGuard(): void
{
    $ip = clientIp();
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
        return;
    }
    $pdo = db();
    $win = (int)floor(time() / DDOS_WINDOW);
    // 高频写节流：同一进程内同一 IP 每 2 秒最多写一次计数。
    // PHP-FPM worker 常驻，static 变量跨请求保留；多个 worker 各写各的，
    // 计数是"至少"值，用于阈值判断已足够（DDoS 高频请求下很快触顶）。
    static $lastFlush = [];
    $now = microtime(true);
    if (!isset($lastFlush[$ip]) || ($now - $lastFlush[$ip]) >= 2.0) {
        $lastFlush[$ip] = $now;
        // 计数写合并为一条 INSERT OR IGNORE + UPDATE（避免并发首次访问时
        // "UPDATE 0 行 → 双方都 INSERT → 唯一约束冲突" 的竞态）
        dbExec(function () use ($pdo, $ip, $win): void {
            $pdo->prepare('INSERT OR IGNORE INTO request_limits (ip, win, hits) VALUES (?, ?, 0)')->execute([$ip, $win]);
            $pdo->prepare('UPDATE request_limits SET hits = hits + 1, win = ? WHERE ip = ?')->execute([$win, $ip]);
        });
    }
    // 读最新计数（读写分离：读是快照，不参与写锁）
    $st = $pdo->prepare('SELECT hits, win FROM request_limits WHERE ip = ?');
    $st->execute([$ip]);
    $r = $st->fetch();
    if ($r && (int)$r['win'] === $win && (int)$r['hits'] >= DDOS_THRESHOLD) {
        // 永久封禁（99 年等价）
        $reason = '自动防护封禁（疑似 DDoS）';
        $up2 = $pdo->prepare('UPDATE banned_ips SET reason=?, created_at=? WHERE ip=?');
        $up2->execute([$reason, now(), $ip]);
        if ($up2->rowCount() === 0) {
            $pdo->prepare('INSERT INTO banned_ips (ip, reason, created_at) VALUES (?,?,?)')
                ->execute([$ip, $reason, now()]);
        }
        fail('访问过于频繁，已被限制', 403);
    }
    // 清理过期窗口数据：窗口轮换时才执行一次（静态缓存记录最近清理的窗口），
    // 避免每个请求都 DELETE 造成写压力
    static $lastCleanWin = null;
    if ($lastCleanWin !== $win) {
        $lastCleanWin = $win;
        $pdo->prepare('DELETE FROM request_limits WHERE win < ?')->execute([$win - 2]);
    }
}

/** 匿名诗意昵称池：匿名发帖时对外展示的稳定昵称 */
$ANON_NICK_POOL = [
    '梧桐树下的同学', '晚风里的女生', '晨光中的少年', '雾霭里的行人', '星河边缘的人',
    '灯火阑珊处', '山茶花开的午后', '雨季的屋檐', '天台上的猫', '操场边的树',
    '图书馆的角落', '黄昏时分的云', '夜航星的旅人', '海岸线的风', '松林间的鹿',
    '雪地里的脚印', '月亮背面的人', '小巷深处的灯', '麦田守望者', '候鸟的翅膀',
    '溪流旁的石子', '路灯下的影子', '清晨的第一束光', '梦里见的人', '远方来信',
    '纸飞机航线', '萤火虫的夏夜', '樱花树下的约定', '栀子花的季节', '风铃响起的午后',
    '云朵收藏家', '星星邮差', '微风经过的街角', '雨后的彩虹', '薄荷色的天空',
    '柠檬味的夏天', '黄昏贩卖机', '日落收集者', '晚霞摄影师', '月光漫游者',
    '北极星的指引', '灯塔守望者', '沙丘上的行者', '热带鱼日记', '蓝鲸的歌声',
];

/** 根据稳定因子从昵称池取一个昵称 */
function anonNickFor(string $seed): string
{
    global $ANON_NICK_POOL;
    $h = 0;
    for ($i = 0; $i < strlen($seed); $i++) {
        $h = ($h * 31 + ord($seed[$i])) & 0x7fffffff;
    }
    return $ANON_NICK_POOL[$h % count($ANON_NICK_POOL)];
}

function initSchema(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  device_id     TEXT UNIQUE NOT NULL,
  nickname      TEXT NOT NULL,
  anon_nickname TEXT NOT NULL DEFAULT '',
  avatar        TEXT NOT NULL DEFAULT '',
  role          TEXT NOT NULL DEFAULT 'user',
  banned        INTEGER NOT NULL DEFAULT 0,
  score         INTEGER NOT NULL DEFAULT 0,
  vip_level     INTEGER NOT NULL DEFAULT 0,
  signature     TEXT NOT NULL DEFAULT '',
  avatar_frame  TEXT NOT NULL DEFAULT '',
  nick_color    TEXT NOT NULL DEFAULT '',
  nick_color_until TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL,
  last_active   TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS posts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL,
  content       TEXT NOT NULL,
  category      TEXT NOT NULL DEFAULT 'confession',
  is_anonymous  INTEGER NOT NULL DEFAULT 0,
  images        TEXT NOT NULL DEFAULT '[]',
  video         TEXT NOT NULL DEFAULT '',
  like_count    INTEGER NOT NULL DEFAULT 0,
  view_count    INTEGER NOT NULL DEFAULT 0,
  comment_count INTEGER NOT NULL DEFAULT 0,
  status        INTEGER NOT NULL DEFAULT 0,
  ip            TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS announcements (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  content    TEXT NOT NULL,
  created_at TEXT NOT NULL,
  status     INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS post_votes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  post_id    INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  option_idx INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE(post_id, user_id)
);

CREATE TABLE IF NOT EXISTS goods (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  title      TEXT NOT NULL,
  love_type  TEXT NOT NULL DEFAULT '',
  price      REAL NOT NULL DEFAULT 0,
  cond       TEXT NOT NULL DEFAULT '',
  category   TEXT NOT NULL DEFAULT '',
  desc       TEXT NOT NULL DEFAULT '',
  images     TEXT NOT NULL DEFAULT '[]',
  status     INTEGER NOT NULL DEFAULT 0,
  views      INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS goods_comments (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  goods_id   INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  content    TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS likes (

  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  post_id    INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE(post_id, user_id)
);
CREATE TABLE IF NOT EXISTS favorites (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  post_id    INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE(post_id, user_id)
);
CREATE TABLE IF NOT EXISTS comments (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  post_id     INTEGER NOT NULL,
  user_id     INTEGER NOT NULL,
  content     TEXT NOT NULL,
  status      INTEGER NOT NULL DEFAULT 0,
  parent_id   INTEGER NOT NULL DEFAULT 0,
  reply_to_id INTEGER NOT NULL DEFAULT 0,
  like_count  INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS friend_requests (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  from_user  INTEGER NOT NULL,
  to_user    INTEGER NOT NULL,
  message    TEXT NOT NULL DEFAULT '',
  status     INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS friends (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_a     INTEGER NOT NULL,
  user_b     INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE(user_a, user_b)
);
CREATE TABLE IF NOT EXISTS rate_limits (
  device_id TEXT PRIMARY KEY,
  last_post    TEXT NOT NULL DEFAULT '',
  last_comment TEXT NOT NULL DEFAULT '',
  last_like    TEXT NOT NULL DEFAULT '',
  last_msg     TEXT NOT NULL DEFAULT '',
  last_report  TEXT NOT NULL DEFAULT '',
  last_bottle  TEXT NOT NULL DEFAULT '',
  last_gift    TEXT NOT NULL DEFAULT '',
  last_room    TEXT NOT NULL DEFAULT '',
  last_note    TEXT NOT NULL DEFAULT '',
  last_capsule TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS banned_ips (
  ip         TEXT PRIMARY KEY,
  reason     TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS admin_login_attempts (
  ip         TEXT PRIMARY KEY,
  fails      INTEGER NOT NULL DEFAULT 0,
  lock_until TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS request_limits (
  ip   TEXT PRIMARY KEY,
  win  INTEGER NOT NULL DEFAULT 0,
  hits INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS messages (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  from_user    INTEGER NOT NULL,
  to_user      INTEGER NOT NULL,
  content      TEXT NOT NULL,
  content_type TEXT NOT NULL DEFAULT 'text',
  created_at   TEXT NOT NULL,
  read         INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS visits (
  owner_id   INTEGER NOT NULL,
  visitor_id INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  PRIMARY KEY (owner_id, visitor_id)
);
CREATE TABLE IF NOT EXISTS rate_logs (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  device_id  TEXT NOT NULL,
  kind       TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS notifications (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  actor_id   INTEGER NOT NULL,
  type       TEXT NOT NULL,
  post_id    INTEGER NOT NULL DEFAULT 0,
  content    TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  read       INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS reports (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  target_type TEXT NOT NULL,
  target_id   INTEGER NOT NULL,
  reporter_id INTEGER NOT NULL,
  reason      TEXT NOT NULL,
  note        TEXT NOT NULL DEFAULT '',
  status      INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS signins (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  date       TEXT NOT NULL,
  streak     INTEGER NOT NULL DEFAULT 1,
  score      INTEGER NOT NULL DEFAULT 5,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS crushes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  from_user  INTEGER NOT NULL,
  to_user    INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  reason     TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS questions (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  to_user    INTEGER NOT NULL,
  from_user  INTEGER NOT NULL DEFAULT 0,
  content    TEXT NOT NULL,
  reply      TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  replied_at TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS bottles (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  owner_id   INTEGER NOT NULL,
  content    TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS bottle_replies (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  bottle_id  INTEGER NOT NULL,
  owner_id   INTEGER NOT NULL,
  content    TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS bottle_picks (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  bottle_id  INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS gifts (
  id    INTEGER PRIMARY KEY AUTOINCREMENT,
  name  TEXT NOT NULL,
  icon  TEXT NOT NULL,
  price INTEGER NOT NULL
);
 CREATE TABLE IF NOT EXISTS gift_logs (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  from_user  INTEGER NOT NULL,
  to_user    INTEGER NOT NULL,
  post_id    INTEGER NOT NULL DEFAULT 0,
  gift_id    INTEGER NOT NULL,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS blindbox_items (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  key_id   TEXT UNIQUE NOT NULL,        -- 物品标识（与前端 CSS 映射，如 box_aurora）
  name     TEXT NOT NULL,               -- 物品名
  kind     TEXT NOT NULL DEFAULT 'frame',-- frame 头像框 | bubble 气泡 | title 称号 | score 积分
  rarity   TEXT NOT NULL DEFAULT 'common',-- common 普通 | rare 稀有 | epic 史诗 | legend 传说
  weight   INTEGER NOT NULL DEFAULT 10, -- 抽取权重（越大越容易出，服务端唯一概率来源）
  css      TEXT NOT NULL DEFAULT '',    -- 前端样式类/值（frame→af-xxx，color→色值）
  icon     TEXT NOT NULL DEFAULT '🎁',
  desc     TEXT NOT NULL DEFAULT ''
);
 CREATE TABLE IF NOT EXISTS blindbox_records (
   id         INTEGER PRIMARY KEY AUTOINCREMENT,
   user_id    INTEGER NOT NULL,
   item_key   TEXT NOT NULL,             -- 对应 blindbox_items.key_id
   created_at TEXT NOT NULL
 );
 CREATE INDEX IF NOT EXISTS idx_box_rec_user ON blindbox_records(user_id, id);
 CREATE INDEX IF NOT EXISTS idx_box_items_rarity ON blindbox_items(rarity, weight);
 CREATE INDEX IF NOT EXISTS idx_gift_from ON gift_logs(from_user);
 CREATE TABLE IF NOT EXISTS user_tasks (
   id         INTEGER PRIMARY KEY AUTOINCREMENT,
   user_id    INTEGER NOT NULL,
   task_date  TEXT NOT NULL,             -- Y-m-d
   task_id    TEXT NOT NULL,             -- sign/post/comment/like/box/goods/fortune/report/allbonus
   claimed_at TEXT,                      -- NULL = 已做未领，非空 = 已领
   UNIQUE (user_id, task_date, task_id)
 );
 CREATE INDEX IF NOT EXISTS idx_user_tasks ON user_tasks(user_id, task_date);
 CREATE TABLE IF NOT EXISTS secret_notes (
   id           INTEGER PRIMARY KEY AUTOINCREMENT,
   from_user    INTEGER NOT NULL,
   to_user      INTEGER NOT NULL,
   content      TEXT NOT NULL,
   suspects     TEXT NOT NULL,           -- JSON [uid1, uid2, uid3]，其中一个是 from_user
   status       TEXT NOT NULL DEFAULT 'pending',  -- pending 待猜 / guessed 猜错 / revealed 揭晓
   guessed_user INTEGER NOT NULL DEFAULT 0,
   created_at   TEXT NOT NULL
 );
  CREATE INDEX IF NOT EXISTS idx_notes_to ON secret_notes(to_user, status);
  CREATE INDEX IF NOT EXISTS idx_notes_from ON secret_notes(from_user, id);
  CREATE TABLE IF NOT EXISTS time_capsules (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    to_user      INTEGER NOT NULL DEFAULT 0,   -- 0=写给未来的自己，>0=写给指定用户
    content      TEXT NOT NULL,
    open_at      TEXT NOT NULL,                -- 到期开启时间（Y-m-d H:i:s）
    opened       INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT NOT NULL
  );
  CREATE INDEX IF NOT EXISTS idx_capsules_user ON time_capsules(user_id, id);
  CREATE INDEX IF NOT EXISTS idx_capsules_due ON time_capsules(opened, open_at);
  CREATE TABLE IF NOT EXISTS anniversaries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,              -- 创建者
    to_user    INTEGER NOT NULL DEFAULT 0,    -- 关联对方用户（0=仅自己可见）
    title      TEXT NOT NULL,                 -- 名称，如「在一起」「认识」
    date       TEXT NOT NULL,                 -- 日期（Y-m-d）
    created_at TEXT NOT NULL
  );
  CREATE INDEX IF NOT EXISTS idx_anniv_user ON anniversaries(user_id, id);
  CREATE TABLE IF NOT EXISTS love_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,              -- 记录者
    to_user    INTEGER NOT NULL DEFAULT 0,    -- 0=仅自己可见，>0=仅指定 TA 可见
    mood       TEXT NOT NULL DEFAULT 'happy', -- 心情：happy/love/shy/sad/sunny
    content    TEXT NOT NULL,
    created_at TEXT NOT NULL
  );
  CREATE INDEX IF NOT EXISTS idx_logs_user ON love_logs(user_id, id);
  CREATE TABLE IF NOT EXISTS couples (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_a       INTEGER NOT NULL,            -- 发起方
    user_b       INTEGER NOT NULL,            -- 接收方
    nick_a       TEXT NOT NULL DEFAULT '',    -- B 对 A 的昵称
    nick_b       TEXT NOT NULL DEFAULT '',    -- A 对 B 的昵称
    status       INTEGER NOT NULL DEFAULT 0,  -- 0 待确认 1 已绑定
    created_at   TEXT NOT NULL,
    bound_at     TEXT NOT NULL DEFAULT ''
  );
  CREATE INDEX IF NOT EXISTS idx_couples_a ON couples(user_a);
  CREATE INDEX IF NOT EXISTS idx_couples_b ON couples(user_b);
  CREATE TABLE IF NOT EXISTS love_tree (
    user_id    INTEGER PRIMARY KEY,           -- 一棵树属于发起绑定的一方
    couple_id  INTEGER NOT NULL DEFAULT 0,
    water      INTEGER NOT NULL DEFAULT 0,    -- 累计浇水次数
    level      INTEGER NOT NULL DEFAULT 1,    -- 成长等级
    last_water TEXT NOT NULL DEFAULT ''
  );
  CREATE TABLE IF NOT EXISTS quiz_daily (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,              -- 答题人
    qdate      TEXT NOT NULL,                 -- 题目日期（Y-m-d）
    answer     TEXT NOT NULL DEFAULT '',      -- 自己的答案
    q_a        INTEGER NOT NULL DEFAULT 0,    -- 题目题号（题库索引）
    created_at TEXT NOT NULL
  );
  CREATE INDEX IF NOT EXISTS idx_quiz_user ON quiz_daily(user_id, qdate);
  CREATE TABLE IF NOT EXISTS love_checkin (
    user_id      INTEGER PRIMARY KEY,
    streak       INTEGER NOT NULL DEFAULT 0,  -- 连续签到天数
    total        INTEGER NOT NULL DEFAULT 0,  -- 累计签到
    last_date    TEXT NOT NULL DEFAULT ''
  );
  CREATE TABLE IF NOT EXISTS wish_pool (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,              -- 许愿人
    content    TEXT NOT NULL,
    created_at TEXT NOT NULL
  );
  CREATE INDEX IF NOT EXISTS idx_wish_user ON wish_pool(user_id, id);
  CREATE TABLE IF NOT EXISTS love_vows (
    couple_id INTEGER PRIMARY KEY,
    vow_a     TEXT NOT NULL DEFAULT '',       -- A 的誓言（用户A 视角）
    vow_b     TEXT NOT NULL DEFAULT '',       -- B 的誓言
    updated_at TEXT NOT NULL DEFAULT ''
  );
  CREATE TABLE IF NOT EXISTS achievements (
    user_id     INTEGER PRIMARY KEY,
    ach_data    TEXT NOT NULL DEFAULT '[]',   -- JSON: 已解锁徽章 key 数组
    updated_at  TEXT NOT NULL DEFAULT ''
  );
SQL
    );
}

/* ================= 响应与工具 ================= */

function ok($data = null, string $msg = 'ok'): void
{
    jsonOut(0, $msg, $data);
}

function fail(string $msg, int $code = 1): void
{
    jsonOut($code, $msg, null);
}

function jsonOut(int $code, string $msg, $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * SQLite 写重试：旧版 SQLite（如 CentOS 7 的 3.7.17）WAL 并发写偶发
 * SQLITE_BUSY(5)，即便设置了 busy_timeout 仍可能失败。
 * 对高频写调用包装此函数，捕获 5 号错误后退避重试，避免整请求 500。
 * 临时方案：升级系统 libsqlite3（≥3.30）后此重试几乎不再触发，可保留作为兜底。
 */
function dbExec(callable $fn, int $retries = 3): mixed
{
    $delay = 20000;
    for ($i = 0; ; $i++) {
        try {
            return $fn();
        } catch (PDOException $e) {
            $isBusy = strpos((string)$e->getMessage(), 'database is locked') !== false
                   || (int)($e->errorInfo[1] ?? 0) === 5;
            if (!$isBusy || $i >= $retries) {
                throw $e;
            }
            usleep($delay);
            $delay *= 2;
        }
    }
}

/** 校验并返回 device_id，不合法直接失败 */
function requireDeviceId(): string
{
    $did = trim((string)($_POST['device_id'] ?? $_GET['device_id'] ?? ''));
    if ($did === '' || strlen($did) < 8 || strlen($did) > 128 || !preg_match('/^[A-Za-z0-9_\-\.]+$/', $did)) {
        fail('设备标识无效', 401);
    }
    return $did;
}

/** 获取/创建用户，返回用户行 */
function getOrCreateUser(string $deviceId): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM users WHERE device_id = ?');
    $st->execute([$deviceId]);
    $u = $st->fetch();
    if ($u) {
        // last_active 降频更新：同一进程内同一用户每 5 分钟最多写一次，
        // 消除"每个请求都 UPDATE"造成的写锁压力（多 worker 合计每分钟约 2 次/用户）
        static $lastActiveFlush = [];
        $uid = (int)$u['id'];
        $ts = time();
        if (!isset($lastActiveFlush[$uid]) || ($ts - $lastActiveFlush[$uid]) >= 300) {
            $lastActiveFlush[$uid] = $ts;
            dbExec(function () use ($pdo, $uid): void {
                $pdo->prepare('UPDATE users SET last_active = ? WHERE id = ?')
                    ->execute([now(), $uid]);
            });
        }
        // 老用户补匿名昵称
        if (empty($u['anon_nickname'])) {
            $anon = anonNickFor($deviceId);
            dbExec(function () use ($pdo, $anon, $uid): void {
                $pdo->prepare('UPDATE users SET anon_nickname = ? WHERE id = ?')->execute([$anon, $uid]);
            });
            $u['anon_nickname'] = $anon;
        }
        return $u;
    }
    $nick = '用户' . strtoupper(substr(hash('md5', $deviceId . now()), 0, 6));
    $anon = anonNickFor($deviceId);
    dbExec(function () use ($pdo, $deviceId, $nick, $anon): void {
        $pdo->prepare('INSERT INTO users (device_id, nickname, anon_nickname, role, created_at, last_active) VALUES (?,?,?,?,?,?)')
            ->execute([$deviceId, $nick, $anon, 'user', now(), now()]);
    });
    $id = (int)$pdo->lastInsertId();
    return ['id' => $id, 'device_id' => $deviceId, 'nickname' => $nick, 'anon_nickname' => $anon,
            'avatar' => '', 'role' => 'user', 'banned' => 0, 'score' => 0, 'vip_level' => 0,
            'signature' => '', 'avatar_frame' => '', 'nick_color' => '',
            'nick_color_until' => '', 'created_at' => now(), 'last_active' => now()];
}

/** 频率限制：$field 对应 rate_limits 列 */
function checkRate(string $deviceId, string $field, int $seconds): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT " . $field . " FROM rate_limits WHERE device_id = ?");
    $st->execute([$deviceId]);
    $last = (string)$st->fetchColumn();
    if ($last !== '' && strtotime($last) + $seconds > time()) {
        fail('操作太快，请稍后再试');
    }
    // 兼容旧版 SQLite（不支持 ON CONFLICT/UPSERT）：先更新，未命中再插入
    $up = $pdo->prepare('UPDATE rate_limits SET ' . $field . ' = ? WHERE device_id = ?');
    $up->execute([now(), $deviceId]);
    if ($up->rowCount() === 0) {
        $pdo->prepare('INSERT INTO rate_limits (device_id, ' . $field . ') VALUES (?, ?)')
            ->execute([$deviceId, now()]);
    }
}

/* ================= 防刷屏：连续次数 + 重复内容 ================= */

/** 频率总量限制：FLOOD_WINDOW 秒内累计发言（发帖/评论/私信）≥ FLOOD_MAX 次拒绝（防连续刷屏） */
function guardFlood(string $deviceId): void
{
    $st = db()->prepare('SELECT COUNT(*) c FROM rate_logs WHERE device_id=? AND created_at >= ?');
    $st->execute([$deviceId, date('Y-m-d H:i:s', time() - FLOOD_WINDOW)]);
    if ((int)$st->fetchColumn() >= FLOOD_MAX) {
        fail('操作太频繁，请稍后再试');
    }
}

/** 记录一次成功发言，用于频率统计；顺带清理过期日志 */
function logAction(string $deviceId, string $kind): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM rate_logs WHERE created_at < ?')
        ->execute([date('Y-m-d H:i:s', time() - FLOOD_CLEAN)]);
    $pdo->prepare('INSERT INTO rate_logs (device_id, kind, created_at) VALUES (?,?,?)')
        ->execute([$deviceId, $kind, now()]);
}

/** 重复内容拦截：与本人最近 DUP_CHECK_N 条发言（发帖/评论/私信，去空白后）完全相同则拒绝 */
function guardDuplicate(int $userId, string $content): void
{
    $n = preg_replace('/\s+/u', '', trim($content));
    if ($n === '') {
        return;
    }
    $st = db()->prepare(
        "SELECT content FROM (
            SELECT content, created_at FROM posts    WHERE user_id=? AND status=0
            UNION ALL
            SELECT content, created_at FROM comments WHERE user_id=? AND status=0
            UNION ALL
            SELECT content, created_at FROM messages WHERE from_user=?
        ) ORDER BY created_at DESC LIMIT " . (int)DUP_CHECK_N
    );
    $st->execute([$userId, $userId, $userId]);
    foreach ($st->fetchAll() as $row) {
        $prev = preg_replace('/\s+/u', '', (string)$row['content']);
        if ($prev !== '' && $prev === $n) {
            fail('内容重复，请勿刷屏');
        }
    }
}

/* ================= 互动通知 ================= */

/** 发互动通知：type = post_like | post_comment，content 为评论内容（点赞时为空）；自己触发自己的不通知 */
function notifyPostOwner(int $postOwnerId, int $actorId, string $type, int $postId, string $content = ''): void
{
    if ($postOwnerId <= 0 || $postOwnerId === $actorId) {
        return;
    }
    $pdo = db();
    // 同一人对同一帖的点赞通知去重（取消赞再点只刷新时间，不刷屏）；评论每次新内容都通知
    if ($type === 'post_like') {
        $st = $pdo->prepare('SELECT id FROM notifications WHERE user_id=? AND actor_id=? AND post_id=? AND type=?');
        $st->execute([$postOwnerId, $actorId, $postId, $type]);
        $nid = (int)$st->fetchColumn();
        if ($nid > 0) {
            $pdo->prepare('UPDATE notifications SET created_at=? WHERE id=?')->execute([now(), $nid]);
            return;
        }
    }
    $pdo->prepare('INSERT INTO notifications (user_id, actor_id, type, post_id, content, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$postOwnerId, $actorId, $type, $postId, $content, now()]);
}

/** 通用互动通知：type = crush_match | question_reply | bottle_reply | post_gift；$dedupe 时同(接收者,触发者,类型)只刷新时间 */
function notifyUser(int $toUserId, int $actorId, string $type, int $postId = 0, string $content = '', bool $dedupe = false): void
{
    if ($toUserId <= 0 || $toUserId === $actorId) {
        return;
    }
    $pdo = db();
    if ($dedupe) {
        $st = $pdo->prepare('SELECT id FROM notifications WHERE user_id=? AND actor_id=? AND type=?');
        $st->execute([$toUserId, $actorId, $type]);
        $nid = (int)$st->fetchColumn();
        if ($nid > 0) {
            $pdo->prepare('UPDATE notifications SET created_at=? WHERE id=?')->execute([now(), $nid]);
            return;
        }
    }
    $pdo->prepare('INSERT INTO notifications (user_id, actor_id, type, post_id, content, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$toUserId, $actorId, $type, $postId, $content, now()]);
}

/* ================= 积分与等级 ================= */

/** 等级阶梯（升序） */
const LEVEL_THRESHOLDS = [0, 50, 150, 300, 500, 800, 1200, 1700, 2300, 3000];

/** 计算等级：返回 [level, current 段起点, next 段起点] */
function levelOf(int $score): array
{
    $lvl = 1;
    $cur = 0;
    $next = LEVEL_THRESHOLDS[1];
    foreach (LEVEL_THRESHOLDS as $i => $t) {
        if ($score >= $t) {
            $lvl = $i + 1;
            $cur = $t;
        }
    }
    $next = ($lvl < count(LEVEL_THRESHOLDS)) ? LEVEL_THRESHOLDS[$lvl] : -1;
    return [$lvl, $cur, $next];
}

/** 用户等级：VIP（vip_level>0，如站长 V100）直接返回 VIP 等级，否则按积分阶梯计算 */
function levelOfUser(int $userId, int $score): array
{
    if ($userId > 0) {
        $st = db()->prepare('SELECT vip_level FROM users WHERE id=?');
        $st->execute([$userId]);
        $vip = (int)$st->fetchColumn();
        if ($vip > 0) {
            return [$vip, $score, 0];
        }
    }
    return levelOf($score);
}

/** 给用户加积分（不落日志，防负数） */
function addScore(int $userId, int $points): void
{
    if ($points === 0 || $userId <= 0) {
        return;
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT score FROM users WHERE id=?');
    $st->execute([$userId]);
    $cur = (int)$st->fetchColumn();
    $new = max(0, $cur + $points);
    $pdo->prepare('UPDATE users SET score=? WHERE id=?')->execute([$new, $userId]);
}

/** 帖子收到的礼物摘要：最多 6 个 + 总数 */
function giftSummary(int $postId): array
{
    $pdo = db();
    $st = $pdo->prepare(
        'SELECT g.icon, g.name, COUNT(*) AS n FROM gift_logs l JOIN gifts g ON g.id = l.gift_id
         WHERE l.post_id=? GROUP BY l.gift_id ORDER BY l.id DESC LIMIT 6'
    );
    $st->execute([$postId]);
    $items = array_map(static function ($r) {
        return ['icon' => (string)$r['icon'], 'name' => (string)$r['name'], 'n' => (int)$r['n']];
    }, $st->fetchAll());
    $st = $pdo->prepare('SELECT COUNT(*) FROM gift_logs WHERE post_id=?');
    $st->execute([$postId]);
    return ['items' => $items, 'total' => (int)$st->fetchColumn()];
}

/* ================= 管理员 token ================= */

function issueAdminToken(string $deviceId): string
{
    $ts = time();
    $sig = hash_hmac('sha256', $deviceId . '|' . $ts, ADMIN_SECRET);
    return $deviceId . '.' . $ts . '.' . $sig;
}

function verifyAdminToken(?string $token): bool
{
    if (!$token) {
        return false;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    [$did, $ts, $sig] = $parts;
    if (!is_numeric($ts) || (time() - (int)$ts) > 12 * 3600) {
        return false; // 12 小时有效
    }
    if (!hash_equals(hash_hmac('sha256', $did . '|' . $ts, ADMIN_SECRET), $sig)) {
        return false;
    }
    // 该设备必须是 super 角色
    $pdo = db();
    $st = $pdo->prepare('SELECT role FROM users WHERE device_id = ?');
    $st->execute([$did]);
    return $st->fetchColumn() === 'super';
}

/* ================= 帖子/用户输出格式化 ================= */

/** 投票数据（含统计与当前用户选择） */
function voteData(int $postId, int $viewerId): ?array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT vote_data FROM posts WHERE id=?');
    $st->execute([$postId]);
    $raw = $st->fetchColumn();
    if (!$raw) {
        return null;
    }
    $vd = json_decode((string)$raw, true);
    if (!is_array($vd) || empty($vd['options']) || !is_array($vd['options'])) {
        return null;
    }
    $options = array_values(array_map('strval', $vd['options']));
    $title = trim((string)($vd['title'] ?? ''));
    if ($title === '') {
        $title = '来投票吧';
    }
    $st = $pdo->prepare('SELECT option_idx, COUNT(*) FROM post_votes WHERE post_id=? GROUP BY option_idx');
    $st->execute([$postId]);
    $counts = array_fill(0, count($options), 0);
    $total = 0;
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
        $counts[(int)$row[0]] = (int)$row[1];
        $total += (int)$row[1];
    }
    $mine = null;
    if ($viewerId > 0) {
        $st = $pdo->prepare('SELECT option_idx FROM post_votes WHERE post_id=? AND user_id=?');
        $st->execute([$postId, $viewerId]);
        $m = $st->fetchColumn();
        if ($m !== false) {
            $mine = (int)$m;
        }
    }
    return ['title' => $title, 'options' => $options, 'counts' => $counts, 'total' => $total, 'mine' => $mine];
}

/** 序列化帖子（对外输出，处理匿名与敏感字段）
 *  $viewer 为 null（管理端）时 $forceReal=true 展示真实昵称。
 */
function formatPost(array $p, ?array $viewer, bool $forceReal = false): array
{
    $isMine = $viewer && (int)$p['user_id'] === (int)$viewer['id'];
    $anon = (int)$p['is_anonymous'] === 1;
    $nickname = $p['nickname'] ?? '匿名用户';
    if ($anon && !$isMine && !$forceReal) {
        $nickname = !empty($p['anon_nickname']) ? $p['anon_nickname'] : '匿名用户';
    }
    $gifts = giftSummary((int)$p['id']);
    return [
        'id'            => (int)$p['id'],
        'content'       => (string)$p['content'],
        'category'      => (string)$p['category'],
        'is_anonymous'  => $anon,
        // 作者 user_id：非匿名帖开放（供心动/提问/礼物互动），匿名帖仅后台（forceReal）可见
        'user_id'       => (!$anon || $forceReal) ? (int)$p['user_id'] : 0,
        'images'        => json_decode((string)($p['images'] ?? '[]'), true) ?: [],
        'video'         => (string)($p['video'] ?? ''),
        'like_count'    => (int)$p['like_count'],
        'view_count'    => (int)$p['view_count'],
        'comment_count' => (int)$p['comment_count'],
        'created_at'    => (string)$p['created_at'],
        'gifts'         => $gifts,
        // 热度（吸引眼球的 🔥 徽章）：点赞 ×2 + 礼物 ×5 + 浏览 ÷5
        'heat'          => (int)$p['like_count'] * 2 + $gifts['total'] * 5 + (int)($p['view_count'] ?? 0) / 5,
        'nickname'      => $nickname,
        // 匿名帖头像也展示（站主需求：别人能看到你上传的头像）；昵称/device_id 仍匿名隐藏
        'avatar'        => (string)($p['avatar'] ?? ''),
        'avatar_frame'  => (string)($p['avatar_frame'] ?? ''),
        'nick_color'    => (string)($p['nick_color'] ?? ''),
        'title'         => (string)($p['title'] ?? ''),
        'bubble_skin'   => (string)($p['bubble_skin'] ?? ''),
        'is_mine'       => $isMine,
        'liked'         => (bool)($p['liked'] ?? false),
        'favorited'     => (bool)($p['favorited'] ?? false),
        // 匿名帖对他人隐藏 device_id，防止身份泄露
        'device_id'     => ($anon && !$isMine) ? '' : (string)($p['device_id'] ?? ''),
        // IP 仅后台（forceReal）可见
        'ip'            => $forceReal ? (string)($p['ip'] ?? '') : '',
        'pinned'        => (int)($p['pinned'] ?? 0),
        'vote'          => voteData((int)$p['id'], $viewer ? (int)$viewer['id'] : 0),
    ];
}

/** 序列化用户（对外输出） */
function formatUser(array $u): array
{
    return [
        'id'         => (int)$u['id'],
        'device_id'  => (string)$u['device_id'],
        'nickname'   => (string)$u['nickname'],
        'avatar'     => (string)($u['avatar'] ?? ''),
        'signature'  => (string)($u['signature'] ?? ''),
        'role'       => (string)$u['role'],
        'banned'     => (int)($u['banned'] ?? 0) === 1,
        'created_at' => (string)$u['created_at'],
    ];
}
