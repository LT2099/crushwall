<?php
/**
 * 蓝天星球 配置
 * 部署时请修改 ADMIN_PASSWORD 为强密码。
 */

// 站点配置
define('SITE_NAME', '蓝天星球');

// 管理员密码（部署前务必修改！建议 16 位以上随机字符串）
// ADMIN_PASSWORD_HASH：password_hash() 生成的 bcrypt 哈希，登录时用 password_verify() 校验，
// 不再明文比对。ADMIN_PASSWORD 仅作为「未配置哈希时的回退」（兼容旧部署），配置了哈希后以哈希为准。
define('ADMIN_PASSWORD', 'admin');
define('ADMIN_PASSWORD_HASH', ''); // 留空则回退明文比对（默认密码 admin）

// 后台登录防爆破：连续失败 ADMIN_MAX_FAILS 次锁定 ADMIN_LOCK_MINUTES 分钟
define('ADMIN_MAX_FAILS', 5);
define('ADMIN_LOCK_MINUTES', 15);

// DDoS 自动防护：同一 IP 在 DDOS_WINDOW 秒内请求数 ≥ DDOS_THRESHOLD 自动永久封禁
// 阈值取 600（10 req/s）以兼容校园/宿舍共用出口 IP（NAT）场景，正常用户与页面轮询远低于此
define('DDOS_WINDOW', 60);
define('DDOS_THRESHOLD', 600);

// 密钥：用于管理员 token 签名（部署时改为随机字符串）
define('ADMIN_SECRET', 'CHANGE_ME_TO_A_RANDOM_STRING');

// 数据库文件路径
define('DB_PATH', __DIR__ . '/data/crushwall.db');

// 上传目录（相对于 cw-api/）
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL_BASE', 'uploads/');

// 音乐目录：cw-api/music/（部署后与站点一起上传），音乐文件通过 nginx alias 对外提供
define('MUSIC_DIR', __DIR__ . '/music');
define('MUSIC_URL_BASE', 'music/');

// 上传限制：帖子图片最大 5MB，头像最大 512KB，音乐最大 30MB，视频最大 100MB，语音最大 5MB，白名单类型
define('VOICE_MAX_SIZE', 5 * 1024 * 1024);

// 漂流瓶：捞瓶间隔与每日上限
define('RATE_BOTTLE', 2);
define('BOTTLE_DAILY_MAX', 20);

// 礼物目录：名称 / 图标 / 价格（积分）
$GIFT_CATALOG = [
    ['小心心', '❤️', 5],
    ['玫瑰', '🌹', 10],
    ['咖啡', '☕', 15],
    ['蛋糕', '🎂', 20],
    ['奖杯', '🏆', 50],
    ['火箭', '🚀', 100],
];
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024);
define('AVATAR_MAX_SIZE', 512 * 1024);
define('MUSIC_MAX_SIZE', 30 * 1024 * 1024);
define('VIDEO_MAX_SIZE', 100 * 1024 * 1024);
$ALLOWED_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
$ALLOWED_VIDEO_TYPES = [
    'video/mp4'  => 'mp4',
    'video/webm' => 'webm',
];
// 举报原因枚举（report.create 的 reason 白名单）
$REPORT_REASONS = ['spam', 'porn', 'abuse', 'illegal', 'other'];

// 操作频率限制（秒）
define('RATE_POST', 5);      // 发帖间隔（原 10s 过严影响体验，放宽至 5s）
define('RATE_COMMENT', 2);   // 评论间隔（原 5s 放宽至 2s）
define('RATE_LIKE', 1);      // 点赞间隔（原 2s 放宽至 1s）
define('RATE_REPORT', 5);    // 举报间隔（原 10s 放宽至 5s）
define('RATE_ROOM', 2);      // 话题房发言间隔（聊天场景 2s 即可，比墙发帖更宽松）

// 防刷屏：FLOOD_WINDOW 秒内累计发言 ≥ FLOOD_MAX 次拒绝；与本人最近 DUP_CHECK_N 条内容相同拒绝；FLOOD_CLEAN 为日志清理窗口
define('FLOOD_WINDOW', 60);
define('FLOOD_MAX', 10);
define('DUP_CHECK_N', 5);
define('FLOOD_CLEAN', 600);

