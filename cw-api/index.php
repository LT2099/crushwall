<?php
/**
 * 蓝天星球 统一 API 入口
 * 所有接口返回 JSON: {code, msg, data}
 * code=0 表示成功。
 */

require_once __DIR__ . '/db.php';

// DDoS 自动防护：高频请求 IP 自动永久封禁（前置拦截）
autoDdosGuard();

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

/* ---------------- 公开：访客信息 ---------------- */

if ($action === 'visitor.info') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $pdo = db();
    // 聚合统计收敛为一条 SQL（减少 PHP↔SQLite 往返与锁竞争）
    $st = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM posts WHERE user_id=:uid AND status=0) AS post_count,
            (SELECT COUNT(*) FROM comments WHERE user_id=:uid AND status=0) AS comment_count,
            (SELECT COUNT(*) FROM friends WHERE user_a=:uid OR user_b=:uid) AS friend_count,
            (SELECT COUNT(*) FROM friend_requests WHERE to_user=:uid AND status=0) AS new_friend_reqs,
            (SELECT COUNT(*) FROM favorites WHERE user_id=:uid) AS fav_count"
    );
    $st->execute([':uid' => $u['id']]);
    $agg = $st->fetch();
    $postCount = (int)$agg['post_count'];
    $commentCount = (int)$agg['comment_count'];
    $friendCount = (int)$agg['friend_count'];
    $newFriendReqs = (int)$agg['new_friend_reqs'];
    $favCount = (int)$agg['fav_count'];
    $st = $pdo->prepare('SELECT id, streak FROM signins WHERE user_id=? AND date=?');
    $st->execute([$u['id'], date('Y-m-d')]);
    $signRow = $st->fetch();
    [$lvl, $cur, $next] = levelOfUser((int)$u["id"], (int)$u["score"]);
    ok([
        'id'            => (int)$u['id'],
        'device_id'     => $u['device_id'],
        'nickname'      => $u['nickname'],
        'avatar'        => (string)($u['avatar'] ?? ''),
        'avatar_frame'  => (string)($u['avatar_frame'] ?? ''),
        'nick_color'    => (string)($u['nick_color'] ?? ''),
        'title'         => (string)($u['title'] ?? ''),
        'bubble_skin'   => (string)($u['bubble_skin'] ?? ''),
        'signature'     => (string)($u['signature'] ?? ''),
        'role'          => $u['role'],
        'post_count'    => $postCount,
        'comment_count' => $commentCount,
        'friend_count'  => $friendCount,
        'fav_count'     => $favCount,
        'new_friend_reqs' => $newFriendReqs,
        'created_at'    => $u['created_at'],
        'score'         => (int)$u['score'],
        'level'         => $lvl,
        'next_need'     => $next > 0 ? $next - (int)$u['score'] : 0,
        'checked'       => (bool)$signRow,
        'streak'        => $signRow ? (int)$signRow['streak'] : 0,
        'love_type'     => (string)($u['love_type'] ?? ''),
    ]);
}

if ($action === 'visits.list') {
    $u = getOrCreateUser(requireDeviceId());
    $st = db()->prepare(
        "SELECT u.nickname, u.avatar, u.signature, v.created_at
         FROM visits v JOIN users u ON u.id = v.visitor_id
         WHERE v.owner_id = ? ORDER BY v.created_at DESC LIMIT 20"
    );
    $st->execute([$u['id']]);
    $list = array_map(function ($v) {
        return [
            'nickname'   => (string)$v['nickname'],
            'avatar'     => (string)$v['avatar'],
            'signature'  => (string)($v['signature'] ?? ''),
            'created_at' => (string)$v['created_at'],
        ];
    }, $st->fetchAll());
    ok(['list' => $list]);
}

if ($action === 'visitors.online') {
    requireDeviceId();
    $pdo = db();
    $cut = date('Y-m-d H:i:s', time() - 300);
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE last_active >= ?');
    $st->execute([$cut]);
    $online = (int)$st->fetchColumn();
    // 返回在线用户明细（最近活跃前 30），供前端"在线围观"展开查看
    $st = $pdo->prepare('SELECT nickname, avatar, signature, last_active FROM users WHERE last_active >= ? ORDER BY last_active DESC LIMIT 30');
    $st->execute([$cut]);
    $list = [];
    foreach ($st->fetchAll() as $r) {
        $list[] = [
            'nickname' => (string)$r['nickname'],
            'avatar' => (string)($r['avatar'] ?? ''),
            'signature' => (string)($r['signature'] ?? ''),
            'last_active' => (string)$r['last_active'],
        ];
    }
    ok(['online' => $online, 'list' => $list]);
}

if ($action === 'visitor.avatar') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $avatar = trim((string)($_POST['avatar'] ?? ''));
    if ($avatar !== '' && !preg_match('#^uploads/avatars/[A-Za-z0-9_\-\.]+\.(jpg|jpeg|png|gif|webp)$#', $avatar)) {
        fail('头像路径不合法');
    }
    db()->prepare('UPDATE users SET avatar=? WHERE id=?')->execute([$avatar, $u['id']]);
    ok(['avatar' => $avatar], $avatar !== '' ? '头像已更新' : '已恢复默认头像');
}

if ($action === 'visitor.update') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    // 个性签名（30 字内，留空清除）
    if (isset($_POST['signature'])) {
        $sig = trim((string)$_POST['signature']);
        if (mb_strlen($sig) > 30) {
            fail('签名长度需在 30 字以内');
        }
        db()->prepare('UPDATE users SET signature=? WHERE id=?')->execute([$sig, $u['id']]);
        ok(['signature' => $sig]);
    }
    $nick = trim((string)($_POST['nickname'] ?? ''));
    $len = mb_strlen($nick);
    if ($len < 1 || $len > 16) {
        fail('昵称长度需为 1-16 个字符');
    }
    db()->prepare('UPDATE users SET nickname=? WHERE id=?')->execute([$nick, $u['id']]);
    ok(['nickname' => $nick]);
}

/* ---------------- 签到积分 ---------------- */

if ($action === 'sign.status') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $pdo = db();
    $today = date('Y-m-d');
    $st = $pdo->prepare('SELECT id, streak FROM signins WHERE user_id=? AND date=?');
    $st->execute([$u['id'], $today]);
    $row = $st->fetch();
    [$lvl, $cur, $next] = levelOfUser((int)$u["id"], (int)$u["score"]);
    ok([
        'checked'   => (bool)$row,
        'streak'    => $row ? (int)$row['streak'] : 0,
        'score'     => (int)$u['score'],
        'level'     => $lvl,
        'next_need' => $next > 0 ? $next - (int)$u['score'] : 0,
    ]);
}

if ($action === 'sign.do') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $pdo = db();
    $today = date('Y-m-d');
    $yday = date('Y-m-d', strtotime('-1 day'));
    // 今日是否已签（先查后写，SQLite 3.7 兼容）
    $st = $pdo->prepare('SELECT id FROM signins WHERE user_id=? AND date=?');
    $st->execute([$u['id'], $today]);
    if ($st->fetch()) {
        fail('今天已经签到啦，明天再来吧');
    }
    $st = $pdo->prepare('SELECT streak FROM signins WHERE user_id=? AND date=?');
    $st->execute([$u['id'], $yday]);
    $prevStreak = (int)$st->fetchColumn();
    $streak = ($prevStreak > 0) ? $prevStreak + 1 : 1;
    $gained = 5 + min(10, $streak - 1);
    $pdo->prepare('INSERT INTO signins (user_id, date, streak, score, created_at) VALUES (?,?,?,?,?)')
        ->execute([$u['id'], $today, $streak, $gained, now()]);
    addScore((int)$u['id'], $gained);
    // 重新读取最新积分
    $st = $pdo->prepare('SELECT score FROM users WHERE id=?');
    $st->execute([$u['id']]);
    $score = (int)$st->fetchColumn();
    [$lvl, $cur, $next] = levelOfUser((int)$u["id"], $score);
    ok(['gained' => $gained, 'streak' => $streak, 'score' => $score, 'level' => $lvl, 'next_need' => $next > 0 ? $next - $score : 0], '签到成功 +' . $gained . ' 积分');
}

/* ---------------- 每日盲盒 ---------------- */

/** 盲盒物品池读取（服务端配置，含概率权重） */
function boxPool(): array
{
    static $pool = null;
    if ($pool === null) {
        $pool = db()->query('SELECT id, key_id, name, kind, rarity, weight, css, icon, desc AS d FROM blindbox_items')->fetchAll();
    }
    return $pool;
}

/** 按权重抽一个物品（服务端概率，前端不可伪造） */
function boxDrawItem(): array
{
    $pool = boxPool();
    $total = 0;
    foreach ($pool as $it) {
        $total += (int)$it['weight'];
    }
    $roll = mt_rand(1, max(1, $total));
    foreach ($pool as $it) {
        $roll -= (int)$it['weight'];
        if ($roll <= 0) {
            return $it;
        }
    }
    return $pool[count($pool) - 1];
}

/** 用户盲盒状态：物品图鉴（数量）+ 当前装备 + 冷却剩余 */
function boxStatus(int $userId): array
{
    $pool = boxPool();
    $pdo = db();
    // 各物品获得次数
    $cnt = [];
    $st = $pdo->prepare('SELECT item_key, COUNT(*) c FROM blindbox_records WHERE user_id=? GROUP BY item_key');
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $r) {
        $cnt[$r['item_key']] = (int)$r['c'];
    }
    // 获取用户当前装备
    $st = $pdo->prepare('SELECT avatar_frame, bubble_skin, title FROM users WHERE id=?');
    $st->execute([$userId]);
    $usr = $st->fetch();
    $items = array_map(function ($it) use ($cnt) {
        return [
            'key' => $it['key_id'], 'name' => $it['name'], 'kind' => $it['kind'],
            'rarity' => $it['rarity'], 'css' => $it['css'], 'icon' => $it['icon'],
            'desc' => $it['d'], 'count' => $cnt[$it['key_id']] ?? 0,
        ];
    }, $pool);
    return [
        'items' => $items,
        'equipped' => [
            'frame'  => (string)($usr['avatar_frame'] ?? ''),
            'bubble' => (string)($usr['bubble_skin'] ?? ''),
            'title'  => (string)($usr['title'] ?? ''),
        ],
    ];
}

if ($action === 'box.status') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    // 冷却剩余（服务端时间为准）
    $st = $pdo->prepare('SELECT last_box FROM users WHERE id=?');
    $st->execute([$u['id']]);
    $lastBox = (string)$st->fetchColumn();
    $remain = 0;
    if ($lastBox !== '') {
        $remain = max(0, BOX_COOLDOWN - (time() - strtotime($lastBox)));
    }
    $st = $pdo->prepare('SELECT score FROM users WHERE id=?');
    $st->execute([$u['id']]);
    $score = (int)$st->fetchColumn();
    ok([
        'status'   => boxStatus((int)$u['id']),
        'remain'   => $remain,
        'cooldown' => BOX_COOLDOWN,
        'score'    => $score,
        'price'    => BOX_PRICE,
    ]);
}

if ($action === 'box.draw') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $uid = (int)$u['id'];
    $paid = (int)($_POST['paid'] ?? 0) === 1;
    if ($paid) {
        // 付费抽：原子扣积分（不占用免费冷却，可连抽；score>=price 条件防并发透支）
        $deduct = $pdo->prepare('UPDATE users SET score = score - ? WHERE id = ? AND score >= ?');
        $deduct->execute([BOX_PRICE, $uid, BOX_PRICE]);
        if ($deduct->rowCount() === 0) {
            $cur = (int)$pdo->query('SELECT score FROM users WHERE id=' . $uid)->fetchColumn();
            fail('积分不足，还差 ' . max(0, BOX_PRICE - $cur) . ' 分', 402);
        }
    } else {
        // 免费抽：原子占位先抢 last_box，未命中则处于冷却中（防并发双抽）
        $cutoff = date('Y-m-d H:i:s', time() - BOX_COOLDOWN);
        $claim = $pdo->prepare("UPDATE users SET last_box = ? WHERE id = ? AND (last_box = '' OR last_box <= ?)");
        $claim->execute([now(), $uid, $cutoff]);
        if ($claim->rowCount() === 0) {
            // 读当前冷却剩余返回
            $st = $pdo->prepare('SELECT last_box FROM users WHERE id=?');
            $st->execute([$uid]);
            $lastBox = (string)$st->fetchColumn();
            $remain = $lastBox === '' ? 0 : max(0, BOX_COOLDOWN - (time() - strtotime($lastBox)));
            fail('盲盒还在冷却中，' . $remain . ' 秒后再来', 429);
        }
    }
    // 服务端抽奖
    $item = boxDrawItem();
    // 记录抽取
    $pdo->prepare('INSERT INTO blindbox_records (user_id, item_key, created_at) VALUES (?,?,?)')
        ->execute([$uid, $item['key_id'], now()]);
    // 应用奖励
    $kind = $item['kind'];
    if ($kind === 'frame') {
        $pdo->prepare('UPDATE users SET avatar_frame=? WHERE id=?')->execute([$item['key_id'], $uid]);
    } elseif ($kind === 'bubble') {
        $pdo->prepare('UPDATE users SET bubble_skin=? WHERE id=?')->execute([$item['css'], $uid]);
    } elseif ($kind === 'title') {
        $pdo->prepare('UPDATE users SET title=? WHERE id=?')->execute([$item['css'], $uid]);
    } elseif ($kind === 'score') {
        addScore($uid, (int)$item['css']);
    }
    // 返回结果 + 最新图鉴
    ok([
        'item' => [
            'key' => $item['key_id'], 'name' => $item['name'], 'kind' => $item['kind'],
            'rarity' => $item['rarity'], 'css' => $item['css'], 'icon' => $item['icon'],
            'desc' => $item['d'],
        ],
        'status' => boxStatus($uid),
        'score'  => (int)$pdo->query('SELECT score FROM users WHERE id=' . $uid)->fetchColumn(),
    ], '开出：' . $item['name']);
}

// 手动装备/切换盲盒物品（头像框/气泡/称号），仅限已拥有的物品
if ($action === 'box.equip') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $uid = (int)$u['id'];
    $key = trim((string)($_POST['item_key'] ?? ''));
    if ($key === '') {
        fail('缺少物品标识', 400);
    }
    $pool = boxPool();
    $item = null;
    foreach ($pool as $it) {
        if ($it['key_id'] === $key) {
            $item = $it;
            break;
        }
    }
    if (!$item) {
        fail('物品不存在', 404);
    }
    $kind = $item['kind'];
    if ($kind === 'score') {
        fail('积分物品无需装备', 400);
    }
    // 校验已拥有
    $st = $pdo->prepare('SELECT COUNT(*) FROM blindbox_records WHERE user_id=? AND item_key=?');
    $st->execute([$uid, $key]);
    if ((int)$st->fetchColumn() === 0) {
        fail('你还没有获得该物品', 403);
    }
    if ($kind === 'frame') {
        $pdo->prepare('UPDATE users SET avatar_frame=? WHERE id=?')->execute([$key, $uid]);
    } elseif ($kind === 'bubble') {
        $pdo->prepare('UPDATE users SET bubble_skin=? WHERE id=?')->execute([$item['css'], $uid]);
    } elseif ($kind === 'title') {
        $pdo->prepare('UPDATE users SET title=? WHERE id=?')->execute([$item['css'], $uid]);
    }
    ok(['status' => boxStatus($uid)], '已装备：' . $item['name']);
}

/* ---------------- 帖子 ---------------- */

if ($action === 'post.create') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $ip = clientIp();
    if (isIpBanned($ip)) {
        fail('你的 IP 已被封禁，无法发布', 403);
    }
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁，无法发布', 403);
    }
    guardFlood($did);
    $content = trim((string)($_POST['content'] ?? ''));
    $category = (string)($_POST['category'] ?? 'confession');
    $isAnon = (int)(intval($_POST['is_anonymous'] ?? 0) === 1);
    $imagesRaw = (string)($_POST['images'] ?? '[]');
    // 话题房消息走更宽松的独立限速（聊天场景快速回复不被卡）
    $isRoomMsg = (int)($_POST['room_msg'] ?? 0) === 1;

    $categories = ['confession', 'forum', 'chat', 'help', 'loss', 'other', 'exam', 'study', 'career', 'anime'];
    if (!in_array($category, $categories, true)) {
        $category = 'confession';
    }
    if ($isRoomMsg) {
        // 跳过墙发帖 5s 限速，改用话题房独立 2s 限速
        checkRate($did, 'last_room', RATE_ROOM);
    } else {
        checkRate($did, 'last_post', RATE_POST);
    }
    if (mb_strlen($content) < 1 || mb_strlen($content) > 2000) {
        fail('内容长度需为 1-2000 字符');
    }
    guardDuplicate((int)$u['id'], $content);
    $images = json_decode($imagesRaw, true);
    if (!is_array($images) || count($images) > 9) {
        fail('图片数量超出限制');
    }
    // 只允许本地上传路径
    foreach ($images as $img) {
        if (!is_string($img) || !preg_match('#^uploads/[A-Za-z0-9_\-\.]+\.(jpg|jpeg|png|gif|webp)$#', $img)) {
            fail('图片路径不合法');
        }
    }
    $video = trim((string)($_POST['video'] ?? ''));
    if ($video !== '' && !preg_match('#^uploads/(chat/)?[A-Za-z0-9_\-\.]+\.(mp4|webm)$#', $video)) {
        fail('视频路径不合法');
    }
    // 投票帖：vote_data = JSON {title, options[]}（2-4 个选项）
    $voteJson = '';
    $voteRaw = trim((string)($_POST['vote_data'] ?? ''));
    if ($voteRaw !== '') {
        $vote = json_decode($voteRaw, true);
        if (!is_array($vote) || !isset($vote['options']) || !is_array($vote['options'])) {
            fail('投票数据格式错误');
        }
        $opts = array_values(array_filter(array_map('strval', $vote['options']), function ($o) {
            return trim($o) !== '';
        }));
        $opts = array_slice($opts, 0, 4);
        if (count($opts) < 2) {
            fail('投票至少需要 2 个选项');
        }
        $vTitle = trim((string)($vote['title'] ?? ''));
        if ($vTitle === '') {
            $vTitle = '来投票吧';
        }
        if (mb_strlen($vTitle) > 30) {
            fail('投票标题最长 30 字');
        }
        foreach ($opts as $o) {
            if (mb_strlen($o) > 20) {
                fail('每个选项最长 20 字');
            }
        }
        $voteJson = json_encode(['title' => $vTitle, 'options' => $opts], JSON_UNESCAPED_UNICODE);
    }
    db()->prepare('INSERT INTO posts (user_id, content, category, is_anonymous, images, video, vote_data, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$u['id'], $content, $category, $isAnon, json_encode($images, JSON_UNESCAPED_SLASHES), $video, $voteJson, clientIp(), now()]);
    $newId = (int)db()->lastInsertId();
    addScore((int)$u['id'], 3); // 发帖 +3 积分
    logAction($did, 'post');
    ok(['id' => $newId], '发布成功');
}

