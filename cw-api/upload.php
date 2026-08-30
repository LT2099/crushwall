<?php
/**
 * 上传接口
 * 安全措施：
 *  - MIME 白名单（图片 jpg/png/gif/webp，音乐 mp3）
 *  - getimagesize 二次校验真实图片
 *  - 随机文件名，拒绝覆盖
 *  - 大小上限：帖子 5MB / 头像 512KB / 音乐 30MB
 *  - type=avatar 时存入 uploads/avatars/ 专属目录
 *  - type=music 时校验管理员 token，存入 MUSIC_DIR
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('仅支持 POST');
}

$did = requireDeviceId();
getOrCreateUser($did); // 确保是有效设备

$type = (string)($_POST['type'] ?? 'post');
$isAvatar = ($type === 'avatar');
$isMusic = ($type === 'music');
$isVideo = ($type === 'video');
$isChatImg = ($type === 'chatimage');
$isVoice = ($type === 'voice');
$isChatVideo = ($type === 'chatvideo');
if (!$isAvatar && !$isMusic && !$isVideo && !$isChatImg && !$isVoice && !$isChatVideo && $type !== 'post') {
    fail('未知的上传类型');
}

// 音乐上传仅管理员可用
if ($isMusic) {
    $token = (string)($_POST['token'] ?? '');
    if (!verifyAdminToken($token)) {
        fail('无权限', 403);
    }
}

if (!isset($_FILES['file'])) {
    fail('未收到文件');
}
$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    fail('上传失败，错误码: ' . $file['error']);
}
if ($file['size'] <= 0) {
    fail('文件为空');
}

/* ---------------- 音乐上传（音频 → MUSIC_DIR） ---------------- */
if ($isMusic) {
    if ($file['size'] > MUSIC_MAX_SIZE) {
        fail('音乐不能超过 30MB');
    }
    // 音频 MIME 白名单（与 musicScan 支持的扩展名对应）
    $AUDIO_TYPES = [
        'audio/mpeg'       => 'mp3',
        'audio/mp3'        => 'mp3',
        'audio/wav'        => 'wav',
        'audio/x-wav'      => 'wav',
        'audio/wave'       => 'wav',
        'audio/flac'       => 'flac',
        'audio/x-flac'     => 'flac',
        'audio/aac'        => 'aac',
        'audio/mp4'        => 'm4a',
        'audio/x-m4a'      => 'm4a',
        'audio/ogg'        => 'ogg',
        'audio/opus'       => 'ogg',
        'application/ogg'  => 'ogg',
    ];
    $mime = strtolower((string)($file['type'] ?? ''));
    $audioExt = $AUDIO_TYPES[$mime] ?? null;
    if (!$audioExt) {
        fail('仅支持 MP3/WAV/FLAC/AAC/M4A/OGG 音频格式');
    }
    $dir = rtrim(MUSIC_DIR, '/') . '/';
    if (!is_dir($dir) || !is_writable($dir)) {
        fail('音乐目录不可写，请联系管理员');
    }
    // 文件名去路径，扩展名归一化，重名追加随机串避免覆盖
    $name = basename((string)($file['name'] ?? 'music.' . $audioExt));
    $name = trim(preg_replace('~[\x00-\x1f\x7f<>:"/\\\\|?*]~', '', $name));
    if ($name === '' || $name === '.') {
        $name = 'music.' . $audioExt;
    }
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $audioExt) {
        $name = pathinfo($name, PATHINFO_FILENAME) . '.' . $audioExt;
    }
    if (file_exists($dir . $name)) {
        $n = pathinfo($name, PATHINFO_FILENAME);
        $name = $n . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $audioExt;
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        fail('保存文件失败');
    }
    @chmod($dir . $name, 0644);
    ok(['url' => MUSIC_URL_BASE . rawurlencode($name)], '上传成功');
}