// 积分小卖部商品目录：kind=frame 头像框（永久）| color 昵称变色（7 天）；price 积分；css 前端应用
$SHOP_CATALOG = [
    ['id' => 'frame_star',   'kind' => 'frame', 'name' => '星环',      'desc' => '星球蓝渐变光环', 'price' => 150, 'css' => 'frame-star'],
    ['id' => 'frame_sakura', 'kind' => 'frame', 'name' => '樱花',      'desc' => '初恋粉花边',     'price' => 120, 'css' => 'frame-sakura'],
    ['id' => 'frame_gold',   'kind' => 'frame', 'name' => '贵族金',    'desc' => '暗夜鎏金描边',   'price' => 200, 'css' => 'frame-gold'],
    ['id' => 'color_pink',   'kind' => 'color', 'name' => '糖果粉',    'desc' => '昵称变粉 7 天',  'price' => 50,  'css' => '#ec4899'],
    ['id' => 'color_green',  'kind' => 'color', 'name' => '抹茶绿',    'desc' => '昵称变绿 7 天',  'price' => 50,  'css' => '#10b981'],
    ['id' => 'color_purple', 'kind' => 'color', 'name' => '暗夜紫',    'desc' => '昵称变紫 7 天',  'price' => 50,  'css' => '#8b5cf6'],
    ['id' => 'color_orange', 'kind' => 'color', 'name' => '活力橙',    'desc' => '昵称变橙 7 天',  'price' => 50,  'css' => '#f97316'],
];

// 时间
date_default_timezone_set('Asia/Shanghai');

// ================= 每日盲盒 =================
// 盲盒冷却（秒）：24 小时，服务端以 time() 校验，改前端/手机时间无效
define('BOX_COOLDOWN', 86400);
// 积分抽盲盒单价（免费次数用完后可花积分立即再抽，不影响次日免费次数）
define('BOX_PRICE', 60);

// 盲盒物品池（概率权重在服务端，前端只展示结果）：
// kind: frame 头像框 | bubble 气泡皮肤 | title 称号 | score 积分
// rarity: legend 传说 | epic 史诗 | rare 稀有 | common 普通
// weight: 抽取权重，权重总和 1000 → 传说 0.5% / 史诗 2.5% / 稀有 12% / 普通 85%
// css: frame 类为 af-xxx（头像框），bubble 类为 bubble-skin-xxx（话题房气泡）
$BLIND_BOX_POOL = [
    // —— 传说 legend（5）——
    ['key' => 'box_aurora',   'name' => '极光之环', 'kind' => 'frame',  'rarity' => 'legend', 'weight' => 1, 'css' => 'af-box-aurora',   'icon' => '🌌', 'desc' => '北极光环绕的传说头像框'],
    ['key' => 'box_galaxy',   'name' => '星河气泡', 'kind' => 'bubble', 'rarity' => 'legend', 'weight' => 1, 'css' => 'bubble-skin-galaxy', 'icon' => '✨', 'desc' => '整个星河都在你的气泡里'],
    ['key' => 'box_owner',    'name' => '星球之主', 'kind' => 'title',  'rarity' => 'legend', 'weight' => 1, 'css' => 'title-owner',      'icon' => '👑', 'desc' => '蓝天星球的传说称号'],
    // —— 史诗 epic（22）——
    ['key' => 'box_rainbow',  'name' => '彩虹气泡', 'kind' => 'bubble', 'rarity' => 'epic', 'weight' => 4, 'css' => 'bubble-skin-rainbow', 'icon' => '🌈', 'desc' => '彩虹渐变气泡，说话都带光'],
    ['key' => 'box_emo',      'name' => '深夜emo达人', 'kind' => 'title', 'rarity' => 'epic', 'weight' => 5, 'css' => 'title-emo', 'icon' => '🌙', 'desc' => '凌晨两点还在想 TA 的人'],
    ['key' => 'box_lucky',    'name' => '锦鲤附体', 'kind' => 'title', 'rarity' => 'epic', 'weight' => 5, 'css' => 'title-lucky', 'icon' => '🍀', 'desc' => '被好运眷顾的天选之子'],
    ['key' => 'box_meteor',   'name' => '流星头像框', 'kind' => 'frame', 'rarity' => 'epic', 'weight' => 4, 'css' => 'af-box-meteor', 'icon' => '☄️', 'desc' => '划过天际的流星头像框'],
    ['key' => 'box_hot',      'name' => '热搜体质', 'kind' => 'title', 'rarity' => 'epic', 'weight' => 4, 'css' => 'title-hot', 'icon' => '🔥', 'desc' => '发帖必上热门的人'],
    // —— 稀有 rare（110）——
    ['key' => 'box_sofa',     'name' => '抢沙发狂魔', 'kind' => 'title', 'rarity' => 'rare', 'weight' => 20, 'css' => 'title-sofa', 'icon' => '🛋️', 'desc' => '帖子的第一反应永远是我'],
    ['key' => 'box_bottle',   'name' => '漂流瓶捞王', 'kind' => 'title', 'rarity' => 'rare', 'weight' => 20, 'css' => 'title-bottle', 'icon' => '🍾', 'desc' => '大海捞针一捞一个准'],
    ['key' => 'box_gradient', 'name' => '渐变气泡', 'kind' => 'bubble', 'rarity' => 'rare', 'weight' => 25, 'css' => 'bubble-skin-gradient', 'icon' => '🌊', 'desc' => '温柔渐变气泡皮肤'],
    ['key' => 'box_spark',    'name' => '星尘头像框', 'kind' => 'frame', 'rarity' => 'rare', 'weight' => 25, 'css' => 'af-box-spark', 'icon' => '✦', 'desc' => '细碎星尘点缀的头像框'],
    ['key' => 'box_like',     'name' => '点赞小能手', 'kind' => 'title', 'rarity' => 'rare', 'weight' => 20, 'css' => 'title-like', 'icon' => '👍', 'desc' => '全站最会捧场的人'],
    // —— 普通 common（862）——
    ['key' => 'box_score5',   'name' => '积分 +5',  'kind' => 'score', 'rarity' => 'common', 'weight' => 340, 'css' => '5',  'icon' => '⭐', 'desc' => '积分 +5'],
    ['key' => 'box_score10',  'name' => '积分 +10', 'kind' => 'score', 'rarity' => 'common', 'weight' => 340, 'css' => '10', 'icon' => '🌟', 'desc' => '积分 +10'],
    ['key' => 'box_score20',  'name' => '积分 +20', 'kind' => 'score', 'rarity' => 'common', 'weight' => 182, 'css' => '20', 'icon' => '💫', 'desc' => '积分 +20'],
];