if ($action === 'post.list') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 15)));
    $category = (string)($_GET['category'] ?? '');
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $scope = (string)($_GET['scope'] ?? 'all'); // all|my|liked|commented|favs
    $sort = (string)($_GET['sort'] ?? 'new'); // new|hot 热门按热度倒序

    $where = ['p.status = 0'];
    $params = [];

    if ($category !== '' && $category !== 'all') {
        $where[] = 'p.category = ?';
        $params[] = $category;
    }
    if ($keyword !== '') {
        $where[] = 'p.content LIKE ?';
        $params[] = '%' . $keyword . '%';
    }
    if ($scope === 'my') {
        $where[] = 'p.user_id = ?';
        $params[] = $u['id'];
    } elseif ($scope === 'liked') {
        $where[] = 'p.id IN (SELECT post_id FROM likes WHERE user_id = ?)';
        $params[] = $u['id'];
    } elseif ($scope === 'commented') {
        $where[] = 'p.id IN (SELECT post_id FROM comments WHERE user_id = ? AND status = 0)';
        $params[] = $u['id'];
    } elseif ($scope === 'favs') {
        $where[] = 'p.id IN (SELECT post_id FROM favorites WHERE user_id = ?)';
        $params[] = $u['id'];
    } elseif ($scope === 'user') {
        // 某用户公开主页的帖子流（只看公开帖；匿名帖因 user_id 对外为 0 不会出现在这）
        $uid = (int)($_GET['user_id'] ?? 0);
        if ($uid <= 0) fail('参数无效');
        $where[] = 'p.user_id = ?';
        $params[] = $uid;
    }

    $whereSql = implode(' AND ', $where);
    $st = db()->prepare("SELECT COUNT(*) c FROM posts p WHERE $whereSql");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $offset = ($page - 1) * $limit;
    // 热门：热度近似公式（点赞×2 + 评论 + 浏览÷5，与 formatPost 的 heat 权重一致，礼物影响小不计入排序）
    $orderBy = ($sort === 'hot')
        ? 'ORDER BY (p.like_count * 2 + p.comment_count + p.view_count / 5) DESC, p.id DESC'
        : 'ORDER BY p.pinned DESC, p.id DESC';
    $sql = "SELECT p.*, u.nickname, u.anon_nickname, u.device_id, u.avatar, u.avatar_frame, u.nick_color, u.title, u.bubble_skin,
                    (SELECT 1 FROM likes l WHERE l.post_id = p.id AND l.user_id = ?) AS liked,
                    (SELECT 1 FROM favorites f WHERE f.post_id = p.id AND f.user_id = ?) AS favorited
             FROM posts p JOIN users u ON u.id = p.user_id
             WHERE $whereSql
             $orderBy LIMIT $limit OFFSET $offset";
    $st = db()->prepare($sql);
    $st->execute(array_merge([$u['id'], $u['id']], $params));
    $list = array_map(fn($p) => formatPost($p, $u), $st->fetchAll());
    ok(['list' => $list, 'total' => $total, 'page' => $page, 'has_more' => ($page * $limit) < $total]);
}

if ($action === 'post.detail') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare(
        "SELECT p.*, u.nickname, u.anon_nickname, u.device_id, u.avatar, u.avatar_frame, u.nick_color, u.title, u.bubble_skin,
                (SELECT 1 FROM likes l WHERE l.post_id = p.id AND l.user_id = ?) AS liked,
                (SELECT 1 FROM favorites f WHERE f.post_id = p.id AND f.user_id = ?) AS favorited
         FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ? AND p.status = 0"
    );
    $st->execute([$u['id'], $u['id'], $id]);
    $p = $st->fetch();
    if (!$p) {
        fail('帖子不存在', 404);
    }
    db()->prepare('UPDATE posts SET view_count = view_count + 1 WHERE id = ?')->execute([$id]);

    // 访客记录：浏览者不同于作者时记录（同一访客重复访问仅刷新最近时间）
    if ($u['id'] != $p['user_id']) {
        db()->prepare('INSERT OR REPLACE INTO visits (owner_id, visitor_id, created_at) VALUES (?,?,?)')
            ->execute([$p['user_id'], $u['id'], now()]);
    }

    $cs = db()->prepare(
        "SELECT c.*, u.nickname, u.device_id, u.avatar, u.avatar_frame, u.nick_color, u.title,
                (SELECT 1 FROM comment_likes cl WHERE cl.comment_id = c.id AND cl.user_id = ?) AS liked,
                (SELECT u2.nickname FROM comments c2 JOIN users u2 ON u2.id = c2.user_id WHERE c2.id = c.reply_to_id) AS reply_to_nickname
         FROM comments c JOIN users u ON u.id = c.user_id
         WHERE c.post_id = ? AND c.status = 0 ORDER BY c.id ASC LIMIT 300"
    );
    $cs->execute([$u['id'], $id]);
    $map = function ($c) use ($u) {
        return [
            'id'               => (int)$c['id'],
            'content'          => (string)$c['content'],
            'nickname'         => (string)$c['nickname'],
            'device_id'        => (string)($c['device_id'] ?? ''),
            'avatar'           => (string)($c['avatar'] ?? ''),
            'avatar_frame'     => (string)($c['avatar_frame'] ?? ''),
            'nick_color'       => (string)($c['nick_color'] ?? ''),
            'title'            => (string)($c['title'] ?? ''),
            'is_mine'          => (int)$c['user_id'] === (int)$u['id'],
            'created_at'       => (string)$c['created_at'],
            'parent_id'        => (int)$c['parent_id'],
            'reply_to_id'      => (int)$c['reply_to_id'],
            'reply_to_nickname'=> (string)($c['reply_to_nickname'] ?? ''),
            'like_count'       => (int)$c['like_count'],
            'liked'            => (bool)($c['liked'] ?? false),
        ];
    };
    // 分组：顶层评论 + 其楼中楼回复（回复统一挂顶层）
    $rows = $cs->fetchAll();
    $top = [];
    $idx = [];
    foreach ($rows as $c) {
        $item = $map($c);
        if ($item['parent_id'] > 0 && isset($idx[$item['parent_id']])) {
            $top[$idx[$item['parent_id']]]['replies'][] = $item;
        } else {
            $item['replies'] = [];
            $idx[$item['id']] = count($top);
            $top[] = $item;
        }
    }
    ok(['post' => formatPost($p, $u), 'comments' => $top]);
}

/* ---------------- 评论点赞 ---------------- */

if ($action === 'comment.like') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁，无法点赞', 403);
    }
    checkRate($did, 'last_like', RATE_LIKE);
    $cid = (int)($_POST['comment_id'] ?? 0);
    if ($cid <= 0) {
        fail('参数无效');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM comments WHERE id=? AND status=0');
    $st->execute([$cid]);
    if (!$st->fetchColumn()) {
        fail('评论不存在', 404);
    }
    $st = $pdo->prepare('SELECT 1 FROM comment_likes WHERE comment_id=? AND user_id=?');
    $st->execute([$cid, (int)$u['id']]);
    if ($st->fetchColumn()) {
        $pdo->prepare('DELETE FROM comment_likes WHERE comment_id=? AND user_id=?')->execute([$cid, (int)$u['id']]);
        $pdo->prepare('UPDATE comments SET like_count = MAX(0, like_count - 1) WHERE id=?')->execute([$cid]);
        ok(['liked' => false, 'like_count' => (int)$pdo->query('SELECT like_count FROM comments WHERE id=' . $cid)->fetchColumn()]);
    } else {
        $pdo->prepare('INSERT OR IGNORE INTO comment_likes (comment_id, user_id, created_at) VALUES (?,?,?)')
            ->execute([$cid, (int)$u['id'], now()]);
        $pdo->prepare('UPDATE comments SET like_count = like_count + 1 WHERE id=?')->execute([$cid]);
        ok(['liked' => true, 'like_count' => (int)$pdo->query('SELECT like_count FROM comments WHERE id=' . $cid)->fetchColumn()]);
    }
}

/* ---------------- 删除评论（本人或超管） ---------------- */

if ($action === 'comment.delete') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $cid = (int)($_POST['comment_id'] ?? 0);
    if ($cid <= 0) {
        fail('参数无效');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT user_id, post_id, status FROM comments WHERE id=?');
    $st->execute([$cid]);
    $c = $st->fetch();
    if (!$c) {
        fail('评论不存在', 404);
    }
    $isAdmin = in_array((string)$u['role'], ['admin', 'super'], true);
    if ((int)$c['user_id'] !== (int)$u['id'] && !$isAdmin) {
        fail('只能删除自己的评论', 403);
    }
    if ((int)$c['status'] === 0) {
        $pdo->prepare('UPDATE comments SET status=1 WHERE id=?')->execute([$cid]);
        $pdo->prepare('UPDATE posts SET comment_count = MAX(0, comment_count - 1) WHERE id=?')->execute([(int)$c['post_id']]);
        // 楼中楼被删：其回复一并隐藏（挂在同一顶层）
        $pdo->prepare('UPDATE comments SET status=1 WHERE parent_id=?')->execute([$cid]);
    }
    ok(null, '评论已删除');
}

/* ---------------- 积分小卖部 ---------------- */

if ($action === 'shop.list') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    global $SHOP_CATALOG;
    $nowTs = time();
    // 昵称色过期后视为未持有
    $colorActive = ($u['nick_color_until'] ?? '') !== '' && strtotime($u['nick_color_until']) > $nowTs;
    $owns = [];
    if (!empty($u['avatar_frame'])) {
        $owns[] = $u['avatar_frame'];
    }
    if ($colorActive && !empty($u['nick_color'])) {
        $owns[] = $u['nick_color'];
    }
    // 创始人/无限积分（vip_level>=100 或 super）：前端显示 ∞
    $unlimited = ((int)($u['vip_level'] ?? 0) >= 100 || (string)($u['role'] ?? '') === 'super');
    ok([
        'score' => $unlimited ? '∞' : (int)$u['score'],
        'owns'  => $owns,
        'items' => array_map(function ($g) {
            return ['id' => $g['id'], 'kind' => $g['kind'], 'name' => $g['name'],
                    'desc' => $g['desc'], 'price' => (int)$g['price'], 'css' => $g['css']];
        }, $SHOP_CATALOG),
    ]);
}

if ($action === 'shop.buy') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    global $SHOP_CATALOG;
    $itemId = (string)($_POST['item_id'] ?? '');
    $item = null;
    foreach ($SHOP_CATALOG as $g) {
        if ($g['id'] === $itemId) {
            $item = $g;
            break;
        }
    }
    if (!$item) {
        fail('商品不存在', 404);
    }
    $pdo = db();
    // 已持有校验
    if ($item['kind'] === 'frame') {
        if (!empty($u['avatar_frame']) && $u['avatar_frame'] === $item['id']) {
            fail('你已经拥有这个头像框啦');
        }
    } else {
        $nowTs = time();
        if ($u['nick_color'] === $item['id'] && strtotime($u['nick_color_until'] ?? '') > $nowTs) {
            fail('该昵称色还在生效中');
        }
    }
    $price = (int)$item['price'];
    // 创始人/无限积分（vip_level>=100 或 super）：不校验、不扣分
    $unlimited = ((int)($u['vip_level'] ?? 0) >= 100 || (string)($u['role'] ?? '') === 'super');
    if (!$unlimited) {
        if ((int)$u['score'] < $price) {
            fail('积分不足，还差 ' . ($price - (int)$u['score']) . ' 分');
        }
        $pdo->prepare('UPDATE users SET score = score - ? WHERE id=?')->execute([$price, (int)$u['id']]);
    }
    if ($item['kind'] === 'frame') {
        $pdo->prepare('UPDATE users SET avatar_frame=? WHERE id=?')->execute([$item['id'], (int)$u['id']]);
    } else {
        $until = date('Y-m-d H:i:s', time() + 7 * 86400);
        $pdo->prepare('UPDATE users SET nick_color=?, nick_color_until=? WHERE id=?')
            ->execute([$item['id'], $until, (int)$u['id']]);
    }
    logAction($did, 'shop');
    $st = $pdo->prepare('SELECT score FROM users WHERE id=?');
    $st->execute([(int)$u['id']]);
    ok(['score' => $unlimited ? '∞' : (int)$st->fetchColumn()], '购买成功');
}

if ($action === 'post.like') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    checkRate($did, 'last_like', RATE_LIKE);
    $id = (int)($_POST['post_id'] ?? 0);
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM posts WHERE id=? AND status=0');
    $st->execute([$id]);
    if (!$st->fetch()) {
        fail('帖子不存在', 404);
    }
    $st = $pdo->prepare('SELECT id FROM likes WHERE post_id=? AND user_id=?');
    $st->execute([$id, $u['id']]);
    if ($st->fetch()) {
        $pdo->prepare('DELETE FROM likes WHERE post_id=? AND user_id=?')->execute([$id, $u['id']]);
        $pdo->prepare('UPDATE posts SET like_count = MAX(0, like_count - 1) WHERE id=?')->execute([$id]);
        ok(['liked' => false], '已取消点赞');
    } else {
        $pdo->prepare('INSERT INTO likes (post_id, user_id, created_at) VALUES (?,?,?)')
            ->execute([$id, $u['id'], now()]);
        $pdo->prepare('UPDATE posts SET like_count = like_count + 1 WHERE id=?')->execute([$id]);
        // 通知帖子作者（自己赞自己不通知）
        $st = $pdo->prepare('SELECT user_id FROM posts WHERE id=?');
        $st->execute([$id]);
        $postOwner = (int)$st->fetchColumn();
        notifyPostOwner($postOwner, (int)$u['id'], 'post_like', $id);
        // 帖主被赞积分：每日同帖上限 5
        if ($postOwner !== (int)$u['id']) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM likes WHERE post_id=? AND created_at >= ?');
            $st->execute([$id, date('Y-m-d 00:00:00')]);
            if ((int)$st->fetchColumn() <= 5) {
                addScore($postOwner, 1);
            }
        }
        ok(['liked' => true], '点赞成功');
    }
}

if ($action === 'post.fav') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $id = (int)($_POST['post_id'] ?? 0);
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM posts WHERE id=? AND status=0');
    $st->execute([$id]);
    if (!$st->fetch()) {
        fail('帖子不存在', 404);
    }
    $st = $pdo->prepare('SELECT id FROM favorites WHERE post_id=? AND user_id=?');
    $st->execute([$id, $u['id']]);
    if ($st->fetch()) {
        $pdo->prepare('DELETE FROM favorites WHERE post_id=? AND user_id=?')->execute([$id, $u['id']]);
        ok(['favorited' => false], '已取消收藏');
    } else {
        $pdo->prepare('INSERT INTO favorites (post_id, user_id, created_at) VALUES (?,?,?)')
            ->execute([$id, $u['id'], now()]);
        ok(['favorited' => true], '已收藏');
    }
}

if ($action === 'post.delete') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $id = (int)($_POST['post_id'] ?? 0);
    $st = db()->prepare('SELECT user_id FROM posts WHERE id=? AND status=0');
    $st->execute([$id]);
    $owner = $st->fetchColumn();
    if (!$owner || ((int)$owner !== (int)$u['id'] && $u['role'] !== 'super')) {
        fail('无权删除');
    }
    db()->prepare('UPDATE posts SET status=1 WHERE id=?')->execute([$id]);
    ok(null, '已删除');
}

/* ---------------- 评论 ---------------- */

if ($action === 'comment.create') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    if (isIpBanned(clientIp())) {
        fail('你的 IP 已被封禁，无法评论', 403);
    }
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁，无法评论', 403);
    }
    checkRate($did, 'last_comment', RATE_COMMENT);
    guardFlood($did);
    $postId = (int)($_POST['post_id'] ?? 0);
    $content = trim((string)($_POST['content'] ?? ''));
    if (mb_strlen($content) < 1 || mb_strlen($content) > 500) {
        fail('评论长度需为 1-500 字符');
    }
    guardDuplicate((int)$u['id'], $content);
    $st = db()->prepare('SELECT user_id FROM posts WHERE id=? AND status=0');
    $st->execute([$postId]);
    $ownerId = (int)$st->fetchColumn();
    if (!$ownerId) {
        fail('帖子不存在', 404);
    }
    // 楼中楼：parent_id 统一挂到顶层评论；reply_to_id 记录被直接回复的评论（用于 @ 显示）
    $parentId = max(0, (int)($_POST['parent_id'] ?? 0));
    $replyToId = $parentId;
    $replyToOwnerId = 0;
    if ($parentId > 0) {
        $st = db()->prepare('SELECT id, user_id, parent_id FROM comments WHERE id=? AND post_id=? AND status=0');
        $st->execute([$parentId, $postId]);
        $pc = $st->fetch();
        if (!$pc) {
            $parentId = 0;
            $replyToId = 0;
        } else {
            $replyToOwnerId = (int)$pc['user_id'];
            if ((int)$pc['parent_id'] > 0) {
                // 回复的是楼中楼回复 → 挂到同一顶层，@ 对象仍是那条回复的作者
                $parentId = (int)$pc['parent_id'];
            } else {
                $parentId = (int)$pc['id'];
            }
        }
    }
    db()->prepare('INSERT INTO comments (post_id, user_id, content, parent_id, reply_to_id, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$postId, $u['id'], $content, $parentId, $replyToId, now()]);
    db()->prepare('UPDATE posts SET comment_count = comment_count + 1 WHERE id=?')->execute([$postId]);
    $newId = (int)db()->lastInsertId();
    notifyPostOwner($ownerId, (int)$u['id'], 'post_comment', $postId, $content);
    // 回复楼中楼时，另通知被直接回复的人（不是帖子作者、也不是自己）
    if ($replyToOwnerId > 0 && $replyToOwnerId !== $ownerId && $replyToOwnerId !== (int)$u['id']) {
        notifyUser($replyToOwnerId, (int)$u['id'], 'comment_reply', $postId, $content);
    }
    addScore((int)$u['id'], 1); // 评论 +1 积分
    logAction($did, 'comment');
    ok(['id' => $newId], '评论成功');
}

/* ---------------- 举报 ---------------- */

