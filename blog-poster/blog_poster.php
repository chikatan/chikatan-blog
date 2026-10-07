<?php
/**
 * blog_poster.php — GitHub の「記事の箱」から、公開日が来た記事を microCMS に投稿する。
 *
 * さくらのサーバーの cron から、毎日1回、コマンドラインで実行する。
 *   php blog_poster.php            通常実行
 *   php blog_poster.php --dry-run  何も投稿せず、やることだけ表示
 *   php blog_poster.php --draft    すべて「下書き」として投稿（テスト用）
 *
 * APIキーは、同じサイトの api/blog-list.php に入っているものを使う
 * （環境変数 MICROCMS_API_KEY があれば、そちらを優先）。このファイルにキーは書かない。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

date_default_timezone_set('Asia/Tokyo');

const RAW_BASE = 'https://raw.githubusercontent.com/chikatan/chikatan-blog/main/';
const DEFAULT_DOMAIN = 'chikatan-nagoya-tantei';
const STATE_FILE = __DIR__ . '/blog_poster_state.json';
const LOG_FILE = __DIR__ . '/blog_poster.log';

$dryRun = in_array('--dry-run', $argv, true);
$forceDraft = in_array('--draft', $argv, true);

function logmsg(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    echo $line;
    @file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

function domain(): string
{
    return getenv('MICROCMS_DOMAIN') ?: DEFAULT_DOMAIN;
}

function api_key(): string
{
    $env = getenv('MICROCMS_API_KEY');
    if ($env) {
        return $env;
    }
    $src = @file_get_contents(__DIR__ . '/../api/blog-list.php');
    if ($src !== false
        && preg_match("/getenv\\('MICROCMS_API_KEY'\\)\\s*\\?:\\s*'([^']+)'/", $src, $m)) {
        return $m[1];
    }
    return '';
}

/** @return array{0:int,1:string} [httpCode, body] */
function http(string $method, string $url, array $headers = [], $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res === false ? '' : (string)$res];
}

function fetch_raw(string $path): ?string
{
    // GitHub の配信はキャッシュされるので、毎回違う値を付けて最新を取る
    [$code, $body] = http('GET', RAW_BASE . $path . '?t=' . time());
    return $code === 200 ? $body : null;
}

function load_state(): array
{
    if (!is_file(STATE_FILE)) {
        return ['posted' => []];
    }
    $d = json_decode((string)file_get_contents(STATE_FILE), true);
    return is_array($d) && isset($d['posted']) ? $d : ['posted' => []];
}

function save_state(array $state): void
{
    file_put_contents(STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function upload_media(string $key, string $bin, string $filename): ?string
{
    $tmp = tempnam(sys_get_temp_dir(), 'bp');
    file_put_contents($tmp, $bin);
    $file = new CURLFile($tmp, mime_content_type($tmp) ?: 'application/octet-stream', $filename);
    [$code, $res] = http(
        'POST',
        'https://' . domain() . '.microcms-management.io/api/v1/media',
        ['X-MICROCMS-API-KEY: ' . $key],
        ['file' => $file]
    );
    @unlink($tmp);
    if ($code < 200 || $code >= 300) {
        logmsg("画像アップロード失敗 HTTP {$code}: " . mb_substr($res, 0, 300));
        return null;
    }
    $j = json_decode($res, true);
    return is_array($j) && !empty($j['url']) ? (string)$j['url'] : null;
}

// ---------------- 本処理 ----------------

$key = api_key();
if ($key === '' && !$dryRun) {
    logmsg('APIキーが見つかりません。中止します。');
    exit(1);
}

$indexRaw = fetch_raw('posts/index.json');
if ($indexRaw === null) {
    logmsg('posts/index.json を取得できませんでした。中止します。');
    exit(1);
}
$index = json_decode($indexRaw, true);
if (!is_array($index)) {
    logmsg('posts/index.json の形式が正しくありません。中止します。');
    exit(1);
}

$state = load_state();
$today = date('Y-m-d');
$posted = 0;

foreach ($index as $entry) {
    $id = (string)($entry['id'] ?? '');
    $date = (string)($entry['publish_date'] ?? '');
    if ($id === '' || $date === '') {
        continue;
    }
    if (isset($state['posted'][$id])) {
        continue;            // 投稿済み
    }
    if ($date > $today) {
        continue;            // まだ公開日が来ていない
    }

    $metaRaw = fetch_raw("posts/{$id}/post.json");
    $bodyHtml = fetch_raw("posts/{$id}/body.html");
    $meta = $metaRaw !== null ? json_decode($metaRaw, true) : null;
    if (!is_array($meta) || $bodyHtml === null || empty($meta['title'])) {
        logmsg("{$id}: 記事ファイルを取得できませんでした。スキップします。");
        continue;
    }

    $mode = $forceDraft ? 'draft' : (($meta['mode'] ?? 'draft') === 'publish' ? 'publish' : 'draft');

    if ($dryRun) {
        logmsg("{$id}: [dry-run] タイトル『{$meta['title']}』を {$mode} で投稿する予定");
        continue;
    }

    $payload = ['title' => (string)$meta['title'], 'body' => $bodyHtml];

    if (!empty($meta['category'])) {
        $payload['category'] = is_array($meta['category']) ? $meta['category'] : [(string)$meta['category']];
    }

    if (!empty($meta['eyecatch'])) {
        $img = fetch_raw("posts/{$id}/" . $meta['eyecatch']);
        if ($img === null) {
            logmsg("{$id}: アイキャッチ画像を取得できませんでした。画像なしで投稿します。");
        } else {
            $url = upload_media($key, $img, basename((string)$meta['eyecatch']));
            if ($url !== null) {
                $payload['eyecatch'] = $url;
            } else {
                logmsg("{$id}: アイキャッチなしで投稿します。");
            }
        }
    }

    $endpoint = 'https://' . domain() . '.microcms.io/api/v1/blog' . ($mode === 'draft' ? '?status=draft' : '');
    [$code, $res] = http(
        'POST',
        $endpoint,
        ['X-MICROCMS-API-KEY: ' . $key, 'Content-Type: application/json'],
        json_encode($payload, JSON_UNESCAPED_UNICODE)
    );

    if ($code < 200 || $code >= 300) {
        logmsg("{$id}: 投稿失敗 HTTP {$code}: " . mb_substr($res, 0, 300));
        continue;
    }

    $j = json_decode($res, true);
    $state['posted'][$id] = [
        'at' => date('c'),
        'mode' => $mode,
        'contentId' => is_array($j) ? ($j['id'] ?? null) : null,
    ];
    save_state($state);
    $posted++;
    logmsg("{$id}: 『{$meta['title']}』を {$mode} で投稿しました。");
}

logmsg("完了。今回の投稿数: {$posted}");
