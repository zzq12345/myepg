<?php
/**
 * 荔枝网（www.jstv.com）电视节目单抓取，输出 XMLTV 格式 XML。
 *
 * 数据来源（与 live.jstv.com 页面相同的公开接口）：
 *   1. 鉴权：POST https://api-auth-lizhi.jstv.com/JwtAuth/GetWebToken（URL 带 AppID/TT/Sign 签名）
 *   2. 频道列表：GET https://publish-lizhi.jstv.com/nav/8385
 *   3. 节目单：GET https://live-lizhi.jstv.com/api/Channel/Epg?channelId=xxx&days=6&isNeedTomorrow=1
 *
 * 输出示例（XMLTV）：
 *   <programme start="20260809000000 +0800" stop="20260809004911 +0800" channel="江苏卫视">
 *     <title lang="zh">错位</title>
 *   </programme>
 *
 * 用法（CLI）：
 *   php epgjiangsu.php                                # 默认输出到 epgjiangsu.xml（修改后）
 *   php epgjiangsu.php --out=epg.xml                 # 输出到指定文件
 *   php epgjiangsu.php --channel=670,676             # 只抓江苏卫视、江苏卫视4K（也支持频道名）
 *   php epgjiangsu.php --days=6 --tomorrow=1         # 天数及是否含明天（默认 6 天+明天，共 7 天）
 *   php epgjiangsu.php --token-cache=token.json      # 自定义 token 缓存文件
 *
 * Web 用法（通过 URL 参数）：
 *   http://yourhost/epgjiangsu.php?out=epg.xml&days=6&channel=670
 *   若不指定 out，则自动生成 epgjiangsu.xml 并同时输出 XML 到浏览器（修改后）
 *
 * 兼容 PHP 5.6+，需要 curl 扩展。
 */

error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');
ini_set("max_execution_time", "900000");
// ---------- 定义 STDERR（兼容 CLI 和 Web） ----------
if (!defined('STDERR')) {
    if (php_sapi_name() === 'cli') {
        define('STDERR', fopen('php://stderr', 'w'));
    } else {
        // Web 下不输出到响应，防止破坏 XML
        define('STDERR', fopen('php://temp', 'w'));
    }
}

/* ---------------- 基本配置（live.jstv.com 页面 JS 中的公开常量） ---------------- */
define('AUTH_API',    'https://api-auth-lizhi.jstv.com');
define('LIVE_API',    'https://live-lizhi.jstv.com');
define('PUBLISH_API', 'https://publish-lizhi.jstv.com');
define('NAV_ID',      '8385');  // 直播频道列表的导航 ID

define('APP_ID_RAW',     'kmvtsM2I5M2M0NTJiODUxNDMxYzhiM2EwNzY3ODlhYjFlMTQ=ksdf6');
define('SECRET_ID_RAW',  'lujddM2I5M2M0NTJiODUxNDMxYzhiM2EwNzY3ODlhYjFlMTQ=jdf7a');
define('APP_SECRET_RAW', 'uekcdOWRkNGIwNDAwZjZlNGQ1NThmMmIzNDk3ZDczNGMyYjQ=qfg6q');

define('PLATFORM',  41);        // 41 = PC 网页端
define('TZ_OFFSET', '+0800');   // 北京时间

/* ---------------- 工具函数 ---------------- */

/** 解码页面 JS 里“前缀 + base64 + 后缀”包裹的常量 */
function getAppInfo($raw)
{
    $prefix = substr($raw, 0, 5);
    if (in_array($prefix, array('lujdd', 'uekcd', 'kmvts', 'hqudc'), true)) {
        return base64_decode(substr($raw, 5, strlen($raw) - 10));
    }
    return $raw;
}