if ($action === 'report.create') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    if (isIpBanned(clientIp())) {
        fail('你的 IP 已被封禁，无法举报', 403);
    }
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁，无法举报', 403);
    }
    checkRate($did, 'last_report', RATE_REPORT);
    guardFlood($did);
    $targetType = (string)($_POST['target_type'] ?? '');
    $targetId = (int)($_POST['target_id'] ?? 0);
    $reason = (string)($_POST['reason'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    if (!in_array($targetType, ['post', 'comment'], true)) {
        fail('举报目标类型不合法');
    }
    if (!in_array($reason, $REPORT_REASONS, true)) {
        fail('举报原因不合法');
    }
    if (mb_strlen($note) > 200) {
        fail('补充说明不能超过 200 字');
    }
    // 目标存在性 + 作者
    $pdo = db();
    if ($targetType === 'post') {
        $st = $pdo->prepare('SELECT user_id FROM posts WHERE id=? AND status=0');
        $st->execute([$targetId]);
        $authorId = (int)$st->fetchColumn();
    } else {
        $st = $pdo->prepare('SELECT user_id FROM comments WHERE id=?');
        $st->execute([$targetId]);
        $authorId = (int)$st->fetchColumn();
    }
    if (!$authorId) {
        fail('目标不存在或已删除', 404);
    }
    if ($authorId === (int)$u['id']) {
        fail('不能举报自己的内容');
    }
    // 同一用户对同一内容只能举报一次
    $st = $pdo->prepare('SELECT id FROM reports WHERE target_type=? AND target_id=? AND reporter_id=?');
    $st->execute([$targetType, $targetId, $u['id']]);
    if ($st->fetch()) {
        fail('你已举报过该内容');
    }
    $pdo->prepare('INSERT INTO reports (target_type, target_id, reporter_id, reason, note, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$targetType, $targetId, $u['id'], $reason, $note, now()]);
    $newId = (int)$pdo->lastInsertId();
    logAction($did, 'report');
    ok(['id' => $newId], '举报已提交，感谢你的反馈');
}

/* ---------------- 好友 ---------------- */

if ($action === 'user.rank') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $pdo = db();
    // 校园积分榜：Top 10（按积分倒序，vip_level 展示用；同分按最新活跃优先）
    $st = $pdo->query(
        'SELECT id, nickname, avatar, signature, score, vip_level, avatar_frame, nick_color, title FROM users
         ORDER BY score DESC, last_active DESC LIMIT 10'
    );
    $list = [];
    foreach ($st->fetchAll() as $r) {
        $lvl = (int)$r['vip_level'] > 0 ? (int)$r['vip_level'] : (int)levelOf((int)$r['score'])[0];
        $list[] = [
            'nickname' => (string)$r['nickname'],
            'avatar' => (string)($r['avatar'] ?? ''),
            'signature' => (string)($r['signature'] ?? ''),
            'avatar_frame' => (string)($r['avatar_frame'] ?? ''),
            'nick_color' => (string)($r['nick_color'] ?? ''),
            'title' => (string)($r['title'] ?? ''),
            'score' => (int)$r['score'],
            'level' => $lvl,
            'is_vip' => (int)$r['vip_level'] > 0,
            'is_me' => (int)$r['id'] === (int)$u['id'],
        ];
    }
    // 我的排名（按积分 > 当前分的用户数 + 1）
    $myScore = (int)($u['score'] ?? 0);
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE score > ?');
    $st->execute([$myScore]);
    $myRank = (int)$st->fetchColumn() + 1;
    ok(['list' => $list, 'my_rank' => $myRank, 'my_score' => $myScore]);
}

if ($action === 'user.profile') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $uid = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($uid <= 0) fail('参数无效');
    $pdo = db();
    $st = $pdo->prepare('SELECT id, nickname, avatar, signature, score, vip_level, device_id, created_at, avatar_frame, nick_color, title, love_type FROM users WHERE id = ?');
    $st->execute([$uid]);
    $t = $st->fetch();
    if (!$t) fail('用户不存在');
    $a = min((int)$u['id'], $uid);
    $b = max((int)$u['id'], $uid);
    $st = $pdo->prepare('SELECT 1 FROM friends WHERE user_a=? AND user_b=?');
    $st->execute([$a, $b]);
    ok([
        'user_id'     => (int)$t['id'],
        'nickname'    => (string)$t['nickname'],
        'avatar'      => (string)($t['avatar'] ?? ''),
        'avatar_frame'=> (string)($t['avatar_frame'] ?? ''),
        'nick_color'  => (string)($t['nick_color'] ?? ''),
        'title'       => (string)($t['title'] ?? ''),
        'love_type'   => (string)($t['love_type'] ?? ''),
        'signature'   => (string)($t['signature'] ?? ''),
        'score'       => (int)$t['score'],
        'level'       => (int)$t['vip_level'] > 0 ? (int)$t['vip_level'] : (int)levelOf((int)$t['score'])[0],
        'is_vip'      => (int)$t['vip_level'] > 0,
        'device_id'   => (string)$t['device_id'],
        'is_self'     => $uid === (int)$u['id'],
        'is_friend'   => (bool)$st->fetchColumn(),
        'created_at'  => (string)$t['created_at'],
    ]);
}

if ($action === 'user.search') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $kw = trim((string)($_GET['keyword'] ?? $_POST['keyword'] ?? ''));
    if ($kw === '' || mb_strlen($kw) > 64) {
        fail('请输入昵称或设备号');
    }
    $pdo = db();
    $st = $pdo->prepare(
        'SELECT id, device_id, nickname, avatar FROM users
         WHERE (nickname LIKE ? OR device_id LIKE ?) AND id != ?
         ORDER BY last_active DESC LIMIT 20'
    );
    $st->execute(['%' . $kw . '%', '%' . $kw . '%', $u['id']]);
    $rows = [];
    foreach ($st->fetchAll() as $f) {
        $a = min($u['id'], (int)$f['id']);
        $b = max($u['id'], (int)$f['id']);
        $st2 = $pdo->prepare('SELECT 1 FROM friends WHERE user_a=? AND user_b=?');
        $st2->execute([$a, $b]);
        $isFriend = (bool)$st2->fetchColumn();
        $st3 = $pdo->prepare("SELECT id, from_user FROM friend_requests
            WHERE ((from_user=? AND to_user=?) OR (from_user=? AND to_user=?)) AND status=0");
        $st3->execute([$u['id'], $f['id'], $f['id'], $u['id']]);
        $pending = $st3->fetch();
        $rows[] = [
            'device_id' => (string)$f['device_id'],
            'nickname'  => (string)$f['nickname'],
            'avatar'    => (string)($f['avatar'] ?? ''),
            'is_friend' => $isFriend,
            'pending'   => $pending ? ((int)$pending['from_user'] === (int)$f['id'] ? 'incoming' : 'outgoing') : '',
        ];
    }
    ok(['list' => $rows]);
}

if ($action === 'friend.add') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $friendDid = trim((string)($_POST['friend_device_id'] ?? ''));
    $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 100);
    if ($friendDid === '' || strlen($friendDid) > 128) {
        fail('目标设备标识无效');
    }
    if ($friendDid === $did) {
        fail('不能添加自己为好友');
    }
    $st = db()->prepare('SELECT id, nickname FROM users WHERE device_id=?');
    $st->execute([$friendDid]);
    $friend = $st->fetch();
    if (!$friend) {
        fail('对方还没有使用过本站');
    }
    // 已经是好友
    $pdo = db();
    $a = min($u['id'], (int)$friend['id']);
    $b = max($u['id'], (int)$friend['id']);
    $st = $pdo->prepare('SELECT id FROM friends WHERE user_a=? AND user_b=?');
    $st->execute([$a, $b]);
    if ($st->fetch()) {
        fail('你们已经是好友了');
    }
    // 有未处理的申请
    $st = $pdo->prepare("SELECT id, from_user FROM friend_requests
        WHERE ((from_user=? AND to_user=?) OR (from_user=? AND to_user=?)) AND status=0");
    $st->execute([$u['id'], $friend['id'], $friend['id'], $u['id']]);
    $pending = $st->fetch();
    if ($pending) {
        if ((int)$pending['from_user'] === (int)$friend['id']) {
            fail('对方已向你发送过申请，请去通知中心处理');
        }
        fail('已发送过申请，请等待对方处理');
    }
    $pdo->prepare('INSERT INTO friend_requests (from_user, to_user, message, created_at) VALUES (?,?,?,?)')
        ->execute([$u['id'], $friend['id'], $message, now()]);
    ok(null, '好友申请已发送');
}

if ($action === 'friend.requests') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $st = db()->prepare(
        "SELECT fr.id, fr.message, fr.status, fr.created_at, u.nickname, u.device_id, u.avatar
         FROM friend_requests fr JOIN users u ON u.id = fr.from_user
         WHERE fr.to_user = ? AND fr.status = 0 ORDER BY fr.id DESC LIMIT 100"
    );
    $st->execute([$u['id']]);
    $list = array_map(function ($r) {
        return [
            'id'         => (int)$r['id'],
            'nickname'   => (string)$r['nickname'],
            'device_id'  => (string)$r['device_id'],
            'avatar'     => (string)($r['avatar'] ?? ''),
            'message'    => (string)$r['message'],
            'created_at' => (string)$r['created_at'],
        ];
    }, $st->fetchAll());
    ok(['list' => $list, 'count' => count($list)]);
}

if ($action === 'friend.accept' || $action === 'friend.reject') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $reqId = (int)($_POST['request_id'] ?? 0);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM friend_requests WHERE id=? AND to_user=? AND status=0');
    $st->execute([$reqId, $u['id']]);
    $req = $st->fetch();
    if (!$req) {
        fail('申请不存在或已处理');
    }
    if ($action === 'friend.accept') {
        $a = min((int)$req['from_user'], (int)$u['id']);
        $b = max((int)$req['from_user'], (int)$u['id']);
        $pdo->prepare('INSERT OR IGNORE INTO friends (user_a, user_b, created_at) VALUES (?,?,?)')
            ->execute([$a, $b, now()]);
        $pdo->prepare('UPDATE friend_requests SET status=1 WHERE id=?')->execute([$reqId]);
        ok(null, '已同意好友申请');
    } else {
        $pdo->prepare('UPDATE friend_requests SET status=2 WHERE id=?')->execute([$reqId]);
        ok(null, '已拒绝');
    }
}

if ($action === 'friend.list') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $st = db()->prepare(
        "SELECT u.id, u.device_id, u.nickname, u.avatar, u.signature, fr.created_at AS friend_since
         FROM friends fr
         JOIN users u ON u.id = (CASE WHEN fr.user_a = ? THEN fr.user_b ELSE fr.user_a END)
         WHERE fr.user_a = ? OR fr.user_b = ? ORDER BY fr.id DESC"
    );
    $st->execute([$u['id'], $u['id'], $u['id']]);
    $list = array_map(function ($r) {
        return [
            'device_id'    => (string)$r['device_id'],
            'nickname'     => (string)$r['nickname'],
            'avatar'       => (string)($r['avatar'] ?? ''),
            'signature'    => (string)($r['signature'] ?? ''),
            'friend_since' => (string)$r['friend_since'],
        ];
    }, $st->fetchAll());
    ok(['list' => $list, 'count' => count($list)]);
}

if ($action === 'friend.remove') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $friendDid = trim((string)($_POST['friend_device_id'] ?? ''));
    $st = db()->prepare('SELECT id FROM users WHERE device_id=?');
    $st->execute([$friendDid]);
    $fid = $st->fetchColumn();
    if (!$fid) {
        fail('好友不存在');
    }
    $a = min($u['id'], (int)$fid);
    $b = max($u['id'], (int)$fid);
    db()->prepare('DELETE FROM friends WHERE user_a=? AND user_b=?')->execute([$a, $b]);
    ok(null, '已删除好友');
}

/* ---------------- 好友私信（仅好友双方可见，聊天记录由客户端本地缓存） ---------------- */

/** 校验两人是否为好友，返回好友用户 id，否则失败 */
function requireFriendPair(int $uid, string $friendDid): int
{
    $st = db()->prepare('SELECT id FROM users WHERE device_id=?');
    $st->execute([$friendDid]);
    $fid = (int)$st->fetchColumn();
    if (!$fid || $fid === $uid) {
        fail('对方不存在');
    }
    $a = min($uid, $fid);
    $b = max($uid, $fid);
    $st = db()->prepare('SELECT 1 FROM friends WHERE user_a=? AND user_b=?');
    $st->execute([$a, $b]);
    if (!$st->fetch()) {
        fail('你们还不是好友，无法私信');
    }
    return $fid;
}

/* ---------------- 心动匹配 ---------------- */

if ($action === 'crush.send') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $toId = (int)($_POST['to_user_id'] ?? 0);
    if ($toId <= 0) {
        fail('目标无效');
    }
    if ($toId === (int)$u['id']) {
        fail('不能心动自己');
    }
    $st = db()->prepare('SELECT id FROM users WHERE id=?');
    $st->execute([$toId]);
    if (!$st->fetch()) {
        fail('用户不存在', 404);
    }
    $pdo = db();
    // 去重（先查后写）
    $st = $pdo->prepare('SELECT id FROM crushes WHERE from_user=? AND to_user=?');
    $st->execute([$u['id'], $toId]);
    if ($st->fetch()) {
        fail('你已经心动 TA 啦');
    }
    // 心动理由（可选，限 50 字，表白墙灵魂）
    $reason = trim((string)($_POST['reason'] ?? ''));
    if (mb_strlen($reason, 'UTF-8') > 50) {
        $reason = mb_substr($reason, 0, 50, 'UTF-8');
    }
    $pdo->prepare('INSERT INTO crushes (from_user, to_user, created_at, reason) VALUES (?,?,?,?)')
        ->execute([$u['id'], $toId, now(), $reason]);
    // 对方收到"心动了你"通知（单向也可见，形成「谁心动了你」闭环）
    notifyUser($toId, (int)$u['id'], 'crush', 0, $reason !== '' ? '心动了你：' . $reason : '心动了你');
    // 是否双向匹配
    $st = $pdo->prepare('SELECT id FROM crushes WHERE from_user=? AND to_user=?');
    $st->execute([$toId, $u['id']]);
    if ($st->fetch()) {
        notifyUser($toId, (int)$u['id'], 'crush_match', 0, '你们互相心动啦，快去打个招呼吧', true);
        notifyUser((int)$u['id'], $toId, 'crush_match', 0, '你们互相心动啦，快去打个招呼吧', true);
        ok(['matched' => true], 'TA 也心动你！匹配成功');
    }
    ok(['matched' => false], '已发送心动');
}

if ($action === 'crush.matches') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $st = db()->prepare(
        'SELECT u.id, u.nickname, u.avatar, u.device_id, c1.created_at, c2.reason AS their_reason
         FROM crushes c1
         JOIN crushes c2 ON c2.from_user = c1.to_user AND c2.to_user = c1.from_user
         JOIN users u ON u.id = c1.to_user
         WHERE c1.from_user = ? ORDER BY c1.created_at DESC'
    );
    $st->execute([$u['id']]);
    $list = array_map(static function ($r) use ($u) {
        return [
            'user_id'    => (int)$r['id'],
            'nickname'   => (string)$r['nickname'],
            'avatar'     => (string)($r['avatar'] ?? ''),
            'device_id'  => (string)$r['device_id'],
            'created_at' => (string)$r['created_at'],
            'reason'     => (string)($r['their_reason'] ?? ''),
            'cp'         => cpScore((int)$u['id'], (int)$r['id']),
        ];
    }, $st->fetchAll());
    ok(['list' => $list]);
}

if ($action === 'crush.status') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $toId = (int)($_POST['to_user_id'] ?? $_GET['to_user_id'] ?? 0);
    if ($toId > 0) {
        $st = db()->prepare('SELECT id FROM crushes WHERE from_user=? AND to_user=?');
        $st->execute([$u['id'], $toId]);
        ok(['sent' => (bool)$st->fetch()]);
    }
    // 不带 to_user_id：返回我心动过的列表（供匹配页展示）
    $st = db()->prepare(
        'SELECT u.id, u.nickname, u.avatar, u.device_id, c.created_at, c.reason
         FROM crushes c JOIN users u ON u.id = c.to_user
         WHERE c.from_user = ? ORDER BY c.created_at DESC'
    );
    $st->execute([$u['id']]);
    $list = array_map(static function ($r) {
        return [
            'user_id'    => (int)$r['id'],
            'nickname'   => (string)$r['nickname'],
            'avatar'     => (string)($r['avatar'] ?? ''),
            'device_id'  => (string)$r['device_id'],
            'created_at' => (string)$r['created_at'],
            'reason'     => (string)($r['reason'] ?? ''),
        ];
    }, $st->fetchAll());
    ok(['list' => $list]);
}

/* 「谁心动了你」：别人对我的心动（含单向与双向），不暴露 device_id */
if ($action === 'crush.received') {
    $u = getOrCreateUser(requireDeviceId());
    $st = db()->prepare(
        'SELECT u.id, u.nickname, u.avatar, c.created_at, c.reason
         FROM crushes c JOIN users u ON u.id = c.from_user
         WHERE c.to_user = ? ORDER BY c.created_at DESC LIMIT 100'
    );
    $st->execute([$u['id']]);
    $pdo = db();
    $list = [];
    foreach ($st->fetchAll() as $r) {
        $fromId = (int)$r['id'];
        if ($fromId === (int)$u['id']) continue; // 自恋数据防御
        $st2 = $pdo->prepare('SELECT 1 FROM crushes WHERE from_user=? AND to_user=?');
        $st2->execute([$u['id'], $fromId]);
        $mutual = (bool)$st2->fetchColumn();
        $st3 = $pdo->prepare('SELECT 1 FROM friends WHERE user_a=? AND user_b=?');
        $st3->execute([min($u['id'], $fromId), max($u['id'], $fromId)]);
        $isFriend = (bool)$st3->fetchColumn();
        $list[] = [
            'user_id'    => $fromId,
            'nickname'   => (string)$r['nickname'],
            'avatar'     => (string)($r['avatar'] ?? ''),
            'created_at' => (string)$r['created_at'],
            'mutual'     => $mutual,
            'is_friend'  => $isFriend,
            'reason'     => (string)($r['reason'] ?? ''),
            'cp'         => cpScore((int)$u['id'], $fromId),
        ];
    }
    ok(['list' => $list]);
}