// 每日任务中心：把全站玩法串成每日清单，完成领积分，全勤额外奖励
// kind: sign/post/comment/like/box/goods = 服务端按表统计今日次数（防作弊）；mark = 前端 task.mark 上报
// 表映射：sign→signins, post→posts, comment→comments, like→likes, box→blindbox_records, goods→goods
$DAILY_TASKS = [
    ['id' => 'sign',    'name' => '签到打卡',     'icon' => '✅', 'target' => 1, 'score' => 5, 'kind' => 'sign'],
    ['id' => 'post',    'name' => '发一篇帖子',   'icon' => '📝', 'target' => 1, 'score' => 5, 'kind' => 'post'],
    ['id' => 'comment', 'name' => '评一条评论',   'icon' => '💬', 'target' => 1, 'score' => 3, 'kind' => 'comment'],
    ['id' => 'like',    'name' => '点一个赞',     'icon' => '👍', 'target' => 1, 'score' => 2, 'kind' => 'like'],
    ['id' => 'box',     'name' => '抽一次盲盒',   'icon' => '🎁', 'target' => 1, 'score' => 5, 'kind' => 'box'],
    ['id' => 'goods',   'name' => '发一件闲置',   'icon' => '📦', 'target' => 1, 'score' => 5, 'kind' => 'goods'],
    ['id' => 'fortune', 'name' => '看今日桃花签', 'icon' => '🍀', 'target' => 1, 'score' => 2, 'kind' => 'mark'],
    ['id' => 'report',  'name' => '看星球日报',   'icon' => '📰', 'target' => 1, 'score' => 2, 'kind' => 'mark'],
];
define('TASK_ALL_BONUS', 10); // 今日全部任务完成（含领取）额外奖励