/** 时间戳混淆（与站点 JS 的 TT 编码一致：按字节高低 4 位变换后重排） */
function encodeTT($ts)
{
    $b = array($ts & 0xFF, ($ts >> 8) & 0xFF, ($ts >> 16) & 0xFF, ($ts >> 24) & 0xFF);
    foreach ($b as $i => $v) {
        $b[$i] = (($v & 0xF0) ^ 0xF0) | ((($v & 0x0F) + 1) & 0x0F);
    }
    $r = $b[3] | ($b[2] << 8) | ($b[1] << 16) | ($b[0] << 24);
    if ($r >= 0x80000000) { // 对齐 JS 32 位有符号结果
        $r -= 4294967296;
    }
    return $r;
}

/** 参数规范化：键排序后依次拼接 键+值（与站点 JS 签名规则一致） */
function canonicalParams(array $params)
{
    ksort($params);
    $s = '';
    foreach ($params as $k => $v) {
        $s .= $k . $v;
    }
    return $s;
}

/** 生成带 AppID/TT/Sign 的签名 URL */
function buildSignedUrl($url, array $params)
{
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url = $url . $sep . 'AppID=' . rawurlencode(getAppInfo(SECRET_ID_RAW));

    $path  = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
    $ts    = time();
    $sign  = md5(getAppInfo(APP_SECRET_RAW) . $path . canonicalParams($params) . $ts);

    return $url . '&TT=' . encodeTT($ts) . '&Sign=' . $sign;
}

/** 生成 32 位随机 uuid */
function randomStr($len)
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) {
        $s .= $chars[mt_rand(0, strlen($chars) - 1)];
    }
    return $s;
}

/** HTTP 请求，返回响应正文（含重试；SSL 证书问题自动降级并提示） */
function httpRequest($url, array $opts = array())
{
    $method  = isset($opts['method']) ? $opts['method'] : 'GET';
    $headers = isset($opts['headers']) ? $opts['headers'] : array();
    $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
    $headers[] = 'Accept: application/json';
    $retries = isset($opts['retries']) ? (int)$opts['retries'] : 4;

    $verify = !isset($opts['no-verify']) || !$opts['no-verify'];
    $warnedNoVerify = false;

    $lastErr = '';
    for ($i = 0; $i <= $retries; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_REFERER        => 'https://live.jstv.com/',
        ));
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, isset($opts['body']) ? $opts['body'] : '');
        }

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $lastErr = $err;
            // 本机未配置 CA 证书链时降级：跳过证书校验重试（会打印警告）
            if ($verify && strpos($err, 'SSL certificate problem') !== false) {
                $verify = false;
                if (!$warnedNoVerify) {
                    $warnedNoVerify = true;
                    if (php_sapi_name() === 'cli') {
                        fwrite(STDERR, "警告: 本机缺少 CA 证书链，本次降级为不校验 SSL 证书。"
                            . "建议为 PHP 配置 curl.cainfo（cacert.pem）以恢复校验。\n");
                    }
                }
                continue;
            }
            sleep(1); // 网络抖动时稍等后重试
            continue;
        }
        if ($code != 200) {
            $lastErr = "HTTP $code";
            sleep(1);
            continue;
        }
        return $resp;
    }
    throw new RuntimeException("请求失败: $lastErr ($url)");
}

/** 从 JWT 中解析过期时间 */
function jwtExp($jwt)
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return time() + 3600;
    }
    $payload = base64_decode(str_replace(array('-', '_'), array('+', '/'), $parts[1]));
    $j = json_decode($payload, true);
    return ($j && isset($j['exp'])) ? (int)$j['exp'] : time() + 3600;
}