/* 每日任务中心：task 统计与领取（配置见 config.php $DAILY_TASKS） */
function taskDone($pdo, $uid, $task, $today) {
    if ($task['kind'] === 'mark') {
        $st = $pdo->prepare('SELECT 1 FROM user_tasks WHERE user_id=? AND task_date=? AND task_id=?');
        $st->execute([$uid, $today, $task['id']]);
        return (bool)$st->fetch();
    }
    if ($task['kind'] === 'sign') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM signins WHERE user_id=? AND date=?');
        $st->execute([$uid, $today]);
        return (int)$st->fetchColumn() >= (int)$task['target'];
    }
    $tables = ['post' => 'posts', 'comment' => 'comments', 'like' => 'likes', 'box' => 'blindbox_records', 'goods' => 'goods'];
    $st = $pdo->prepare('SELECT COUNT(*) FROM ' . $tables[$task['kind']] . ' WHERE user_id=? AND created_at LIKE ?');
    $st->execute([$uid, $today . '%']);
    return (int)$st->fetchColumn() >= (int)$task['target'];
}
if ($action === 'task.list') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $today = date('Y-m-d');
    $list = [];
    $allClaimed = true;
    foreach ($DAILY_TASKS as $t) {
        $st = $pdo->prepare('SELECT claimed_at FROM user_tasks WHERE user_id=? AND task_date=? AND task_id=?');
        $st->execute([$u['id'], $today, $t['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $done = $row ? true : taskDone($pdo, $u['id'], $t, $today);
        $claimed = (bool)($row && $row['claimed_at'] !== null);
        if (!$claimed) {
            $allClaimed = false;
        }
        $list[] = ['id' => $t['id'], 'name' => $t['name'], 'icon' => $t['icon'], 'target' => $t['target'], 'score' => $t['score'], 'kind' => $t['kind'], 'done' => $done, 'claimed' => $claimed];
    }
    $st = $pdo->prepare('SELECT 1 FROM user_tasks WHERE user_id=? AND task_date=? AND task_id=?');
    $st->execute([$u['id'], $today, 'allbonus']);
    ok(['list' => $list, 'all_done' => $allClaimed, 'all_bonus' => (bool)$st->fetch(), 'bonus' => TASK_ALL_BONUS, 'today' => $today]);
}
if ($action === 'task.mark') {
    $u = getOrCreateUser(requireDeviceId());
    $id = trim((string)($_POST['task_id'] ?? ''));
    $valid = false;
    foreach ($DAILY_TASKS as $t) {
        if ($t['id'] === $id && $t['kind'] === 'mark') {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        fail('任务无效');
    }
    $pdo = db();
    $pdo->prepare('INSERT OR IGNORE INTO user_tasks (user_id, task_date, task_id) VALUES (?,?,?)')
        ->execute([$u['id'], date('Y-m-d'), $id]);
    ok(['done' => true]);
}
if ($action === 'task.claim') {
    $u = getOrCreateUser(requireDeviceId());
    $id = trim((string)($_POST['task_id'] ?? ''));
    $today = date('Y-m-d');
    $pdo = db();
    if ($id === 'allbonus') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM user_tasks WHERE user_id=? AND task_date=? AND claimed_at IS NOT NULL');
        $st->execute([$u['id'], $today]);
        $claimedCnt = (int)$st->fetchColumn();
        if ($claimedCnt < count($DAILY_TASKS)) {
            fail('还有 ' . (count($DAILY_TASKS) - $claimedCnt) . ' 个任务没完成');
        }
        $st = $pdo->prepare('INSERT OR IGNORE INTO user_tasks (user_id, task_date, task_id, claimed_at) VALUES (?,?,?,?)');
        $st->execute([$u['id'], $today, 'allbonus', now()]);
        if ($st->rowCount() === 0) {
            fail('全勤奖已经领过啦');
        }
        addScore((int)$u['id'], TASK_ALL_BONUS);
        ok(['score' => TASK_ALL_BONUS], '全勤奖励 +' . TASK_ALL_BONUS . ' 积分');
    }
    $task = null;
    foreach ($DAILY_TASKS as $t) {
        if ($t['id'] === $id) {
            $task = $t;
            break;
        }
    }
    if (!$task) {
        fail('任务不存在');
    }
    if (!taskDone($pdo, $u['id'], $task, $today)) {
        fail('任务还没完成哦');
    }
    if ($task['kind'] === 'mark') {
        // 上报型：done 记录已存在（task.mark），只更新领取时间
        $st = $pdo->prepare('UPDATE user_tasks SET claimed_at=? WHERE user_id=? AND task_date=? AND task_id=? AND claimed_at IS NULL');
        $st->execute([now(), $u['id'], $today, $id]);
        if ($st->rowCount() === 0) {
            fail('今天这份已经领过啦');
        }
    } else {
        // 统计型：首次领取直接插入领取记录（重复领取 rowCount=0）
        $st = $pdo->prepare('INSERT OR IGNORE INTO user_tasks (user_id, task_date, task_id, claimed_at) VALUES (?,?,?,?)');
        $st->execute([$u['id'], $today, $id, now()]);
        if ($st->rowCount() === 0) {
            fail('今天这份已经领过啦');
        }
    }
    addScore((int)$u['id'], (int)$task['score']);
    ok(['score' => (int)$task['score']], '任务完成 +' . $task['score'] . ' 积分');
}

/* 匿名心动纸条：递匿名纸条，对方从 3 个嫌疑里猜是谁，猜对才揭晓 */
if ($action === 'note.send') {
    $u = getOrCreateUser(requireDeviceId());
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁，无法递纸条', 403);
    }
    checkRate(requireDeviceId(), 'last_note', 10);
    $toId = (int)($_POST['to_user_id'] ?? 0);
    $content = trim((string)($_POST['content'] ?? ''));
    if ($toId <= 0 || $toId === (int)$u['id']) {
        fail('纸条要递给别的用户');
    }
    if ($content === '' || mb_strlen($content) > 50) {
        fail('纸条内容 1-50 字');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT 1 FROM users WHERE id=?');
    $st->execute([$toId]);
    if (!$st->fetch()) {
        fail('用户不存在');
    }
    // 嫌疑名单：自己 + 2 个随机其他活跃用户（含发件人，共 3 个）
    $st = $pdo->prepare('SELECT id FROM users WHERE id != ? ORDER BY RANDOM() LIMIT 2');
    $st->execute([$toId]);
    $sus = [$u['id']];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        if (!in_array((int)$rid, $sus, true)) {
            $sus[] = (int)$rid;
        }
    }
    while (count($sus) < 3) {
        $st = $pdo->prepare('SELECT id FROM users WHERE id != ? AND id NOT IN (' . implode(',', $sus) . ') ORDER BY RANDOM() LIMIT 1');
        $st->execute([$toId]);
        $rid = $st->fetchColumn();
        if (!$rid) {
            break;
        }
        $sus[] = (int)$rid;
    }
    shuffle($sus);
    $pdo->prepare('INSERT INTO secret_notes (from_user, to_user, content, suspects, status, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([(int)$u['id'], $toId, $content, json_encode($sus), 'pending', now()]);
    notifyUser($toId, (int)$u['id'], 'note', 0, '有人递给你一张匿名纸条，猜猜是谁');
    ok(['id' => (int)$pdo->lastInsertId()], '纸条已递出，等 TA 来猜');
}
if ($action === 'note.list') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    // 我收到的纸条：pending 在前
    $st = $pdo->prepare("SELECT n.*, u.nickname AS fn, u.avatar AS fav FROM secret_notes n LEFT JOIN users u ON u.id=n.from_user WHERE n.to_user=? ORDER BY (n.status='pending') DESC, n.id DESC LIMIT 30");
    $st->execute([$u['id']]);
    $inbox = array_map(function ($n) use ($pdo) {
        $sus = json_decode($n['suspects'], true) ?: [];
        $names = [];
        if ($sus) {
            $ph = implode(',', array_fill(0, count($sus), '?'));
            $st = $pdo->prepare("SELECT id, nickname, avatar FROM users WHERE id IN ($ph)");
            $st->execute($sus);
            foreach ($st->fetchAll() as $row) {
                $names[(int)$row['id']] = ['nickname' => (string)$row['nickname'], 'avatar' => (string)$row['avatar']];
            }
        }
        return [
            'id'        => (int)$n['id'],
            'content'   => (string)$n['content'],
            'status'    => (string)$n['status'],
            'suspects'  => array_map(function ($uid) use ($names) {
                return ['user_id' => $uid, 'nickname' => $names[$uid]['nickname'] ?? '神秘人', 'avatar' => $names[$uid]['avatar'] ?? ''];
            }, $sus),
            'guessed'   => (int)$n['guessed_user'] > 0 ? (int)$n['guessed_user'] : null,
            'from_id'   => (int)$n['from_user'],
            'from_nick' => (string)$n['fn'],
            'created_at' => (string)$n['created_at'],
        ];
    }, $st->fetchAll());
    // 我发出的纸条
    $st = $pdo->prepare("SELECT n.*, u.nickname AS tn, u.avatar AS tav FROM secret_notes n LEFT JOIN users u ON u.id=n.to_user WHERE n.from_user=? ORDER BY n.id DESC LIMIT 30");
    $st->execute([$u['id']]);
    $sent = array_map(function ($n) {
        $sus = json_decode($n['suspects'], true) ?: [];
        return [
            'id'        => (int)$n['id'],
            'content'   => (string)$n['content'],
            'status'    => (string)$n['status'],
            'to_id'     => (int)$n['to_user'],
            'to_nick'   => (string)$n['tn'],
            'suspects'  => $sus,
            'guessed'   => (int)$n['guessed_user'],
            'created_at' => (string)$n['created_at'],
        ];
    }, $st->fetchAll());
    ok(['inbox' => $inbox, 'sent' => $sent]);
}
if ($action === 'note.guess') {
    $u = getOrCreateUser(requireDeviceId());
    $nid = (int)($_POST['note_id'] ?? 0);
    $gid = (int)($_POST['suspect_id'] ?? 0);
    if ($nid <= 0 || $gid <= 0) {
        fail('参数无效');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM secret_notes WHERE id=? AND to_user=? AND status=?');
    $st->execute([$nid, $u['id'], 'pending']);
    $n = $st->fetch();
    if (!$n) {
        fail('纸条不存在或已猜过');
    }
    $sus = json_decode($n['suspects'], true) ?: [];
    if (!in_array($gid, $sus, true)) {
        fail('这个选项不在纸条的嫌疑里');
    }
    $found = $gid === (int)$n['from_user'];
    $status = $found ? 'revealed' : 'guessed';
    $pdo->prepare('UPDATE secret_notes SET status=?, guessed_user=? WHERE id=? AND status=?')
        ->execute([$status, (int)$u['id'], $nid, 'pending']);
    if ($found) {
        notifyUser((int)$n['from_user'], (int)$u['id'], 'note', 0, 'TA 猜中了你递的纸条！');
        notifyUser((int)$u['id'], (int)$n['from_user'], 'note', 0, '你猜中了 TA 的纸条，揭晓啦！');
        ok(['revealed' => true], '猜中啦！纸条是「' . $n['content'] . '」送出的');
    } else {
        ok(['revealed' => false], '猜错啦，纸条继续保持神秘');
    }
}

/* 时光胶囊：写给未来某个时间点的自己或 TA，到期才能开启 */
if ($action === 'capsule.create') {
    $u = getOrCreateUser(requireDeviceId());
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁', 403);
    }
    checkRate(requireDeviceId(), 'last_capsule', 60);
    $content = trim((string)($_POST['content'] ?? ''));
    $days = (int)($_POST['days'] ?? 0);
    $toId = (int)($_POST['to_user_id'] ?? 0);
    $openAt = trim((string)($_POST['open_at'] ?? ''));
    if ($content === '' || mb_strlen($content) > 500) {
        fail('胶囊内容 1-500 字');
    }
    $validDays = [1, 7, 30, 365];
    if ($toId === (int)$u['id']) {
        $toId = 0;
    }
    if ($openAt !== '') {
        $ts = strtotime($openAt);
        if (!$ts || $ts <= time()) {
            fail('开启时间要在未来');
        }
        $openAt = date('Y-m-d H:i:s', $ts);
    } else {
        if (!in_array($days, $validDays, true)) {
            fail('请选择开启时间');
        }
        $openAt = date('Y-m-d H:i:s', time() + $days * 86400);
    }
    $pdo = db();
    if ($toId > 0) {
        $st = $pdo->prepare('SELECT id, nickname FROM users WHERE id=?');
        $st->execute([$toId]);
        if (!$st->fetch()) {
            fail('对方不存在');
        }
    }
    $pdo->prepare('INSERT INTO time_capsules (user_id, to_user, content, open_at, opened, created_at) VALUES (?,?,?,?,0,?)')
        ->execute([(int)$u['id'], $toId, $content, $openAt, now()]);
    $cid = (int)$pdo->lastInsertId();
    ok(['id' => $cid, 'open_at' => $openAt], $toId > 0 ? '胶囊已埋下，到期后送达 TA' : '胶囊已埋下，等未来开启');
}
if ($action === 'capsule.list') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $nowStr = now();
    // 到期未开的先自动解锁
    $pdo->prepare('UPDATE time_capsules SET opened=1 WHERE user_id=? AND opened=0 AND open_at<=?')
        ->execute([(int)$u['id'], $nowStr]);
    $st = $pdo->prepare("SELECT c.*, u.nickname AS tnick FROM time_capsules c LEFT JOIN users u ON u.id=c.to_user WHERE c.user_id=? ORDER BY c.opened ASC, c.open_at DESC LIMIT 50");
    $st->execute([(int)$u['id']]);
    $list = array_map(function ($c) {
        return [
            'id'         => (int)$c['id'],
            'to_user'    => (int)$c['to_user'],
            'to_nick'    => (int)$c['to_user'] > 0 ? (string)$c['tnick'] : '未来的自己',
            'content'    => (string)$c['content'],
            'open_at'    => (string)$c['open_at'],
            'opened'     => (int)$c['opened'],
            'created_at' => (string)$c['created_at'],
        ];
    }, $st->fetchAll());
    $pending = 0;
    $locked = 0;
    foreach ($list as $c) {
        if ($c['opened'] === 1) {
            $pending++;
        } else {
            $locked++;
        }
    }
    ok(['list' => $list, 'pending' => $pending, 'locked' => $locked]);
}
if ($action === 'capsule.reveal') {
    $u = getOrCreateUser(requireDeviceId());
    $cid = (int)($_POST['capsule_id'] ?? 0);
    if ($cid <= 0) {
        fail('参数无效');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM time_capsules WHERE id=? AND user_id=?');
    $st->execute([$cid, (int)$u['id']]);
    $c = $st->fetch();
    if (!$c) {
        fail('胶囊不存在');
    }
    if ((int)$c['opened'] === 0 && strtotime((string)$c['open_at']) > time()) {
        fail('胶囊还没有到开启时间', 403);
    }
    $pdo->prepare('UPDATE time_capsules SET opened=1 WHERE id=?')->execute([$cid]);
    $toNick = '未来的自己';
    if ((int)$c['to_user'] > 0) {
        $st = $pdo->prepare('SELECT nickname FROM users WHERE id=?');
        $st->execute([(int)$c['to_user']]);
        $toNick = (string)$st->fetchColumn() ?: '神秘人';
    }
    ok([
        'id'      => (int)$c['id'],
        'content' => (string)$c['content'],
        'open_at' => (string)$c['open_at'],
        'to_nick' => $toNick,
    ], '胶囊开启成功');
}

/* 纪念日：记录在一起/相识等日期，计算已过天数与下一个周年倒计时 */
if ($action === 'anniv.list') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM anniversaries WHERE user_id=? ORDER BY date ASC LIMIT 100');
    $st->execute([(int)$u['id']]);
    $list = array_map(function ($a) {
        $date = (string)$a['date'];
        $startTs = strtotime($date);
        $start = $startTs ? date('Y-m-d', $startTs) : '';
        $days = 0;
        $next = '';
        $next_days = 0;
        if ($start !== '') {
            $today = date('Y-m-d');
            $days = (int)((strtotime($today) - strtotime($start)) / 86400);
            if ($days < 0) {
                $days = 0;
            }
            // 下一个周年：今年或明年
            $y = (int)date('Y');
            $anniv = date('Y-m-d', strtotime($y . '-' . date('m-d', strtotime($start))));
            if ($anniv < $today) {
                $anniv = date('Y-m-d', strtotime(($y + 1) . '-' . date('m-d', strtotime($start))));
            }
            $next_days = (int)((strtotime($anniv) - strtotime($today)) / 86400);
            $next = $anniv;
        }
        return [
            'id'        => (int)$a['id'],
            'title'     => (string)$a['title'],
            'date'      => $start,
            'days'      => $days,
            'next'      => $next,
            'next_days' => $next_days,
        ];
    }, $st->fetchAll());
    ok(['list' => $list]);
}
if ($action === 'anniv.create') {
    $u = getOrCreateUser(requireDeviceId());
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁', 403);
    }
    checkRate(requireDeviceId(), 'last_anniv', 60);
    $title = trim((string)($_POST['title'] ?? ''));
    $date = trim((string)($_POST['date'] ?? ''));
    $toId = (int)($_POST['to_user_id'] ?? 0);
    if ($title === '' || mb_strlen($title) > 30) {
        fail('纪念日名称 1-30 字');
    }
    $ts = strtotime($date);
    if (!$ts) {
        fail('日期格式不正确');
    }
    $date = date('Y-m-d', $ts);
    if ($toId === (int)$u['id']) {
        $toId = 0;
    }
    $pdo = db();
    if ($toId > 0) {
        $st = $pdo->prepare('SELECT id FROM users WHERE id=?');
        $st->execute([$toId]);
        if (!$st->fetch()) {
            fail('对方不存在');
        }
    }
    $pdo->prepare('INSERT INTO anniversaries (user_id, to_user, title, date, created_at) VALUES (?,?,?,?,?)')
        ->execute([(int)$u['id'], $toId, $title, $date, now()]);
    ok(['id' => (int)$pdo->lastInsertId()], '纪念日已记录');
}
if ($action === 'anniv.update') {
    $u = getOrCreateUser(requireDeviceId());
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        fail('参数无效');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM anniversaries WHERE id=? AND user_id=?');
    $st->execute([$id, (int)$u['id']]);
    $a = $st->fetch();
    if (!$a) {
        fail('纪念日不存在');
    }
    $title = trim((string)($_POST['title'] ?? ''));
    $date = trim((string)($_POST['date'] ?? ''));
    if ($title === '' || mb_strlen($title) > 30) {
        fail('纪念日名称 1-30 字');
    }
    $ts = strtotime($date);
    if (!$ts) {
        fail('日期格式不正确');
    }
    $date = date('Y-m-d', $ts);
    $pdo->prepare('UPDATE anniversaries SET title=?, date=? WHERE id=?')
        ->execute([$title, $date, $id]);
    ok([], '已更新');
}
if ($action === 'anniv.delete') {
    $u = getOrCreateUser(requireDeviceId());
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        fail('参数无效');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM anniversaries WHERE id=? AND user_id=?');
    $st->execute([$id, (int)$u['id']]);
    if (!$st->fetch()) {
        fail('纪念日不存在');
    }
    $pdo->prepare('DELETE FROM anniversaries WHERE id=?')->execute([$id]);
    ok([], '已删除');
}

/* ================= 恋爱实验室 ================= */

/* 恋爱日志：记录甜蜜瞬间，可选仅自己/指定 TA 可见 */
if ($action === 'log.create') {
    $u = getOrCreateUser(requireDeviceId());
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁', 403);
    }
    checkRate(requireDeviceId(), 'last_log', 30);
    $content = trim((string)($_POST['content'] ?? ''));
    $mood = trim((string)($_POST['mood'] ?? 'happy'));
    $toId = (int)($_POST['to_user_id'] ?? 0);
    if ($content === '' || mb_strlen($content) > 300) {
        fail('日记内容 1-300 字');
    }
    $validMoods = ['happy', 'love', 'shy', 'sad', 'sunny'];
    if (!in_array($mood, $validMoods, true)) {
        $mood = 'happy';
    }
    if ($toId === (int)$u['id']) {
        $toId = 0;
    }
    $pdo = db();
    if ($toId > 0) {
        $st = $pdo->prepare('SELECT id FROM users WHERE id=?');
        $st->execute([$toId]);
        if (!$st->fetch()) {
            fail('对方不存在');
        }
    }
    $pdo->prepare('INSERT INTO love_logs (user_id, to_user, mood, content, created_at) VALUES (?,?,?,?,?)')
        ->execute([(int)$u['id'], $toId, $mood, $content, now()]);
    ok(['id' => (int)$pdo->lastInsertId()], '日记已记录');
}
if ($action === 'log.list') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM love_logs WHERE user_id=? OR to_user=? ORDER BY id DESC LIMIT 100');
    $st->execute([(int)$u['id'], (int)$u['id']]);
    $list = array_map(function ($l) use ($u) {
        $mine = (int)$l['user_id'] === (int)$u['id'];
        return [
            'id'         => (int)$l['id'],
            'mine'       => $mine,
            'mood'       => (string)$l['mood'],
            'content'    => (string)$l['content'],
            'to_user'    => (int)$l['to_user'],
            'created_at' => (string)$l['created_at'],
        ];
    }, $st->fetchAll());
    ok(['list' => $list]);
}
if ($action === 'log.delete') {
    $u = getOrCreateUser(requireDeviceId());
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        fail('参数无效');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM love_logs WHERE id=? AND user_id=?');
    $st->execute([$id, (int)$u['id']]);
    if (!$st->fetch()) {
        fail('日记不存在');
    }
    $pdo->prepare('DELETE FROM love_logs WHERE id=?')->execute([$id]);
    ok([], '已删除');
}