// 恋爱人格测试：8 题 × 4 选项，选项按三个维度计票（A 主动/P 被动、D 直球/T 试探、R 浪漫/W 务实）
// 三维组合 → 8 种人格（$LOVE_TYPES 的 key 由三个维度拼接）
$LOVE_QUESTIONS = [
    ['q' => '在星球墙刷到心动的人，你会？', 'opts' => [
        ['t' => '直接私信打招呼', 'dims' => 'AD'], ['t' => '先点个赞，等 TA 注意到我', 'dims' => 'PT'],
        ['t' => '写一首藏头诗悄悄表白', 'dims' => 'PR'], ['t' => '默默记下来，随缘', 'dims' => 'PW'],
    ]],
    ['q' => '和 TA 聊天时，你习惯？', 'opts' => [
        ['t' => '想到什么说什么', 'dims' => 'AD'], ['t' => '字斟句酌，怕说错话', 'dims' => 'PT'],
        ['t' => '突然来一句土味情话', 'dims' => 'AR'], ['t' => '聊聊日常，细水长流', 'dims' => 'PW'],
    ]],
    ['q' => '发现 TA 也对你心动了，你的第一反应？', 'opts' => [
        ['t' => '马上约 TA 见面', 'dims' => 'AD'], ['t' => '反复确认几遍是不是真的', 'dims' => 'PT'],
        ['t' => '悄悄计划一场惊喜', 'dims' => 'AR'], ['t' => '先当朋友处着，顺其自然', 'dims' => 'PW'],
    ]],
    ['q' => '第一次约会，你会怎么安排？', 'opts' => [
        ['t' => '全权做主，带 TA 体验我安排的一切', 'dims' => 'AD'], ['t' => '问 TA 想去哪，跟着 TA 走', 'dims' => 'PT'],
        ['t' => '精心找个小众浪漫的地方', 'dims' => 'AR'], ['t' => '看电影吃饭，轻松舒服就好', 'dims' => 'PW'],
    ]],
    ['q' => 'TA 心情不好的时候，你会？', 'opts' => [
        ['t' => '直接冲到 TA 面前', 'dims' => 'AD'], ['t' => '先发消息试探，不打扰', 'dims' => 'PT'],
        ['t' => '写封信或者录首歌给 TA', 'dims' => 'PR'], ['t' => '买好 TA 爱吃的东西', 'dims' => 'AW'],
    ]],
    ['q' => '吵架了，你通常怎么和好？', 'opts' => [
        ['t' => '当场把话说开', 'dims' => 'AD'], ['t' => '先冷静，再发小作文', 'dims' => 'PT'],
        ['t' => '送个小礼物，仪式感拉满', 'dims' => 'AR'], ['t' => '用行动道歉，做 TA 爱吃的', 'dims' => 'AW'],
    ]],
    ['q' => '朋友起哄你们在一起，你会？', 'opts' => [
        ['t' => '大方承认，就等这句话', 'dims' => 'AD'], ['t' => '脸红否认，心里偷乐', 'dims' => 'PT'],
        ['t' => '顺势表白，就着气氛', 'dims' => 'AR'], ['t' => '打哈哈过去，先不公开', 'dims' => 'PW'],
    ]],
    ['q' => '你觉得恋爱里最重要的是？', 'opts' => [
        ['t' => '双向奔赴的热情', 'dims' => 'AD'], ['t' => '心照不宣的默契', 'dims' => 'PT'],
        ['t' => '平凡日子里的浪漫', 'dims' => 'AR'], ['t' => '踏实安心的陪伴', 'dims' => 'PW'],
    ]],
];
// 8 种人格：key = 三维护位（主动/被动 直球/试探 浪漫/务实），desc 描述，match 最佳拍档
$LOVE_TYPES = [
    'ADR' => ['name' => '心动行动派', 'icon' => '🚀', 'desc' => '第一眼心动就冲，浪漫和热情都拉满，你是爱情里最勇敢的冒险家。', 'match' => '暗恋观察家'],
    'ADW' => ['name' => '直球告白选手', 'icon' => '💬', 'desc' => '喜欢就说出来，喜欢就行动，你的坦诚是 TA 最大的安全感。', 'match' => '随缘佛系派'],
    'ATR' => ['name' => '温柔狙击手', 'icon' => '🎯', 'desc' => '主动但不莽撞，每一步都精心设计，你擅长在温柔里埋惊喜。', 'match' => '氛围感诗人'],
    'ATW' => ['name' => '靠谱行动家', 'icon' => '🛡️', 'desc' => '嘴上不怎么说，但事情都办得妥妥的，你的爱都藏在行动里。', 'match' => '暗恋观察家'],
    'PDR' => ['name' => '氛围感诗人', 'icon' => '🌙', 'desc' => '不轻易主动，但一开口就是浪漫暴击，你在等一个懂你的人。', 'match' => '温柔狙击手'],
    'PDW' => ['name' => '慢热直球手', 'icon' => '⚡', 'desc' => '平时慢热，认定了的人却敢直接开口，你的反差最迷人。', 'match' => '靠谱行动家'],
    'PTR' => ['name' => '暗恋观察家', 'icon' => '👀', 'desc' => '默默关注、反复确认，你的爱藏在每一个偷偷的注视里。', 'match' => '心动行动派'],
    'PTW' => ['name' => '随缘佛系派', 'icon' => '🍃', 'desc' => '顺其自然，是你的终会来，你的从容本身就是魅力。', 'match' => '直球告白选手'],
];