/** 获取访问 token（带文件缓存，过期前 120 秒自动换新） */
function getToken($cacheFile)
{
    if ($cacheFile && is_file($cacheFile)) {
        $c = json_decode(file_get_contents($cacheFile), true);
        if ($c && isset($c['token'], $c['exp']) && $c['exp'] - 120 > time()) {
            return $c['token'];
        }
    }

    $appId = getAppInfo(APP_ID_RAW);
    $body  = array('platform' => PLATFORM, 'uuid' => randomStr(32), 'appId' => $appId);

    $resp = httpRequest(buildSignedUrl(AUTH_API . '/JwtAuth/GetWebToken', $body), array(
        'method'  => 'POST',
        'body'    => json_encode($body),
        'headers' => array('Content-Type: application/json'),
    ));

    $j = json_decode($resp, true);
    if (!$j || !isset($j['data']['accessToken'])) {
        throw new RuntimeException('获取 token 失败: ' . $resp);
    }

    $token = $j['data']['accessToken'];
    if ($cacheFile) {
        file_put_contents($cacheFile, json_encode(array('token' => $token, 'exp' => jwtExp($token))));
    }
    return $token;
}

/** 直播频道列表兜底数据（live.jstv.com 页面内置列表，2026-08-10 核对） */
function fallbackChannels()
{
    return array(
        array('id' => 670, 'name' => '江苏卫视'),
        array('id' => 676, 'name' => '江苏卫视4K'),
        array('id' => 669, 'name' => '江苏城市'),
        array('id' => 663, 'name' => '江苏综艺'),
        array('id' => 664, 'name' => '江苏影视'),
        array('id' => 668, 'name' => '江苏公共新闻频道'),
        array('id' => 666, 'name' => '江苏教育'),
        array('id' => 665, 'name' => '江苏体育休闲频道'),
        array('id' => 667, 'name' => '优漫卡通'),
        array('id' => 671, 'name' => '江苏国际'),
    );
}

/** 获取直播频道列表（失败时使用兜底列表） */
function fetchChannels($token)
{
    try {
        $resp = httpRequest(PUBLISH_API . '/nav/' . NAV_ID, array(
            'headers' => array('Authorization: Bearer ' . $token),
        ));
        $j = json_decode($resp, true);

        $list = array();
        if ($j && isset($j['data']['articles'])) {
            foreach ($j['data']['articles'] as $a) {
                $list[] = array('id' => (int)$a['extraId'], 'name' => $a['title']);
            }
        }
        if ($list) {
            return $list;
        }
    } catch (Exception $e) {
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, '频道列表接口不可用，使用内置列表: ' . $e->getMessage() . "\n");
        }
    }
    return fallbackChannels();
}

/** 获取单个频道的节目单 */
function fetchEpg($token, $channelId, $days, $tomorrow)
{
    $url  = LIVE_API . '/api/Channel/Epg?channelId=' . $channelId
          . '&days=' . $days . '&isNeedTomorrow=' . $tomorrow;
    $resp = httpRequest($url, array(
        'headers' => array('Authorization: Bearer ' . $token),
    ));
    $j = json_decode($resp, true);
    if (!$j || !isset($j['data']['epg'])) {
        throw new RuntimeException("频道 $channelId 节目单获取失败: " . $resp);
    }
    return $j['data'];
}

/** "2026-08-09 00:01:56" → "20260809000156 +0800"（北京时间） */
function xmltvTime($datetimeStr)
{
    return preg_replace('/[^0-9]/', '', $datetimeStr) . ' ' . TZ_OFFSET;
}