/* 情侣绑定：发起方输入对方 user_id，对方确认后绑定，互设昵称 */
if ($action === 'couple.apply') {
    $u = getOrCreateUser(requireDeviceId());
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁', 403);
    }
    $toId = (int)($_POST['to_user_id'] ?? 0);
    if ($toId <= 0 || $toId === (int)$u['id']) {
        fail('请输入对方的用户ID');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM users WHERE id=?');
    $st->execute([$toId]);
    if (!$st->fetch()) {
        fail('对方不存在');
    }
    // 已绑定直接拒绝
    $st = $pdo->prepare("SELECT id FROM couples WHERE status=1 AND (user_a=? OR user_b=?)");
    $st->execute([(int)$u['id'], (int)$u['id']]);
    if ($st->fetch()) {
        fail('你已经有恋人啦');
    }
    $st = $pdo->prepare("SELECT id FROM couples WHERE status=1 AND (user_a=? OR user_b=?)");
    $st->execute([$toId, $toId]);
    if ($st->fetch()) {
        fail('对方已经有恋人啦');
    }
    // 已有待确认申请则更新
    $st = $pdo->prepare('SELECT id FROM couples WHERE user_a=? AND user_b=? AND status=0');
    $st->execute([(int)$u['id'], $toId]);
    if ($st->fetch()) {
        ok([], '已重新发出申请');
    }
    $pdo->prepare('INSERT INTO couples (user_a, user_b, created_at) VALUES (?,?,?)')
        ->execute([(int)$u['id'], $toId, now()]);
    ok([], '已发出绑定申请，等 TA 确认');
}
if ($action === 'couple.confirm') {
    $u = getOrCreateUser(requireDeviceId());
    $cid = (int)($_POST['id'] ?? 0);
    $nick = trim((string)($_POST['nick'] ?? ''));
    if ($cid <= 0) {
        fail('参数无效');
    }
    if ($nick === '' || mb_strlen($nick) > 12) {
        fail('给对方起个昵称吧（1-12 字）');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM couples WHERE id=? AND user_b=? AND status=0');
    $st->execute([$cid, (int)$u['id']]);
    $c = $st->fetch();
    if (!$c) {
        fail('申请不存在或已处理');
    }
    $pdo->prepare('UPDATE couples SET status=1, nick_b=?, bound_at=? WHERE id=?')
        ->execute([$nick, now(), $cid]);
    ok([], '已绑定成功');
}
if ($action === 'couple.status') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM couples WHERE status=1 AND (user_a=? OR user_b=?) LIMIT 1");
    $st->execute([(int)$u['id'], (int)$u['id']]);
    $c = $st->fetch();
    $data = ['bound' => false];
    if ($c) {
        $myNick = ((int)$c['user_a'] === (int)$u['id']) ? $c['nick_b'] : $c['nick_a'];
        $peerId = ((int)$c['user_a'] === (int)$u['id']) ? (int)$c['user_b'] : (int)$c['user_a'];
        $st = $pdo->prepare('SELECT id, nickname FROM users WHERE id=?');
        $st->execute([$peerId]);
        $peer = $st->fetch();
        $data = [
            'bound'    => true,
            'couple_id'=> (int)$c['id'],
            'my_nick'  => (string)$myNick,
            'peer_id'  => $peerId,
            'peer_name'=> $peer ? (string)$peer['nickname'] : '',
            'bound_at' => (string)$c['bound_at'],
        ];
    } else {
        // 待确认申请
        $st = $pdo->prepare("SELECT id, user_a FROM couples WHERE user_b=? AND status=0");
        $st->execute([(int)$u['id']]);
        $req = $st->fetch();
        if ($req) {
            $st = $pdo->prepare('SELECT id, nickname FROM users WHERE id=?');
            $st->execute([(int)$req['user_a']]);
            $from = $st->fetch();
            $data = [
                'bound'    => false,
                'pending'  => true,
                'req_id'   => (int)$req['id'],
                'from_id'  => (int)$req['user_a'],
                'from_name'=> $from ? (string)$from['nickname'] : '',
            ];
        }
    }
    ok($data);
}
if ($action === 'couple.setnick') {
    $u = getOrCreateUser(requireDeviceId());
    $nick = trim((string)($_POST['nick'] ?? ''));
    if ($nick === '' || mb_strlen($nick) > 12) {
        fail('昵称 1-12 字');
    }
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM couples WHERE status=1 AND (user_a=? OR user_b=?) LIMIT 1");
    $st->execute([(int)$u['id'], (int)$u['id']]);
    $c = $st->fetch();
    if (!$c) {
        fail('还没有绑定恋人');
    }
    if ((int)$c['user_a'] === (int)$u['id']) {
        $pdo->prepare('UPDATE couples SET nick_b=? WHERE id=?')->execute([$nick, (int)$c['id']]);
    } else {
        $pdo->prepare('UPDATE couples SET nick_a=? WHERE id=?')->execute([$nick, (int)$c['id']]);
    }
    ok([], '昵称已更新');
}

/* 爱情树：绑定后一起浇水，每日一次 */
if ($action === 'tree.info') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $st = $pdo->prepare("SELECT id FROM couples WHERE status=1 AND (user_a=? OR user_b=?) LIMIT 1");
    $st->execute([(int)$u['id'], (int)$u['id']]);
    $c = $st->fetch();
    if (!$c) {
        ok(['bound' => false]);
    }
    $tree = null;
    $st = $pdo->prepare('SELECT * FROM love_tree WHERE couple_id=?');
    $st->execute([(int)$c['id']]);
    $tree = $st->fetch();
    if (!$tree) {
        $pdo->prepare('INSERT INTO love_tree (couple_id) VALUES (?)')->execute([(int)$c['id']]);
        $st = $pdo->prepare('SELECT * FROM love_tree WHERE couple_id=?');
        $st->execute([(int)$c['id']]);
        $tree = $st->fetch();
    }
    $lv = (int)$tree['level'];
    $need = $lv * 3; // 升下一级所需浇水次数
    $today = date('Y-m-d');
    $wateredToday = (strpos((string)$tree['last_water'], $today) === 0);
    ok([
        'bound'        => true,
        'water'        => (int)$tree['water'],
        'level'        => $lv,
        'next_need'    => $need,
        'progress'     => (int)$tree['water'] % $need,
        'watered_today'=> $wateredToday,
    ]);
}
if ($action === 'tree.water') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $st = $pdo->prepare("SELECT id FROM couples WHERE status=1 AND (user_a=? OR user_b=?) LIMIT 1");
    $st->execute([(int)$u['id'], (int)$u['id']]);
    $c = $st->fetch();
    if (!$c) {
        fail('还没有绑定恋人');
    }
    $st = $pdo->prepare('SELECT * FROM love_tree WHERE couple_id=?');
    $st->execute([(int)$c['id']]);
    $tree = $st->fetch();
    if (!$tree) {
        $pdo->prepare('INSERT INTO love_tree (couple_id) VALUES (?)')->execute([(int)$c['id']]);
        $st = $pdo->prepare('SELECT * FROM love_tree WHERE couple_id=?');
        $st->execute([(int)$c['id']]);
        $tree = $st->fetch();
    }
    $today = date('Y-m-d');
    if (strpos((string)$tree['last_water'], $today) === 0) {
        fail('今天已经浇过水啦');
    }
    $water = (int)$tree['water'] + 1;
    $level = (int)$tree['level'];
    if ($water >= $level * 3) {
        $level++;
    }
    $pdo->prepare('UPDATE love_tree SET water=?, level=?, last_water=? WHERE couple_id=?')
        ->execute([$water, $level, now(), (int)$c['id']]);
    ok(['water' => $water, 'level' => $level], '浇水成功，树儿又长大一点啦');
}

/* 默契问答：每日一题，答案存库，双人同答可比对 */
if ($action === 'quiz.daily') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $today = date('Y-m-d');
    $st = $pdo->prepare('SELECT * FROM quiz_daily WHERE user_id=? AND qdate=?');
    $st->execute([(int)$u['id'], $today]);
    $mine = $st->fetch();
    $qIdx = $mine ? (int)$mine['q_a'] : ((int)$u['id'] + (int)date('d')) % 20;
    ok([
        'qdate'   => $today,
        'q_idx'   => $qIdx,
        'answered'=> (bool)$mine,
        'answer'  => $mine ? (string)$mine['answer'] : '',
    ]);
}
if ($action === 'quiz.answer') {
    $u = getOrCreateUser(requireDeviceId());
    $answer = trim((string)($_POST['answer'] ?? ''));
    $qIdx = (int)($_POST['q_idx'] ?? 0);
    if ($answer === '' || mb_strlen($answer) > 100) {
        fail('答案 1-100 字');
    }
    $pdo = db();
    $today = date('Y-m-d');
    $st = $pdo->prepare('SELECT id FROM quiz_daily WHERE user_id=? AND qdate=?');
    $st->execute([(int)$u['id'], $today]);
    if ($st->fetch()) {
        fail('今天已经答过啦，明天再来');
    }
    $pdo->prepare('INSERT INTO quiz_daily (user_id, qdate, answer, q_a, created_at) VALUES (?,?,?,?,?)')
        ->execute([(int)$u['id'], $today, $answer, $qIdx, now()]);
    ok([], '已提交答案');
}

/* 每日情话签到：连续签到领情话+积分 */
if ($action === 'checkin.do') {
    $u = getOrCreateUser(requireDeviceId());
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁', 403);
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM love_checkin WHERE user_id=?');
    $st->execute([(int)$u['id']]);
    $row = $st->fetch();
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', time() - 86400);
    if ($row && (string)$row['last_date'] === $today) {
        fail('今天已经签过到啦');
    }
    $streak = 1;
    $total = 1;
    if ($row) {
        $streak = ((string)$row['last_date'] === $yesterday) ? (int)$row['streak'] + 1 : 1;
        $total = (int)$row['total'] + 1;
    }
    // 连续 3 天奖 5 分，其他奖 2 分
    $bonus = ($streak >= 3) ? 5 : 2;
    if ($row) {
        $pdo->prepare('UPDATE love_checkin SET streak=?, total=?, last_date=? WHERE user_id=?')
            ->execute([$streak, $total, $today, (int)$u['id']]);
    } else {
        $pdo->prepare('INSERT INTO love_checkin (user_id, streak, total, last_date) VALUES (?,?,?,?)')
            ->execute([(int)$u['id'], $streak, $total, $today]);
    }
    $pdo->prepare('UPDATE users SET score = score + ? WHERE id=?')->execute([$bonus, (int)$u['id']]);
    ok(['streak' => $streak, 'total' => $total, 'bonus' => $bonus], '签到成功，收获情话一朵');
}
if ($action === 'checkin.info') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM love_checkin WHERE user_id=?');
    $st->execute([(int)$u['id']]);
    $row = $st->fetch();
    $streak = $row ? (int)$row['streak'] : 0;
    $total = $row ? (int)$row['total'] : 0;
    $checkedToday = $row && (string)$row['last_date'] === date('Y-m-d');
    ok(['streak' => $streak, 'total' => $total, 'checked_today' => $checkedToday]);
}

/* 恋爱许愿池：写愿望投池，随机捞别人的（脱敏） */
if ($action === 'wish.create') {
    $u = getOrCreateUser(requireDeviceId());
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁', 403);
    }
    checkRate(requireDeviceId(), 'last_wish', 60);
    $content = trim((string)($_POST['content'] ?? ''));
    if ($content === '' || mb_strlen($content) > 100) {
        fail('愿望 1-100 字');
    }
    $pdo = db();
    // 每人最多保留 20 条愿望
    $cnt = (int)$pdo->prepare('SELECT COUNT(*) FROM wish_pool WHERE user_id=?')->execute([(int)$u['id']]) && 0;
    $st = $pdo->prepare('SELECT COUNT(*) c FROM wish_pool WHERE user_id=?');
    $st->execute([(int)$u['id']]);
    $cnt = (int)$st->fetchColumn();
    if ($cnt >= 20) {
        $pdo->prepare('DELETE FROM wish_pool WHERE id=(SELECT id FROM wish_pool WHERE user_id=? ORDER BY id ASC LIMIT 1)')
            ->execute([(int)$u['id']]);
    }
    $pdo->prepare('INSERT INTO wish_pool (user_id, content, created_at) VALUES (?,?,?)')
        ->execute([(int)$u['id'], $content, now()]);
    ok(['id' => (int)$pdo->lastInsertId()], '愿望已投入许愿池');
}
if ($action === 'wish.pick') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    // 随机捞 3 条别人的愿望
    $st = $pdo->query('SELECT content, created_at FROM wish_pool WHERE user_id != ' . (int)$u['id'] . ' ORDER BY RANDOM() LIMIT 3');
    $list = array_map(function ($w) {
        return [
            'content'    => (string)$w['content'],
            'created_at' => (string)$w['created_at'],
        ];
    }, $st->fetchAll());
    ok(['list' => $list]);
}

/* 情侣誓言：绑定后互立誓言，对方可见 */
if ($action === 'vow.get') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM couples WHERE status=1 AND (user_a=? OR user_b=?) LIMIT 1");
    $st->execute([(int)$u['id'], (int)$u['id']]);
    $c = $st->fetch();
    if (!$c) {
        ok(['bound' => false]);
    }
    $st = $pdo->prepare('SELECT * FROM love_vows WHERE couple_id=?');
    $st->execute([(int)$c['id']]);
    $v = $st->fetch();
    $mine = $v ? (((int)$c['user_a'] === (int)$u['id']) ? (string)$v['vow_a'] : (string)$v['vow_b']) : '';
    $peer = $v ? (((int)$c['user_a'] === (int)$u['id']) ? (string)$v['vow_b'] : (string)$v['vow_a']) : '';
    ok(['bound' => true, 'mine' => $mine, 'peer' => $peer]);
}
if ($action === 'vow.set') {
    $u = getOrCreateUser(requireDeviceId());
    $vow = trim((string)($_POST['vow'] ?? ''));
    if ($vow === '' || mb_strlen($vow) > 200) {
        fail('誓言 1-200 字');
    }
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM couples WHERE status=1 AND (user_a=? OR user_b=?) LIMIT 1");
    $st->execute([(int)$u['id'], (int)$u['id']]);
    $c = $st->fetch();
    if (!$c) {
        fail('还没有绑定恋人');
    }
    $cid = (int)$c['id'];
    $st = $pdo->prepare('SELECT * FROM love_vows WHERE couple_id=?');
    $st->execute([$cid]);
    $v = $st->fetch();
    if ($v) {
        if ((int)$c['user_a'] === (int)$u['id']) {
            $pdo->prepare('UPDATE love_vows SET vow_a=?, updated_at=? WHERE couple_id=?')->execute([$vow, now(), $cid]);
        } else {
            $pdo->prepare('UPDATE love_vows SET vow_b=?, updated_at=? WHERE couple_id=?')->execute([$vow, now(), $cid]);
        }
    } else {
        $vowA = ((int)$c['user_a'] === (int)$u['id']) ? $vow : '';
        $vowB = ((int)$c['user_a'] === (int)$u['id']) ? '' : $vow;
        $pdo->prepare('INSERT INTO love_vows (couple_id, vow_a, vow_b, updated_at) VALUES (?,?,?,?)')
            ->execute([$cid, $vowA, $vowB, now()]);
    }
    ok([], '誓言已刻下');
}

/* 恋爱成就墙：行为解锁徽章 */
if ($action === 'ach.list') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM achievements WHERE user_id=?');
    $st->execute([(int)$u['id']]);
    $row = $st->fetch();
    $unlocked = $row ? (json_decode((string)$row['ach_data'], true) ?: []) : [];
    // 汇总统计
    $st = $pdo->prepare('SELECT COUNT(*) FROM love_logs WHERE user_id=?');
    $st->execute([(int)$u['id']]);
    $logCount = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT * FROM love_checkin WHERE user_id=?');
    $st->execute([(int)$u['id']]);
    $cin = $st->fetch();
    $streak = $cin ? (int)$cin['streak'] : 0;
    $total = $cin ? (int)$cin['total'] : 0;
    $st = $pdo->prepare("SELECT id FROM couples WHERE status=1 AND (user_a=? OR user_b=?) LIMIT 1");
    $st->execute([(int)$u['id'], (int)$u['id']]);
    $bound = (bool)$st->fetch();
    $st = $pdo->prepare('SELECT COUNT(*) FROM wish_pool WHERE user_id=?');
    $st->execute([(int)$u['id']]);
    $wishCount = (int)$st->fetchColumn();
    // 已达成徽章
    $all = [
        'first_log'   => ['name' => '初次心动', 'desc' => '写下第一篇恋爱日志', 'got' => $logCount >= 1],
        'log_10'      => ['name' => '日记达人', 'desc' => '累计 10 篇恋爱日志', 'got' => $logCount >= 10],
        'bound'       => ['name' => '缘定此生', 'desc' => '成功绑定恋人', 'got' => $bound],
        'checkin_7'   => ['name' => '甜言蜜语', 'desc' => '连续签到 7 天', 'got' => $streak >= 7],
        'checkin_30'  => ['name' => '情话大师', 'desc' => '累计签到 30 天', 'got' => $total >= 30],
        'wish_3'      => ['name' => '许愿者', 'desc' => '投入 3 个愿望', 'got' => $wishCount >= 3],
        'vow'         => ['name' => '一诺千金', 'desc' => '写下誓言', 'got' => in_array('vow', $unlocked, true)],
    ];
    foreach ($all as $k => $v) {
        $all[$k]['unlocked'] = in_array($k, $unlocked, true) || $v['got'];
    }
    $gotCount = 0;
    foreach ($all as $v) { if ($v['unlocked']) $gotCount++; }
    ok(['list' => array_values($all), 'got' => $gotCount, 'total' => count($all)]);
}
if ($action === 'ach.unlock') {
    $u = getOrCreateUser(requireDeviceId());
    $key = trim((string)($_POST['key'] ?? ''));
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM achievements WHERE user_id=?');
    $st->execute([(int)$u['id']]);
    $row = $st->fetch();
    $unlocked = $row ? (json_decode((string)$row['ach_data'], true) ?: []) : [];
    if (!in_array($key, $unlocked, true)) {
        $unlocked[] = $key;
        $data = json_encode(array_values($unlocked), JSON_UNESCAPED_UNICODE);
        if ($row) {
            $pdo->prepare('UPDATE achievements SET ach_data=?, updated_at=? WHERE user_id=?')->execute([$data, now(), (int)$u['id']]);
        } else {
            $pdo->prepare('INSERT INTO achievements (user_id, ach_data, updated_at) VALUES (?,?,?)')
                ->execute([(int)$u['id'], $data, now()]);
        }
    }
    ok(['key' => $key]);
}