/* ---------------- 视频上传（视频 → UPLOAD_DIR，帖子附件 / 私信） ---------------- */
if ($isVideo || $isChatVideo) {
    if ($file['size'] > VIDEO_MAX_SIZE) {
        fail('视频不能超过 100MB');
    }
    $mime = strtolower((string)($file['type'] ?? ''));
    $vExt = $ALLOWED_VIDEO_TYPES[$mime] ?? null;
    if (!$vExt) {
        fail('仅支持 MP4/WebM 视频格式');
    }
    // 文件头二次校验，确认是真实视频容器
    $head = (string)@file_get_contents($file['tmp_name'], false, null, 0, 16);
    if ($vExt === 'mp4' && substr($head, 4, 4) !== 'ftyp') {
        fail('文件不是有效 MP4');
    }
    if ($vExt === 'webm' && substr($head, 0, 4) !== "\x1A\x45\xDF\xA3") {
        fail('文件不是有效 WebM');
    }
    $dir = $isChatVideo ? (UPLOAD_DIR . 'chat/') : UPLOAD_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    for ($i = 0; $i < 10; $i++) {
        $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $vExt;
        if (!file_exists($dir . $name)) {
            break;
        }
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        fail('保存文件失败');
    }
    chmod($dir . $name, 0644);
    ok(['url' => UPLOAD_URL_BASE . ($isChatVideo ? 'chat/' : '') . $name], '上传成功');
}

/* ---------------- 语音上传（录音 → uploads/voice/，私信） ---------------- */
if ($isVoice) {
    if ($file['size'] > VOICE_MAX_SIZE) {
        fail('语音不能超过 5MB');
    }
    $VOICE_TYPES = [
        'audio/webm'         => 'webm',
        'audio/ogg'          => 'ogg',
        'application/ogg'    => 'ogg',
        'audio/mpeg'         => 'mp3',
        'audio/mp3'          => 'mp3',
        'audio/mp4'          => 'm4a',
        'audio/x-m4a'        => 'm4a',
    ];
    $mime = strtolower((string)($file['type'] ?? ''));
    $vExt = $VOICE_TYPES[$mime] ?? null;
    if (!$vExt) {
        fail('仅支持 WebM/OGG/MP3/M4A 语音格式');
    }
    // webm/ogg 文件头校验（EBML 魔数）
    $head = (string)@file_get_contents($file['tmp_name'], false, null, 0, 4);
    if (($vExt === 'webm' || $vExt === 'ogg') && substr($head, 0, 4) !== "\x1A\x45\xDF\xA3") {
        fail('文件不是有效音频');
    }
    $dir = UPLOAD_DIR . 'voice/';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    for ($i = 0; $i < 10; $i++) {
        $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $vExt;
        if (!file_exists($dir . $name)) {
            break;
        }
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        fail('保存文件失败');
    }
    chmod($dir . $name, 0644);
    ok(['url' => UPLOAD_URL_BASE . 'voice/' . $name], '上传成功');
}

/* ---------------- 图片上传（post 帖图 / avatar 头像 / chatimage 私信图） ---------------- */
$subDir = $isAvatar ? 'avatars/' : ($isChatImg ? 'chat/' : '');
$maxSize = $isAvatar ? AVATAR_MAX_SIZE : UPLOAD_MAX_SIZE;

if ($file['size'] > $maxSize) {
    fail($isAvatar ? '头像不能超过 512KB' : '图片不能超过 5MB');
}

$mime = (string)($file['type'] ?? '');
$ext = $ALLOWED_IMAGE_TYPES[$mime] ?? null;
if (!$ext) {
    fail('仅支持 JPG/PNG/GIF/WebP 格式');
}

// 二次校验：确认是真实图片
$info = @getimagesize($file['tmp_name']);
if ($info === false) {
    fail('文件不是有效图片');
}

// 生成随机文件名
$dir = UPLOAD_DIR . $subDir;
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
for ($i = 0; $i < 10; $i++) {
    $name = ($isAvatar ? 'av_' : date('Ymd') . '_') . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!file_exists($dir . $name)) {
        break;
    }
}
if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
    fail('保存文件失败');
}
chmod($dir . $name, 0644);

ok(['url' => UPLOAD_URL_BASE . $subDir . $name], '上传成功');