/** XML 转义 */
function xe($s)
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** 抓取并拼装 XMLTV 文档 */
function buildXmltv($token, array $channels, $days, $tomorrow)
{
    $progCount = 0;
    $chXml = '';
    $pgXml = '';

    foreach ($channels as $ch) {
        try {
            $data = fetchEpg($token, $ch['id'], $days, $tomorrow);
        } catch (Exception $e) {
            if (php_sapi_name() === 'cli') {
                fwrite(STDERR, '跳过频道 ' . $ch['name'] . ': ' . $e->getMessage() . "\n");
            }
            continue;
        }

        // 【修改】使用频道中文名作为 ID（去除空格，确保 XML 合法）
        $cid = str_replace(' ', '_', $ch['name']); // 例如 "江苏卫视" → "江苏卫视"
        // 如果名称中包含其它特殊字符，可进一步过滤，但中文汉字是合法的

        $chXml .= '  <channel id="' . xe($cid) . '">' . "\n";
        $chXml .= '    <display-name lang="zh">' . xe($ch['name']) . '</display-name>' . "\n";
        $chXml .= '  </channel>' . "\n";

        foreach ($data['epg'] as $day) {
            foreach ($day['data'] as $p) {
                $pgXml .= '  <programme start="' . xmltvTime($p['startTime'])
                        . '" stop="' . xmltvTime($p['endTime'])
                        . '" channel="' . xe($cid) . '">' . "\n";
                $pgXml .= '    <title lang="zh">' . xe($p['programName']) . '</title>' . "\n";
                $pgXml .= '  </programme>' . "\n";
                $progCount++;
            }
        }
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, '已抓取 ' . $ch['name'] . '（' . count($data['epg']) . " 天）\n");
        }
    }

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<!DOCTYPE tv SYSTEM "xmltv.dtd">' . "\n";
    $xml .= '<tv generator-info-name="jstv.com" generator-info-url="https://www.jstv.com/">' . "\n";
    $xml .= $chXml;
    $xml .= $pgXml;
    $xml .= '</tv>' . "\n";

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "共 $progCount 条节目\n");
    }
    return $xml;
}

/** 解析参数：兼容 CLI（--key=value）和 Web（$_GET） */
function parseArgs()
{
    $opts = array();
    if (php_sapi_name() === 'cli') {
        global $argv;
        for ($i = 1; $i < count($argv); $i++) {
            if (preg_match('/^--([^=]+)=(.*)$/', $argv[$i], $m)) {
                $opts[$m[1]] = $m[2];
            }
        }
    } else {
        foreach ($_GET as $k => $v) {
            $opts[$k] = $v;
        }
    }
    return $opts;
}

/* ---------------- 主流程 ---------------- */

$opts = parseArgs();

$days          = isset($opts['days']) ? max(1, (int)$opts['days']) : 6;
$tomorrow      = isset($opts['tomorrow']) ? (int)$opts['tomorrow'] : 1;
$channelFilter = isset($opts['channel'])
    ? array_filter(array_map('trim', explode(',', $opts['channel'])))
    : array();

// ===================== [修改] 1：强制默认输出文件 =====================
$outFile = isset($opts['out']) ? $opts['out'] : null;
if ($outFile === null) {
    $outFile = 'epgjiangsu.xml';
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "未指定输出文件，默认写入 epgjiangsu.xml\n");
    }
}
// ====================================================================

$cacheFile = isset($opts['token-cache']) ? $opts['token-cache'] : sys_get_temp_dir() . '/jstv_token.json';

try {
    $token    = getToken($cacheFile);
    $channels = fetchChannels($token);
    if (!$channels) {
        throw new RuntimeException('未获取到频道列表');
    }

    if ($channelFilter) {
        $channels = array_values(array_filter($channels, function ($c) use ($channelFilter) {
            return in_array((string)$c['id'], $channelFilter, true)
                || in_array($c['name'], $channelFilter, true);
        }));
        if (!$channels) {
            throw new RuntimeException('指定的频道均未找到');
        }
    }

    $xml = buildXmltv($token, $channels, $days, $tomorrow);

    // ===================== [修改] 2：写入文件，同时在 Web 下输出 XML =====================
    if ($outFile) {
        file_put_contents($outFile, $xml);
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, "已写入 $outFile\n");
        }
        // Web 环境下同时输出 XML 到浏览器（保持原有显示功能）
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: text/xml; charset=utf-8');
            echo $xml;
        }
    } else {
        // 兜底：如果没有 out（理论上不会发生，因为上面已设置默认值）
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: text/xml; charset=utf-8');
        }
        echo $xml;
    }
    // ==================================================================================

} catch (Exception $e) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, '错误: ' . $e->getMessage() . "\n");
    } else {
        // Web 下输出错误信息为纯文本，避免破坏 XML 预期
        header('Content-Type: text/plain; charset=utf-8');
        echo '错误: ' . $e->getMessage();
    }
    exit(1);
}