/* 恋爱人格测试：8 题三维计分，题库在 config.php（单一来源），服务端计分防篡改 */
if ($action === 'love.test.get') {
    $u = getOrCreateUser(requireDeviceId());
    $questions = [];
    foreach ($LOVE_QUESTIONS as $q) {
        $questions[] = ['q' => $q['q'], 'opts' => array_map(function ($o) {
            return $o['t'];
        }, $q['opts'])];
    }
    ok(['questions' => $questions, 'tested' => $u['love_type'] !== '', 'type' => $u['love_type'], 'types' => array_map(function ($k, $v) {
        return ['key' => $k, 'name' => $v['name'], 'icon' => $v['icon'], 'desc' => $v['desc'], 'match' => $v['match']];
    }, array_keys($LOVE_TYPES), $LOVE_TYPES)]);
}
if ($action === 'love.test.submit') {
    $u = getOrCreateUser(requireDeviceId());
    $answers = $_POST['answers'] ?? '[]';
    $answers = json_decode((string)$answers, true);
    if (!is_array($answers) || count($answers) !== count($LOVE_QUESTIONS)) {
        fail('答案不完整');
    }
    $votes = ['A' => 0, 'P' => 0, 'D' => 0, 'T' => 0, 'R' => 0, 'W' => 0];
    foreach ($LOVE_QUESTIONS as $i => $q) {
        $idx = (int)($answers[$i] ?? -1);
        if (!isset($q['opts'][$idx])) {
            fail('答案无效');
        }
        $dims = $q['opts'][$idx]['dims'];
        $votes[$dims[0]]++;
        $votes[$dims[1]]++;
    }
    $key = ($votes['A'] >= $votes['P'] ? 'A' : 'P') . ($votes['D'] >= $votes['T'] ? 'D' : 'T') . ($votes['R'] >= $votes['W'] ? 'R' : 'W');
    $type = $LOVE_TYPES[$key];
    $pdo = db();
    $pdo->prepare('UPDATE users SET love_type=? WHERE id=?')->execute([$key, (int)$u['id']]);
    ok(['type' => $key, 'name' => $type['name'], 'icon' => $type['icon'], 'desc' => $type['desc'], 'match' => $type['match']], '你是「' . $type['name'] . '」');
}
if ($action === 'love.test.reset') {
    $u = getOrCreateUser(requireDeviceId());
    db()->prepare("UPDATE users SET love_type='' WHERE id=?")->execute([(int)$u['id']]);
    ok(['reset' => true], '已重置，可以重新测一次');
}

/* 「每日桃花签」：恋爱运势，按 device_id+日期 确定性生成（当日稳定，跨天变化，零存储） */
if ($action === 'fortune.today') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $seed = md5($did . ':' . date('Y-m-d'));
    $n = hexdec(substr($seed, 0, 8));
    $n2 = hexdec(substr($seed, 8, 8));
    $n3 = hexdec(substr($seed, 16, 8));
    $score = 30 + ($n % 71); // 30-100
    if ($score >= 85) {
        $level = '桃花朵朵开';
        $tip = '今日桃花爆炸！主动出击，成功率最高的一天';
    } elseif ($score >= 60) {
        $level = '桃花运在线';
        $tip = '桃花在线，多去星球墙看一眼，说不定有惊喜';
    } elseif ($score >= 40) {
        $level = '桃花酝酿中';
        $tip = '桃花在酝酿，先经营好自己，TA 会注意到';
    } else {
        $level = '桃花待机中';
        $tip = '今天适合攒积分抽盲盒，好运留给明天';
    }
    $YES = ['主动表白', '发一条心动帖', '约 TA 散步', '给 TA 点个赞', '主动打个招呼', '写封小情书', '分享一首歌', '去集市逛逛'];
    $NO = ['深夜emo', '假装高冷', '反复试探', '吃飞醋', '连发消息', '熬夜等回复'];
    $VERSE = [
        '今天的晚风很温柔，适合把心事说出口',
        '你遇见的每个人，都在为你准备惊喜',
        '心动不必等时机，现在就是好时辰',
        '月亮在云后探头，它在替你害羞',
        '所有错过，都是为了此刻的恰好遇见',
        '桃花不着急开，等的人也不着急来',
        '你笑起来的样子，今天会有人想起',
        '缘分正在路上，记得把门留一点缝',
        '今天的你，比昨天更值得被爱',
        '好运会迟到，但从不会缺席',
    ];
    $y1 = $YES[$n % count($YES)];
    $y2 = $YES[$n2 % count($YES)];
    if ($y2 === $y1) {
        $y2 = $YES[($n2 + 3) % count($YES)];
    }
    ok([
        'date'  => date('Y-m-d'),
        'score' => $score,
        'level' => $level,
        'yes'   => [$y1, $y2],
        'no'    => $NO[$n3 % count($NO)],
        'verse' => $VERSE[$n2 % count($VERSE)],
        'tip'   => $tip,
    ]);
}

/* 「每日星球日报」：昨日星球与我（个人）的数据汇总 */
if ($action === 'daily.report') {
    $u = getOrCreateUser(requireDeviceId());
    $pdo = db();
    $y = date('Y-m-d', strtotime('-1 day'));
    $p = $y . '%';
    // 我的：昨日收到的心动
    $st = $pdo->prepare('SELECT COUNT(*) FROM crushes WHERE to_user=? AND created_at LIKE ?');
    $st->execute([$u['id'], $p]);
    $myCrushes = (int)$st->fetchColumn();
    // 我的：昨日访客
    $st = $pdo->prepare('SELECT COUNT(*) FROM visits WHERE owner_id=? AND created_at LIKE ?');
    $st->execute([$u['id'], $p]);
    $myVisits = (int)$st->fetchColumn();
    // 我的帖子昨日新增赞/评论
    $st = $pdo->prepare('SELECT COUNT(*) FROM likes l JOIN posts pst ON pst.id=l.post_id WHERE pst.user_id=? AND l.created_at LIKE ?');
    $st->execute([$u['id'], $p]);
    $myLikes = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM comments c JOIN posts pst ON pst.id=c.post_id WHERE pst.user_id=? AND c.created_at LIKE ?');
    $st->execute([$u['id'], $p]);
    $myComments = (int)$st->fetchColumn();
    // 星球昨日：新帖 / 新人 / 新匹配（配对中较晚一方在昨日，即配对形成于昨日）
    $st = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE created_at LIKE ?');
    $st->execute([$p]);
    $glPosts = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_at LIKE ?');
    $st->execute([$p]);
    $glUsers = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM crushes c1 WHERE c1.created_at LIKE ? AND EXISTS (SELECT 1 FROM crushes c2 WHERE c2.from_user=c1.to_user AND c2.to_user=c1.from_user AND c2.created_at <= c1.created_at)');
    $st->execute([$p]);
    $glMatches = (int)$st->fetchColumn();
    ok([
        'mine'   => ['crushes' => $myCrushes, 'visits' => $myVisits, 'likes' => $myLikes, 'comments' => $myComments],
        'planet' => ['posts' => $glPosts, 'users' => $glUsers, 'matches' => $glMatches],
    ]);
}

/* ---------------- CP 缘分值（心动匹配的进阶互动分） ---------------- */

/**
 * 计算两人缘分值 0-100（数据均来自真实互动记录，无前端参与）：
 * 双向心动 +45 / 单向心动 +10 / 好友 +10 / 好友时长每5天+1(上限15)
 * 私信往来 +3 起随消息数增长(上限10) / 点赞·评论·礼物互动每项+2(上限20)
 */
function cpScore(int $me, int $other): array
{
    $pdo = db();
    if ($me === $other) {
        return ['score' => 100, 'level' => 'soulmate', 'level_name' => '灵魂伴侣', 'slogan' => '你就是你自己最坚定的爱', 'detail' => ['mutual' => true, 'friend_days' => 0, 'msg_count' => 0, 'interact_count' => 0]];
    }
    $score = 0;
    $detail = ['mutual' => false, 'friend_days' => 0, 'msg_count' => 0, 'interact_count' => 0];

    // 双向心动 +45
    $st = $pdo->prepare('SELECT 1 FROM crushes c1 JOIN crushes c2 ON c2.from_user=c1.to_user AND c2.to_user=c1.from_user WHERE c1.from_user=? AND c1.to_user=?');
    $st->execute([$me, $other]);
    if ($st->fetch()) {
        $detail['mutual'] = true;
        $score += 45;
    } else {
        // 单向心动（任意方向）+10
        $st = $pdo->prepare('SELECT 1 FROM crushes WHERE (from_user=? AND to_user=?) OR (from_user=? AND to_user=?)');
        $st->execute([$me, $other, $other, $me]);
        if ($st->fetch()) $score += 10;
    }

    // 好友 +10，好友时长每 5 天 +1（上限 15）
    $a = min($me, $other);
    $b = max($me, $other);
    $st = $pdo->prepare('SELECT created_at FROM friends WHERE user_a=? AND user_b=?');
    $st->execute([$a, $b]);
    $fsince = $st->fetchColumn();
    if ($fsince) {
        $score += 10;
        $detail['friend_days'] = max(0, (int)((time() - strtotime($fsince)) / 86400));
        $score += min(15, (int)($detail['friend_days'] / 5));
    }

    // 私信往来：有消息 +3，消息越多越高（上限 10）
    $st = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE (from_user=? AND to_user=?) OR (from_user=? AND to_user=?)');
    $st->execute([$me, $other, $other, $me]);
    $detail['msg_count'] = (int)$st->fetchColumn();
    if ($detail['msg_count'] > 0) {
        $score += min(10, 3 + (int)floor(log($detail['msg_count'] + 1, 2)) * 2);
    }

    // 点赞/评论/礼物双向互动（上限 20）
    $interact = 0;
    $st = $pdo->prepare('SELECT COUNT(*) FROM likes l JOIN posts p ON p.id=l.post_id WHERE l.user_id=? AND p.user_id=?');
    $st->execute([$me, $other]);
    $interact += (int)$st->fetchColumn();
    $st->execute([$other, $me]);
    $interact += (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.user_id=? AND p.user_id=?');
    $st->execute([$me, $other]);
    $interact += (int)$st->fetchColumn();
    $st->execute([$other, $me]);
    $interact += (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM gift_logs WHERE (from_user=? AND to_user=?) OR (from_user=? AND to_user=?)');
    $st->execute([$me, $other, $other, $me]);
    $interact += (int)$st->fetchColumn();
    $detail['interact_count'] = $interact;
    $score += min(20, $interact * 2);

    $score = min(100, (int)$score);
    // 等级：缘分分越高描述越甜
    $levels = [
        [95, 'soulmate', '灵魂伴侣', '命中注定，此生挚爱'],
        [80, 'destiny',  '天生一对', '遇见就是故事的开始'],
        [60, 'bosom',    '相见恨晚', '聊不完的话题，藏不住的心动'],
        [40, 'liking',   '互有好感', '你们之间有点东西'],
        [20, 'fate',     '有缘相识', '缘分的种子已经种下'],
        [0,  'stranger', '缘未到',   '先打个招呼吧'],
    ];
    $lv = $levels[count($levels) - 1];
    foreach ($levels as $l) {
        if ($score >= $l[0]) { $lv = $l; break; }
    }
    return ['score' => $score, 'level' => $lv[1], 'level_name' => $lv[2], 'slogan' => $lv[3], 'detail' => $detail];
}

if ($action === 'cp.value') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $toId = (int)($_POST['to_user_id'] ?? $_GET['to_user_id'] ?? 0);
    if ($toId <= 0) {
        fail('目标无效');
    }
    $st = db()->prepare('SELECT id FROM users WHERE id=?');
    $st->execute([$toId]);
    if (!$st->fetch()) {
        fail('用户不存在', 404);
    }
    ok(cpScore((int)$u['id'], $toId), '缘分值已计算');
}

/* ---------------- 匿名提问箱 ---------------- */

if ($action === 'question.ask') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $toId = (int)($_POST['to_user_id'] ?? 0);
    $content = trim((string)($_POST['content'] ?? ''));
    if ($toId <= 0) {
        fail('目标无效');
    }
    if ($toId === (int)$u['id']) {
        fail('不能给自己提问');
    }
    $st = db()->prepare('SELECT id FROM users WHERE id=?');
    $st->execute([$toId]);
    if (!$st->fetch()) {
        fail('用户不存在', 404);
    }
    if (mb_strlen($content) < 1 || mb_strlen($content) > 300) {
        fail('提问长度需为 1-300 字符');
    }
    checkRate($did, 'last_comment', 2);
    guardFlood($did);
    db()->prepare('INSERT INTO questions (to_user, from_user, content, created_at) VALUES (?,?,?,?)')
        ->execute([$toId, $u['id'], $content, now()]);
    ok([], '已匿名提问');
}

if ($action === 'question.list') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $st = db()->prepare("SELECT * FROM questions WHERE to_user=? ORDER BY (reply='') DESC, id DESC");
    $st->execute([$u['id']]);
    $list = array_map(static function ($q) {
        return [
            'id'         => (int)$q['id'],
            'content'    => (string)$q['content'],
            'reply'      => (string)$q['reply'],
            'answered'   => (string)$q['reply'] !== '',
            'created_at' => (string)$q['created_at'],
            'replied_at' => (string)$q['replied_at'],
        ];
    }, $st->fetchAll());
    ok(['list' => $list]);
}

if ($action === 'question.reply') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $qid = (int)($_POST['question_id'] ?? 0);
    $reply = trim((string)($_POST['reply'] ?? ''));
    if (mb_strlen($reply) < 1 || mb_strlen($reply) > 500) {
        fail('回复长度需为 1-500 字符');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT to_user, from_user FROM questions WHERE id=?');
    $st->execute([$qid]);
    $q = $st->fetch();
    if (!$q) {
        fail('提问不存在', 404);
    }
    if ((int)$q['to_user'] !== (int)$u['id']) {
        fail('无权回复');
    }
    $pdo->prepare('UPDATE questions SET reply=?, replied_at=? WHERE id=?')->execute([$reply, now(), $qid]);
    // 通知提问者（提问者对自己可见，对被提问者匿名）
    $fromId = (int)$q['from_user'];
    if ($fromId > 0 && $fromId !== (int)$u['id']) {
        notifyUser($fromId, (int)$u['id'], 'question_reply', 0, '你的提问被回复啦');
    }
    ok([], '已回复');
}

/* ---------------- 漂流瓶 ---------------- */

if ($action === 'bottle.throw') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $content = trim((string)($_POST['content'] ?? ''));
    if (mb_strlen($content) < 1 || mb_strlen($content) > 500) {
        fail('内容长度需为 1-500 字符');
    }
    checkRate($did, 'last_bottle', RATE_BOTTLE);
    guardFlood($did);
    db()->prepare('INSERT INTO bottles (owner_id, content, created_at) VALUES (?,?,?)')
        ->execute([$u['id'], $content, now()]);
    ok([], '瓶子已扔出，等待有缘人');
}

if ($action === 'bottle.pick') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $pdo = db();
    // 每日次数（先查）
    $st = $pdo->prepare('SELECT COUNT(*) FROM bottle_picks WHERE user_id=? AND created_at >= ?');
    $st->execute([$u['id'], date('Y-m-d 00:00:00')]);
    $pickedToday = (int)$st->fetchColumn();
    if ($pickedToday >= BOTTLE_DAILY_MAX) {
        fail('今天捞了太多瓶子啦，明天再来吧');
    }
    checkRate($did, 'last_bottle', RATE_BOTTLE);
    $st = $pdo->prepare(
        'SELECT b.id, b.content, b.created_at FROM bottles b
         WHERE b.owner_id != ?
           AND NOT EXISTS (SELECT 1 FROM bottle_picks p WHERE p.bottle_id = b.id AND p.user_id = ?)
         ORDER BY RANDOM() LIMIT 1'
    );
    $st->execute([$u['id'], $u['id']]);
    $b = $st->fetch();
    if (!$b) {
        fail('海里暂时没有瓶子啦，去扔一个吧');
    }
    $pdo->prepare('INSERT INTO bottle_picks (bottle_id, user_id, created_at) VALUES (?,?,?)')
        ->execute([$b['id'], $u['id'], now()]);
    ok([
        'bottle_id'  => (int)$b['id'],
        'content'    => (string)$b['content'],
        'created_at' => (string)$b['created_at'],
        'left_today' => BOTTLE_DAILY_MAX - $pickedToday - 1,
    ]);
}

if ($action === 'bottle.reply') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $bid = (int)($_POST['bottle_id'] ?? 0);
    $content = trim((string)($_POST['content'] ?? ''));
    if (mb_strlen($content) < 1 || mb_strlen($content) > 500) {
        fail('回应长度需为 1-500 字符');
    }
    checkRate($did, 'last_bottle', RATE_BOTTLE);
    guardFlood($did);
    $pdo = db();
    $st = $pdo->prepare('SELECT owner_id FROM bottles WHERE id=?');
    $st->execute([$bid]);
    $ownerId = (int)$st->fetchColumn();
    if (!$ownerId) {
        fail('瓶子不存在', 404);
    }
    if ($ownerId === (int)$u['id']) {
        fail('不能回应自己的瓶子');
    }
    $pdo->prepare('INSERT INTO bottle_replies (bottle_id, owner_id, content, created_at) VALUES (?,?,?,?)')
        ->execute([$bid, $u['id'], $content, now()]);
    notifyUser($ownerId, (int)$u['id'], 'bottle_reply', 0, '你的漂流瓶收到回应啦');
    ok([], '回应已送出');
}

if ($action === 'bottle.mine') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM bottles WHERE owner_id=? ORDER BY id DESC LIMIT 50');
    $st->execute([$u['id']]);
    $list = array_map(static function ($b) use ($pdo) {
        $st2 = $pdo->prepare('SELECT content, created_at FROM bottle_replies WHERE bottle_id=? ORDER BY id');
        $st2->execute([$b['id']]);
        return [
            'id'         => (int)$b['id'],
            'content'    => (string)$b['content'],
            'created_at' => (string)$b['created_at'],
            'replies'    => array_map(static function ($r) {
                return ['content' => (string)$r['content'], 'created_at' => (string)$r['created_at']];
            }, $st2->fetchAll()),
        ];
    }, $st->fetchAll());
    ok(['list' => $list]);
}

/* ---------------- 礼物互动 ---------------- */

if ($action === 'gift.list') {
    $u = getOrCreateUser(requireDeviceId());
    $rows = db()->query('SELECT * FROM gifts ORDER BY price')->fetchAll();
    ok(['list' => array_map(static function ($g) {
        return ['id' => (int)$g['id'], 'name' => (string)$g['name'], 'icon' => (string)$g['icon'], 'price' => (int)$g['price']];
    }, $rows)]);
}

if ($action === 'gift.send') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $postId = (int)($_POST['post_id'] ?? 0);
    $giftId = (int)($_POST['gift_id'] ?? 0);
    checkRate($did, 'last_gift', 2);
    $pdo = db();
    $st = $pdo->prepare('SELECT price, name, icon FROM gifts WHERE id=?');
    $st->execute([$giftId]);
    $gift = $st->fetch();
    if (!$gift) {
        fail('礼物不存在', 404);
    }
    $st = $pdo->prepare('SELECT user_id FROM posts WHERE id=? AND status=0');
    $st->execute([$postId]);
    $toId = (int)$st->fetchColumn();
    if (!$toId) {
        fail('帖子不存在', 404);
    }
    if ($toId === (int)$u['id']) {
        fail('不能给自己送礼物');
    }
    $st = $pdo->prepare('SELECT score FROM users WHERE id=?');
    $st->execute([$u['id']]);
    $myScore = (int)$st->fetchColumn();
    $price = (int)$gift['price'];
    // VIP（vip_level>0，如站长 V100）无限积分：不校验余额、不扣分，可一直送礼帮别人升级
    $isVip = (int)($u['vip_level'] ?? 0) > 0;
    if (!$isVip && $myScore < $price) {
        fail('积分不足，还差 ' . ($price - $myScore) . ' 分');
    }
    // 事务：扣发送者、接收者得 50%、记录
    $pdo->beginTransaction();
    try {
        if (!$isVip) {
            $pdo->prepare('UPDATE users SET score = score - ? WHERE id=?')->execute([$price, $u['id']]);
        }
        addScore($toId, intdiv($price, 2));
        $pdo->prepare('INSERT INTO gift_logs (from_user, to_user, post_id, gift_id, created_at) VALUES (?,?,?,?,?)')
            ->execute([$u['id'], $toId, $postId, $giftId, now()]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail('赠送失败，请重试');
    }
    notifyUser($toId, (int)$u['id'], 'post_gift', $postId, '收到礼物 ' . $gift['icon'] . ' ' . $gift['name']);
    ok(['name' => (string)$gift['name'], 'icon' => (string)$gift['icon'], 'price' => $price], '送出 ' . $gift['name']);
}

/* ---------------- 私信 ---------------- */

if ($action === 'chat.send') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁，无法发送消息', 403);
    }
    $friendDid = trim((string)($_POST['to_device_id'] ?? ''));
    $ctype = (string)($_POST['content_type'] ?? 'text');
    if (!in_array($ctype, ['text', 'image', 'voice', 'video'], true)) {
        $ctype = 'text';
    }
    $content = trim((string)($_POST['content'] ?? ''));
    if ($ctype === 'text') {
        if (mb_strlen($content) < 1 || mb_strlen($content) > 2000) {
            fail('消息长度需为 1-2000 字符');
        }
        guardDuplicate((int)$u['id'], $content);
    } else if ($ctype === 'video') {
        // 视频：仅允许 uploads/chat/ 下的本地视频
        if (!preg_match('#^uploads/chat/[A-Za-z0-9_\-\.]+\.(mp4|webm)$#', $content)) {
            fail('视频路径不合法');
        }
    } else {
        // 图片/语音：仅允许本地上传路径
        if (!preg_match('#^uploads/(chat|voice)/[A-Za-z0-9_\-\.]+\.(jpg|jpeg|png|gif|webp|webm|ogg|mp3|m4a)$#', $content)) {
            fail('文件路径不合法');
        }
    }
    checkRate($did, 'last_msg', 2);
    guardFlood($did);
    $fid = requireFriendPair((int)$u['id'], $friendDid);
    db()->prepare('INSERT INTO messages (from_user, to_user, content, content_type, created_at) VALUES (?,?,?,?,?)')
        ->execute([$u['id'], $fid, $content, $ctype, now()]);
    $newId = (int)db()->lastInsertId();
    logAction($did, 'chat');
    // 生日祝福彩蛋：双方触发满屏蛋糕（发送方立即播，接收方打开聊天时播）
    $birthday = false;
    if ($ctype === 'text') {
        $bw = ['生日快乐', '生快', '生日快', 'happy birthday', '🎂', '蛋糕'];
        foreach ($bw as $w) {
            if (mb_stripos($content, $w) !== false) {
                $birthday = true;
                break;
            }
        }
    }
    ok(['id' => $newId, 'birthday' => $birthday], '已发送');
}

if ($action === 'chat.history') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $friendDid = trim((string)($_GET['friend_device_id'] ?? $_POST['friend_device_id'] ?? ''));
    $beforeId = (int)($_GET['before_id'] ?? $_POST['before_id'] ?? 0);
    $limit = min(100, max(1, (int)($_GET['limit'] ?? $_POST['limit'] ?? 50)));
    $fid = requireFriendPair((int)$u['id'], $friendDid);
    $pdo = db();
    // 取 beforeId 之前（或最新）的 limit 条，再按 id 正序返回
    $st = $pdo->prepare(
        'SELECT id, from_user, content, content_type, created_at, read FROM messages
         WHERE ((from_user=? AND to_user=?) OR (from_user=? AND to_user=?))
           AND id < ? ORDER BY id DESC LIMIT ?'
    );
    $st->execute([$u['id'], $fid, $fid, $u['id'], ($beforeId > 0 ? $beforeId : PHP_INT_MAX), $limit]);
    $rows = array_reverse($st->fetchAll());
    $myId = (int)$u['id'];
    $list = array_map(function ($m) use ($myId) {
        return [
            'id'         => (int)$m['id'],
            'from_user'  => (int)$m['from_user'],
            'mine'       => (int)$m['from_user'] === $myId,
            'content'    => (string)$m['content'],
            'content_type' => (string)($m['content_type'] ?? 'text'),
            'created_at' => (string)$m['created_at'],
            'read'       => (int)$m['read'] === 1,
        ];
    }, $rows);
    // 顺手把对方发来的消息标记已读
    $pdo->prepare('UPDATE messages SET read=1 WHERE from_user=? AND to_user=? AND read=0')
        ->execute([$fid, $u['id']]);
    ok(['list' => $list, 'friend_device_id' => $friendDid]);
}

if ($action === 'chat.unread') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $st = db()->prepare(
        'SELECT COUNT(*) FROM messages WHERE to_user=? AND read=0'
    );
    $st->execute([$u['id']]);
    $total = (int)$st->fetchColumn();
    // 按好友分组未读数 + 最新一条消息预览
    $st = db()->prepare(
        'SELECT m.from_user, u.device_id, u.nickname, u.avatar, COUNT(*) AS n
         FROM messages m JOIN users u ON u.id = m.from_user
         WHERE m.to_user=? AND m.read=0
         GROUP BY m.from_user ORDER BY MAX(m.id) DESC'
    );
    $st->execute([$u['id']]);
    $per = array_map(function ($r) {
        return [
            'device_id' => (string)$r['device_id'],
            'nickname'  => (string)$r['nickname'],
            'avatar'    => (string)($r['avatar'] ?? ''),
            'unread'    => (int)$r['n'],
        ];
    }, $st->fetchAll());
    ok(['total' => $total, 'per_friend' => $per]);
}

if ($action === 'chat.read') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $friendDid = trim((string)($_POST['friend_device_id'] ?? ''));
    $st = db()->prepare('SELECT id FROM users WHERE device_id=?');
    $st->execute([$friendDid]);
    $fid = (int)$st->fetchColumn();
    if ($fid) {
        db()->prepare('UPDATE messages SET read=1 WHERE from_user=? AND to_user=? AND read=0')
            ->execute([$fid, $u['id']]);
    }
    ok(null, '已读');
}

/* ---------------- 管理后台 ---------------- */

if ($action === 'admin.login') {
    $did = requireDeviceId();
    $pwd = (string)($_POST['password'] ?? '');
    // 防爆破：失败次数达上限后按 IP 锁定一段时间
    $ip = clientIp();
    $pdo = db();
    if ($ip !== '') {
        $st = $pdo->prepare('SELECT fails, lock_until FROM admin_login_attempts WHERE ip = ?');
        $st->execute([$ip]);
        $att = $st->fetch();
        if ($att && $att['lock_until'] !== '' && strtotime($att['lock_until']) > time()) {
            $mins = ceil((strtotime($att['lock_until']) - time()) / 60);
            fail('尝试次数过多，请 ' . $mins . ' 分钟后再试', 429);
        }
    }
    // 密码校验：优先 password_verify 哈希，未配置哈希时回退明文比较（兼容旧部署）
    $okPwd = defined('ADMIN_PASSWORD_HASH') && ADMIN_PASSWORD_HASH !== ''
        ? password_verify($pwd, ADMIN_PASSWORD_HASH)
        : hash_equals(ADMIN_PASSWORD, $pwd);
    if (!$okPwd) {
        if ($ip !== '') {
            $st = $pdo->prepare('SELECT fails FROM admin_login_attempts WHERE ip = ?');
            $st->execute([$ip]);
            $fails = (int)$st->fetchColumn();
            $fails++;
            if ($fails >= ADMIN_MAX_FAILS) {
                $lock = date('Y-m-d H:i:s', time() + ADMIN_LOCK_MINUTES * 60);
                $pdo->prepare('UPDATE admin_login_attempts SET fails=0, lock_until=? WHERE ip=?')
                    ->execute([$lock, $ip]);
            } else {
                // 兼容旧版 SQLite：先 UPDATE 后 INSERT
                $up = $pdo->prepare('UPDATE admin_login_attempts SET fails=?, lock_until=? WHERE ip=?');
                $up->execute([$fails, '', $ip]);
                if ($up->rowCount() === 0) {
                    $pdo->prepare('INSERT INTO admin_login_attempts (ip, fails, lock_until) VALUES (?,?,?)')
                        ->execute([$ip, $fails, '']);
                }
            }
            fail('密码错误，剩余 ' . (ADMIN_MAX_FAILS - $fails) . ' 次机会', 401);
        }
        fail('密码错误', 401);
    }
    // 登录成功：清除失败记录
    if ($ip !== '') {
        $pdo->prepare('DELETE FROM admin_login_attempts WHERE ip = ?')->execute([$ip]);
    }
    $u = getOrCreateUser($did);
    if ($u['role'] !== 'super') {
        db()->prepare('UPDATE users SET role=? WHERE id=?')->execute(['super', $u['id']]);
    }
    ok(['token' => issueAdminToken($did)], '登录成功');
}

if ($action === 'admin.overview') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $pdo = db();
    $counts = [];
    foreach ([
        'total_users' => 'SELECT COUNT(*) FROM users',
        'total_posts' => 'SELECT COUNT(*) FROM posts WHERE status=0',
        'total_comments' => 'SELECT COUNT(*) FROM comments WHERE status=0',
        'today_posts' => "SELECT COUNT(*) FROM posts WHERE status=0 AND date(created_at) = date('now','localtime')",
        'today_users' => "SELECT COUNT(*) FROM users WHERE date(created_at) = date('now','localtime')",
    ] as $k => $sql) {
        $counts[$k] = (int)$pdo->query($sql)->fetchColumn();
    }
    ok($counts);
}

if ($action === 'admin.posts') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $page = max(1, (int)($_POST['page'] ?? 1));
    $limit = 20;
    $st = db()->prepare(
        "SELECT p.*, u.nickname, u.anon_nickname, u.device_id FROM posts p JOIN users u ON u.id = p.user_id
         ORDER BY p.id DESC LIMIT ? OFFSET ?"
    );
    $st->execute([$limit, ($page - 1) * $limit]);
    $list = array_map(fn($p) => formatPost($p, null, true), $st->fetchAll());
    ok(['list' => $list, 'page' => $page]);
}

/* ---------------- IP 封禁管理 ---------------- */

if ($action === 'admin.ip_list') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $rows = db()->query('SELECT ip, reason, created_at FROM banned_ips ORDER BY created_at DESC')->fetchAll();
    ok(['list' => $rows]);
}

if ($action === 'admin.ip_ban') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $ip = trim((string)($_POST['ip'] ?? ''));
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        fail('IP 地址不合法');
    }
    $reason = trim((string)($_POST['reason'] ?? ''));
    // 兼容旧版 SQLite：先 UPDATE 后 INSERT
    $up = db()->prepare('UPDATE banned_ips SET reason=?, created_at=? WHERE ip=?');
    $up->execute([$reason, now(), $ip]);
    if ($up->rowCount() === 0) {
        db()->prepare('INSERT INTO banned_ips (ip, reason, created_at) VALUES (?,?,?)')
            ->execute([$ip, $reason, now()]);
    }
    ok(null, '已封禁 ' . $ip);
}

if ($action === 'admin.ip_unban') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $ip = trim((string)($_POST['ip'] ?? ''));
    if ($ip === '') {
        fail('IP 地址不合法');
    }
    db()->prepare('DELETE FROM banned_ips WHERE ip = ?')->execute([$ip]);
    ok(null, '已解封');
}

/* ---------------- 音乐管理（排序 / 重命名） ---------------- */

/** 读取 .order.json 中的文件名数组 */
function musicOrderList(string $dir): array
{
    $path = rtrim($dir, '/') . '/.order.json';
    if (is_file($path)) {
        $arr = json_decode((string)file_get_contents($path), true);
        if (is_array($arr)) {
            return array_values(array_filter($arr, 'is_string'));
        }
    }
    return [];
}

/** 写入 .order.json */
function saveMusicOrder(string $dir, array $names): void
{
    $path = rtrim($dir, '/') . '/.order.json';
    file_put_contents($path, json_encode($names, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @chmod($path, 0644);
}

if ($action === 'admin.music_order') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $dir = rtrim(MUSIC_DIR, '/');
    if (!is_dir($dir)) {
        fail('音乐目录不存在');
    }
    $raw = (string)($_POST['order'] ?? '[]');
    $names = json_decode($raw, true);
    if (!is_array($names)) {
        fail('排序数据不合法');
    }
    // 只保留目录中真实存在的音频文件
    $valid = [];
    foreach ($names as $n) {
        if (!is_string($n)) {
            continue;
        }
        $base = basename($n);
        if ($base !== $n) {
            continue; // 拒绝路径穿越
        }
        if (is_file($dir . '/' . $n)) {
            $valid[] = $n;
        }
    }
    saveMusicOrder($dir, $valid);
    ok(['order' => $valid], '播放顺序已保存');
}

if ($action === 'admin.music_rename') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $dir = rtrim(MUSIC_DIR, '/');
    if (!is_dir($dir)) {
        fail('音乐目录不存在');
    }
    $old = basename((string)($_POST['old_name'] ?? ''));
    $new = basename((string)($_POST['new_name'] ?? ''));
    if ($old === '' || $new === '' || $old === $new) {
        fail('文件名不合法');
    }
    $new = trim(preg_replace('~[\x00-\x1f\x7f<>:"/\\\\|?*]~', '', $new));
    $ext = strtolower(pathinfo($new, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp3', 'flac', 'wav', 'ogg', 'm4a', 'aac'], true)) {
        $new = pathinfo($new, PATHINFO_FILENAME) . '.' . (strtolower(pathinfo($old, PATHINFO_EXTENSION)) ?: 'mp3');
    }
    if ($new === '' || $new === $old) {
        fail('新文件名不合法');
    }
    if (!is_file($dir . '/' . $old)) {
        fail('原文件不存在');
    }
    if (is_file($dir . '/' . $new)) {
        fail('同名文件已存在');
    }
    if (!@rename($dir . '/' . $old, $dir . '/' . $new)) {
        // 跨设备兜底：复制后删除原文件
        if (!@copy($dir . '/' . $old, $dir . '/' . $new) || !@unlink($dir . '/' . $old)) {
            fail('重命名失败');
        }
    }
    @chmod($dir . '/' . $new, 0644);
    // 同步更新 .order.json
    $order = musicOrderList($dir);
    foreach ($order as $i => $n) {
        if ($n === $old) {
            $order[$i] = $new;
        }
    }
    saveMusicOrder($dir, $order);
    ok(null, '已重命名为 ' . $new);
}

if ($action === 'admin.post_delete') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $id = (int)($_POST['post_id'] ?? 0);
    db()->prepare('UPDATE posts SET status=1 WHERE id=?')->execute([$id]);
    ok(null, '已删除');
}

if ($action === 'admin.comments') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $page = max(1, (int)($_POST['page'] ?? 1));
    $limit = 20;
    $st = db()->prepare(
        "SELECT c.*, u.nickname, p.content AS post_content FROM comments c
         JOIN users u ON u.id = c.user_id
         LEFT JOIN posts p ON p.id = c.post_id
         ORDER BY c.id DESC LIMIT ? OFFSET ?"
    );
    $st->execute([$limit, ($page - 1) * $limit]);
    $list = $st->fetchAll();
    foreach ($list as &$c) {
        $c['id'] = (int)$c['id'];
        $c['post_id'] = (int)$c['post_id'];
    }
    unset($c);
    ok(['list' => $list, 'page' => $page]);
}

if ($action === 'admin.comment_delete') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $id = (int)($_POST['comment_id'] ?? 0);
    $pdo = db();
    $st = $pdo->prepare('SELECT post_id FROM comments WHERE id=? AND status=0');
    $st->execute([$id]);
    $pid = $st->fetchColumn();
    if (!$pid) {
        fail('评论不存在');
    }
    $pdo->prepare('UPDATE comments SET status=1 WHERE id=?')->execute([$id]);
    $pdo->prepare('UPDATE posts SET comment_count = MAX(0, comment_count - 1) WHERE id=?')->execute([$pid]);
    ok(null, '已删除');
}

if ($action === 'admin.users') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $page = max(1, (int)($_POST['page'] ?? 1));
    $limit = 20;
    $st = db()->prepare('SELECT * FROM users ORDER BY id DESC LIMIT ? OFFSET ?');
    $st->execute([$limit, ($page - 1) * $limit]);
    $list = array_map(fn($u) => formatUser($u), $st->fetchAll());
    ok(['list' => $list, 'page' => $page]);
}

if ($action === 'admin.user_role') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $id = (int)($_POST['id'] ?? 0);
    $role = (string)($_POST['role'] ?? 'user');
    if (!in_array($role, ['user', 'super'], true)) {
        fail('角色不合法');
    }
    db()->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role, $id]);
    ok([], '已更新角色');
}

if ($action === 'admin.cleanup_inactive') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    // 清理不活跃用户积分：N 天未访问的账号积分归 0（账号/帖子/关系保留，省库空间）
    $days = min(365, max(1, (int)($_POST['days'] ?? 15)));
    $cut = date('Y-m-d H:i:s', time() - $days * 86400);
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE last_active < ? AND score > 0');
    $st->execute([$cut]);
    $n = (int)$st->fetchColumn();
    if ($n > 0) {
        $pdo->prepare('UPDATE users SET score = 0 WHERE last_active < ? AND score > 0')->execute([$cut]);
    }
    ok(['cleaned' => $n], '已清理 ' . $n . ' 个 ' . $days . ' 天未活跃用户的积分');
}

if ($action === 'admin.user_ban') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        fail('用户不存在');
    }
    // 不能封禁超管自己
    $st = db()->prepare('SELECT role FROM users WHERE id=?');
    $st->execute([$id]);
    $role = $st->fetchColumn();
    if ($role === false) {
        fail('用户不存在');
    }
    if ($role === 'super') {
        fail('不能封禁超管');
    }
    db()->prepare('UPDATE users SET banned=1 WHERE id=?')->execute([$id]);
    ok(null, '已封禁该用户');
}

if ($action === 'admin.user_unban') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        fail('用户不存在');
    }
    db()->prepare('UPDATE users SET banned=0 WHERE id=?')->execute([$id]);
    ok(null, '已解封该用户');
}

if ($action === 'admin.reports') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $status = (int)($_POST['status'] ?? 0);
    if ($status !== 0 && $status !== 1) {
        $status = 0;
    }
    $page = max(1, (int)($_POST['page'] ?? 1));
    $limit = min(50, max(1, (int)($_POST['limit'] ?? 20)));
    $pdo = db();
    $where = 'r.status = ?';
    $params = [$status];
    $st = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $st = $pdo->prepare(
        "SELECT r.*, u.nickname AS reporter_nickname,
                COALESCE(p.content, '') AS post_content,
                COALESCE(p.user_id, 0) AS post_author_id,
                COALESCE(pu.nickname, '') AS post_author_nickname,
                COALESCE(c.content, '') AS comment_content,
                COALESCE(c.user_id, 0) AS comment_author_id,
                COALESCE(cu.nickname, '') AS comment_author_nickname
         FROM reports r
         JOIN users u ON u.id = r.reporter_id
         LEFT JOIN posts p ON r.target_type = 'post' AND p.id = r.target_id
         LEFT JOIN users pu ON pu.id = p.user_id
         LEFT JOIN comments c ON r.target_type = 'comment' AND c.id = r.target_id
         LEFT JOIN users cu ON cu.id = c.user_id
         WHERE $where
         ORDER BY r.id DESC LIMIT ? OFFSET ?"
    );
    $params[] = $limit;
    $params[] = ($page - 1) * $limit;
    $st->execute($params);
    $list = $st->fetchAll();
    foreach ($list as &$r) {
        $r['id'] = (int)$r['id'];
        $r['target_id'] = (int)$r['target_id'];
        $r['reporter_id'] = (int)$r['reporter_id'];
        $r['status'] = (int)$r['status'];
        $content = $r['target_type'] === 'post' ? $r['post_content'] : $r['comment_content'];
        $r['target_content'] = mb_strlen($content) > 100 ? mb_substr($content, 0, 100) . '…' : $content;
        $r['author_id'] = (int)($r['target_type'] === 'post' ? $r['post_author_id'] : $r['comment_author_id']);
        $r['author_nickname'] = (string)($r['target_type'] === 'post' ? $r['post_author_nickname'] : $r['comment_author_nickname']);
        unset($r['post_content'], $r['post_author_id'], $r['post_author_nickname'],
              $r['comment_content'], $r['comment_author_id'], $r['comment_author_nickname']);
    }
    unset($r);
    ok(['list' => $list, 'page' => $page, 'total' => $total, 'has_more' => $page * $limit < $total]);
}

if ($action === 'admin.report_handle') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $id = (int)($_POST['id'] ?? 0);
    $handleAction = (string)($_POST['handle'] ?? '');
    if (!in_array($handleAction, ['delete_post', 'delete_comment', 'ban_author', 'ignore'], true)) {
        fail('处理动作不合法');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM reports WHERE id=?');
    $st->execute([$id]);
    $rep = $st->fetch();
    if (!$rep) {
        fail('举报不存在', 404);
    }
    // 解析目标作者（可能内容已被删除，作者取不到时不影响 ignore/delete）
    $authorId = 0;
    if ($rep['target_type'] === 'post') {
        $st = $pdo->prepare('SELECT user_id FROM posts WHERE id=?');
        $st->execute([$rep['target_id']]);
        $authorId = (int)$st->fetchColumn();
    } else {
        $st = $pdo->prepare('SELECT user_id, post_id FROM comments WHERE id=?');
        $st->execute([$rep['target_id']]);
        $c = $st->fetch();
        if ($c) {
            $authorId = (int)$c['user_id'];
            $cPostId = (int)$c['post_id'];
        }
    }
    switch ($handleAction) {
        case 'delete_post':
            $pdo->prepare('UPDATE posts SET status=1 WHERE id=?')->execute([$rep['target_id']]);
            break;
        case 'delete_comment':
            $pdo->prepare('DELETE FROM comments WHERE id=?')->execute([$rep['target_id']]);
            if (!empty($cPostId)) {
                $pdo->prepare('UPDATE posts SET comment_count = MAX(0, comment_count - 1) WHERE id=?')->execute([$cPostId]);
            }
            break;
        case 'ban_author':
            if ($authorId > 0) {
                $st = $pdo->prepare('SELECT role FROM users WHERE id=?');
                $st->execute([$authorId]);
                if ($st->fetchColumn() !== 'super') {
                    $pdo->prepare('UPDATE users SET banned=1 WHERE id=?')->execute([$authorId]);
                }
            }
            break;
        case 'ignore':
            break;
    }
    $pdo->prepare('UPDATE reports SET status=1 WHERE id=?')->execute([$id]);
    ok(null, '已处理');
}

/* ---------------- 心动电台（音乐播放器） ---------------- */

/** 扫描音乐目录，返回歌曲列表（.order.json 提供播放顺序） */
function musicScan(): array
{
    $dir = defined('MUSIC_DIR') ? MUSIC_DIR : '';
    if ($dir === '' || !is_dir($dir)) {
        return [];
    }
    $exts = ['mp3', 'flac', 'wav', 'ogg', 'm4a', 'aac'];
    $files = [];
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $path = rtrim($dir, '/') . '/' . $f;
        if (is_file($path)) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, $exts, true)) {
                $title = pathinfo($f, PATHINFO_FILENAME);
                $files[$f] = [
                    'id'    => (int)(crc32($f) & 0x7fffffff),
                    'title' => $title,
                    'artist'=> '',
                    'url'   => MUSIC_URL_BASE . rawurlencode($f),
                ];
            }
        }
    }
    if (!$files) {
        return [];
    }
    // 按 .order.json 排序（未收录的歌曲追加到末尾，按文件名排序）
    $ordered = [];
    $orderPath = rtrim($dir, '/') . '/.order.json';
    if (is_file($orderPath)) {
        $order = json_decode((string)file_get_contents($orderPath), true);
        if (is_array($order)) {
            foreach ($order as $name) {
                if (isset($files[$name])) {
                    $ordered[] = $files[$name];
                    unset($files[$name]);
                }
            }
        }
    }
    foreach ($files as $f) {
        $ordered[] = $f;
    }
    return $ordered;
}

/* ---------------- 互动通知 ---------------- */

if ($action === 'notify.list') {
    $u = getOrCreateUser(requireDeviceId());
    $st = db()->prepare(
        "SELECT n.id, n.type, n.post_id, n.content, n.read, n.created_at, u.nickname, u.avatar
         FROM notifications n JOIN users u ON u.id = n.actor_id
         WHERE n.user_id = ? ORDER BY n.id DESC LIMIT 50"
    );
    $st->execute([$u['id']]);
    $list = array_map(function ($n) {
        return [
            'id'         => (int)$n['id'],
            'type'       => (string)$n['type'],
            'post_id'    => (int)$n['post_id'],
            'content'    => (string)$n['content'],
            'nickname'   => (string)$n['nickname'],
            'avatar'     => (string)$n['avatar'],
            'read'       => (int)$n['read'] === 1,
            'created_at' => (string)$n['created_at'],
        ];
    }, $st->fetchAll());
    ok(['list' => $list]);
}

if ($action === 'notify.unread') {
    $u = getOrCreateUser(requireDeviceId());
    $st = db()->prepare('SELECT COUNT(*) c FROM notifications WHERE user_id=? AND read=0');
    $st->execute([$u['id']]);
    ok(['unread' => (int)$st->fetchColumn()]);
}

if ($action === 'notify.read') {
    $u = getOrCreateUser(requireDeviceId());
    db()->prepare('UPDATE notifications SET read=1 WHERE user_id=? AND read=0')->execute([$u['id']]);
    ok(null, '已全部标记已读');
}

if ($action === 'music.list') {
    requireDeviceId();
    $tracks = musicScan();
    ok(['list' => $tracks, 'count' => count($tracks)]);
}

if ($action === 'post.vote') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $id = (int)($_POST['post_id'] ?? 0);
    $opt = (int)($_POST['option'] ?? -1);
    $pdo = db();
    $st = $pdo->prepare('SELECT vote_data FROM posts WHERE id=? AND status=0');
    $st->execute([$id]);
    $vd = $st->fetchColumn();
    if ($vd === false) {
        fail('帖子不存在', 404);
    }
    $vote = json_decode((string)$vd, true);
    if (!is_array($vote) || empty($vote['options']) || !is_array($vote['options']) || count($vote['options']) < 2) {
        fail('该帖没有投票', 400);
    }
    $opts = array_values(array_map('strval', $vote['options']));
    if ($opt < 0 || $opt >= count($opts)) {
        fail('选项无效', 400);
    }
    // 每人一票，可改票（SQLite 3.7 不支持 UPSERT，用 UPDATE + INSERT 兼容写法）
    $pdo->prepare('UPDATE post_votes SET option_idx=?, created_at=? WHERE post_id=? AND user_id=?')
        ->execute([$opt, now(), $id, (int)$u['id']]);
    $pdo->prepare('INSERT INTO post_votes (post_id, user_id, option_idx, created_at) SELECT ?,?,?,? '
        . 'WHERE NOT EXISTS (SELECT 1 FROM post_votes WHERE post_id=? AND user_id=?)')
        ->execute([$id, (int)$u['id'], $opt, now(), $id, (int)$u['id']]);
    ok(voteData($id, (int)$u['id']), '投票成功');
}

/* ---------------- 公告与置顶 ---------------- */
if ($action === 'announce.list') {
    $st = db()->prepare('SELECT id, content, created_at FROM announcements WHERE status = 0 ORDER BY id DESC LIMIT 5');
    $st->execute();
    ok(['list' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'admin.announce_create') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $content = trim((string)($_POST['content'] ?? ''));
    if ($content === '') {
        fail('公告内容不能为空');
    }
    if (mb_strlen($content) > 500) {
        fail('公告最长 500 字');
    }
    $st = db()->prepare('INSERT INTO announcements (content, created_at) VALUES (?, ?)');
    $st->execute([$content, date('Y-m-d H:i:s')]);
    ok(null, '公告已发布');
}

if ($action === 'admin.announce_delete') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $id = (int)($_POST['id'] ?? 0);
    db()->prepare('UPDATE announcements SET status = 1 WHERE id = ?')->execute([$id]);
    ok(null, '公告已删除');
}

if ($action === 'admin.post_pin') {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
    $id = (int)($_POST['post_id'] ?? 0);
    $pinned = (int)($_POST['pinned'] ?? 0) === 1;
    if ($id <= 0) {
        fail('参数无效');
    }
    db()->prepare('UPDATE posts SET pinned = ? WHERE id = ?')->execute([$pinned ? 1 : 0, $id]);
    ok(null, $pinned ? '已置顶' : '已取消置顶');
}


/* ================= 二手集市 ================= */

function formatGoods(array $g, ?array $viewer): array
{
    $isMine = $viewer && (int)$g['user_id'] === (int)$viewer['id'];
    return [
        'id'         => (int)$g['id'],
        'title'      => (string)$g['title'],
        'price'      => round((float)$g['price'], 2),
        'cond'       => (string)$g['cond'],
        'category'   => (string)$g['category'],
        'desc'       => (string)$g['desc'],
        'images'     => json_decode((string)($g['images'] ?? '[]'), true) ?: [],
        'status'     => (int)$g['status'],
        'views'      => (int)$g['views'],
        'created_at' => (string)$g['created_at'],
        'is_mine'    => $isMine,
        'user_id'    => (int)$g['user_id'],
        'nickname'   => (string)($g['nickname'] ?? ''),
        'avatar'     => (string)($g['avatar'] ?? ''),
    ];
}

if ($action === 'goods.create') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁，无法发布', 403);
    }
    checkRate($did, 'last_post', RATE_POST);
    $title = trim((string)($_POST['title'] ?? ''));
    $price = (float)($_POST['price'] ?? 0);
    $cond = trim((string)($_POST['cond'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $desc = trim((string)($_POST['desc'] ?? ''));
    $images = json_decode((string)($_POST['images'] ?? '[]'), true);
    if ($title === '' || mb_strlen($title) > 40) {
        fail('标题 1-40 字');
    }
    if ($price < 0 || $price > 99999) {
        fail('价格范围 0-99999');
    }
    $CONDS = ['全新', '几乎全新', '轻度使用', '明显使用'];
    $CATS = ['教材', '数码', '生活', '服饰', '其他'];
    if (!in_array($cond, $CONDS, true)) {
        fail('成色选项无效');
    }
    if (!in_array($category, $CATS, true)) {
        fail('分类选项无效');
    }
    if (mb_strlen($desc) > 1000) {
        fail('描述最多 1000 字');
    }
    if (!is_array($images)) {
        fail('图片参数无效');
    }
    if (count($images) > 6) {
        fail('最多 6 张图片');
    }
    foreach ($images as $img) {
        if (!is_string($img) || !preg_match('#^uploads/[A-Za-z0-9_\-\.]+\.(jpg|jpeg|png|gif|webp)$#', $img)) {
            fail('图片地址无效');
        }
    }
    $pdo = db();
    $pdo->prepare('INSERT INTO goods (user_id, title, price, cond, category, desc, images, status, views, created_at) VALUES (?,?,?,?,?,?,?,0,0,?)')
        ->execute([(int)$u['id'], $title, $price, $cond, $category, $desc, json_encode($images, JSON_UNESCAPED_UNICODE), now()]);
    // 发布奖励：每日前 3 件各 +5 积分（激励集市冷启动，超出部分无奖励防刷分）
    $today = date('Y-m-d') . '%';
    $st = $pdo->prepare('SELECT COUNT(*) FROM goods WHERE user_id = ? AND created_at LIKE ?');
    $st->execute([(int)$u['id'], $today]);
    $gained = 0;
    if ((int)$st->fetchColumn() <= 3) {
        addScore((int)$u['id'], 5);
        $gained = 5;
    }
    ok(['id' => (int)$pdo->lastInsertId(), 'gained' => $gained], $gained > 0 ? '发布成功 +' . $gained . ' 积分，坐等有缘人' : '发布成功，坐等有缘人');
}

if ($action === 'goods.list') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(30, max(1, (int)($_GET['limit'] ?? 10)));
    $category = trim((string)($_GET['category'] ?? ''));
    $sort = trim((string)($_GET['sort'] ?? 'latest'));
    $pdo = db();
    $where = 'WHERE g.status = 0';
    $args = [];
    if ($category !== '' && $category !== '全部') {
        $where .= ' AND g.category = ?';
        $args[] = $category;
    }
    $order = 'g.id DESC';
    if ($sort === 'price_asc') {
        $order = 'g.price ASC, g.id DESC';
    } elseif ($sort === 'price_desc') {
        $order = 'g.price DESC, g.id DESC';
    }
    $total = (int)$pdo->query('SELECT COUNT(*) FROM goods g ' . $where)->fetchColumn();
    $st = $pdo->prepare('SELECT g.*, u.nickname, u.avatar FROM goods g LEFT JOIN users u ON u.id = g.user_id ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)(($page - 1) * $limit));
    $st->execute($args);
    $list = array_map(function ($g) use ($u) {
        return formatGoods($g, $u);
    }, $st->fetchAll());
    ok(['list' => $list, 'has_more' => $page * $limit < $total, 'total' => $total]);
}

if ($action === 'goods.detail') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        fail('参数无效');
    }
    $pdo = db();
    $pdo->prepare('UPDATE goods SET views = views + 1 WHERE id = ?')->execute([$id]);
    $st = $pdo->prepare('SELECT g.*, u.nickname, u.avatar FROM goods g LEFT JOIN users u ON u.id = g.user_id WHERE g.id = ?');
    $st->execute([$id]);
    $g = $st->fetch();
    if (!$g) {
        fail('商品不存在', 404);
    }
    $cs = $pdo->prepare('SELECT c.*, u.nickname, u.avatar FROM goods_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.goods_id = ? ORDER BY c.id ASC');
    $cs->execute([$id]);
    $comments = array_map(function ($c) {
        return [
            'id'         => (int)$c['id'],
            'user_id'    => (int)$c['user_id'],
            'nickname'   => (string)($c['nickname'] ?? ''),
            'avatar'     => (string)($c['avatar'] ?? ''),
            'content'    => (string)$c['content'],
            'created_at' => (string)$c['created_at'],
        ];
    }, $cs->fetchAll());
    ok(['goods' => formatGoods($g, $u), 'comments' => $comments]);
}

if ($action === 'goods.comment.create') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    if (isUserBanned((int)$u['id'])) {
        fail('你的账号已被封禁，无法留言', 403);
    }
    checkRate($did, 'last_comment', RATE_COMMENT);
    $gid = (int)($_POST['goods_id'] ?? 0);
    $content = trim((string)($_POST['content'] ?? ''));
    if ($gid <= 0) {
        fail('参数无效');
    }
    if ($content === '' || mb_strlen($content) > 300) {
        fail('留言 1-300 字');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM goods WHERE id = ? AND status = 0');
    $st->execute([$gid]);
    if (!$st->fetchColumn()) {
        fail('商品不存在或已下架', 404);
    }
    $pdo->prepare('INSERT INTO goods_comments (goods_id, user_id, content, created_at) VALUES (?,?,?,?)')
        ->execute([$gid, (int)$u['id'], $content, now()]);
    ok(['id' => (int)$pdo->lastInsertId()], '留言已送达，等待卖家回复');
}

if ($action === 'goods.sold') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        fail('参数无效');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT user_id FROM goods WHERE id = ? AND status = 0');
    $st->execute([$id]);
    $owner = $st->fetchColumn();
    if (!$owner) {
        fail('商品不存在或已下架', 404);
    }
    if ((int)$owner !== (int)$u['id'] && !in_array($u['role'] ?? '', ['admin', 'super'], true)) {
        fail('只有卖家本人可以标记已售', 403);
    }
    $pdo->prepare('UPDATE goods SET status = 1 WHERE id = ?')->execute([$id]);
    ok(null, '已标记为售出');
}

if ($action === 'goods.delete') {
    $did = requireDeviceId();
    $u = getOrCreateUser($did);
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        fail('参数无效');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT user_id FROM goods WHERE id = ? AND status = 0');
    $st->execute([$id]);
    $owner = $st->fetchColumn();
    if (!$owner) {
        fail('商品不存在或已下架', 404);
    }
    if ((int)$owner !== (int)$u['id'] && !in_array($u['role'] ?? '', ['admin', 'super'], true)) {
        fail('只有卖家本人可以下架', 403);
    }
    $pdo->prepare('UPDATE goods SET status = 2 WHERE id = ?')->execute([$id]);
    ok(null, '已下架');
}

/* ---------------- 404 ---------------- */


fail('接口不存在: ' . $action, 404);
