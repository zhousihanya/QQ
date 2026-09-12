<?php
/**
 * ============================================================
 *  QQ 开放平台管理后台 PHP 代理 - 公共库 (common.php)
 * ============================================================
 * 来源: 《QQ开放平台接口清单.md》 + src/services/openPlatformService.js (Node 版) 的 PHP 移植
 *
 * 鉴权约定:
 *   开发者扫码登录 q.qq.com 后取得会话三元组，作为参数随路传入:
 *     uin         -> Cookie quin    (QQ号)
 *     developerId -> Cookie quid    (开发者ID, 兼容参数名 uid)
 *     ticket      -> Cookie qticket (开发者票据)
 *   适用范围:
 *     bot.q.qq.com/cgi-bin/*   : 三元组即可 (旧版/新版同基址同鉴权)
 *     q.qq.com/qrcode/*        : 三元组即可
 *     q.qq.com/bopen/v2/*      : 三元组即可 (实测有效)
 *     q.qq.com/pb/* 、/api/v1/*: 需完整浏览器登录态 (裸三元组返回 code=-101185006 登录态校验失败)
 *
 * 响应约定:
 *   查询类接口原样透传上游 JSON (cgi-bin: {retcode, msg, data})
 *   包装类接口统一 {code, msg, ...}，code=0 表示成功
 *
 * 关键错误码:
 *   0 成功; 51 参数校验失败; 10004 no app login permission (无该机器人权限/appid错误);
 *   -101185002 Unauthorized; -101185006 登录态校验失败;
 *   24011 扫码票据与操作场景不匹配
 *        (必须用对应 type 的二维码 + 登录态同一个QQ扫码 + 扫码后立即提交，中间不可插入其他请求)
 *
 * 目录结构:
 *   old/  旧版接口 (PHP 抓包实测有效): 登录/授权二维码、机器人信息、模板、IP白名单、事件、域名报备、成员
 *   new/  新版机器人管理端接口 (q.qq.com/qqbot/ SPA): cgi action 通用代理、敏感操作二维码、重置密钥、bopen/v2
 */

const QQ_BOT_BASE = 'https://bot.q.qq.com';
const QQ_Q_BASE   = 'https://q.qq.com';
const QQ_UA       = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const QQ_TIMEOUT  = 15;

/** 会话值合法性校验 (防 Cookie 头注入) */
function qq_valid_session_value($v) {
    return is_string($v) && preg_match('/^[A-Za-z0-9_=.:-]{4,256}$/', $v) === 1;
}

/** 扫码票据合法性校验 */
function qq_valid_qrcode($v) {
    return is_string($v) && preg_match('/^[A-Za-z0-9_-]{4,128}$/', $v) === 1;
}

/** 统一 JSON 输出并结束 */
function qq_out($data, $httpStatus = 200) {
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 失败输出 {code, msg, ...extra} (code>=400 时同步 HTTP 状态码) */
function qq_fail($code, $msg, $extra = array()) {
    $httpStatus = ($code >= 400 && $code < 600) ? (int)$code : 200;
    qq_out(array_merge(array('code' => $code, 'msg' => $msg), $extra), $httpStatus);
}

/**
 * 提取并校验开发者会话三元组 (从 GET/POST 参数)
 * @param bool $required false 时允许全部为空 (登录二维码场景)，返回 null
 * @return array|null {uin, developerId, ticket}
 */
function qq_session($required = true) {
    $uin    = trim((string)(isset($_REQUEST['uin']) ? $_REQUEST['uin'] : ''));
    $devId  = trim((string)(isset($_REQUEST['developerId']) ? $_REQUEST['developerId'] : (isset($_REQUEST['uid']) ? $_REQUEST['uid'] : '')));
    $ticket = trim((string)(isset($_REQUEST['ticket']) ? $_REQUEST['ticket'] : ''));

    if (!$required && $uin === '' && $devId === '' && $ticket === '') {
        return null;
    }
    if ($uin === '' || $devId === '' || $ticket === '') {
        qq_fail(400, '参数不完整 (需要 uin / developerId / ticket)');
    }
    foreach (array($uin, $devId, $ticket) as $v) {
        if (!qq_valid_session_value($v)) {
            qq_fail(400, '会话参数格式不合法');
        }
    }
    return array('uin' => $uin, 'developerId' => $devId, 'ticket' => $ticket);
}

/** 组装请求头 (写接口对非浏览器 UA 可能直接拒绝，统一伪装成浏览器) */
function qq_headers($session, $referer = 'https://q.qq.com/qqbot/') {
    $headers = array(
        'Content-Type: application/json',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        'Accept-Encoding: gzip',
        'User-Agent: ' . QQ_UA,
        'Referer: ' . $referer,
        'Origin: https://' . parse_url($referer, PHP_URL_HOST),
    );
    if ($session) {
        $headers[] = 'Cookie: quin=' . $session['uin']
            . '; quid=' . $session['developerId']
            . '; qticket=' . $session['ticket'];
    }
    return $headers;
}

/**
 * 统一请求上游接口
 * @param string     $method  GET / POST
 * @param string     $url     完整 URL
 * @param array|null $session 会话三元组
 * @param mixed      $body    请求体 (数组自动 json_encode)
 * @param string     $referer Referer
 * @return array 上游 JSON (非 JSON 时返回 array('raw' => ...))
 */
function qq_request($method, $url, $session = null, $body = null, $referer = 'https://q.qq.com/qqbot/') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, qq_headers($session, $referer));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
    curl_setopt($ch, CURLOPT_TIMEOUT, QQ_TIMEOUT);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 与原 PHP 抓包脚本一致；环境有 CA 证书时建议开启
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    $resp = curl_exec($ch);
    if ($resp === false || curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        qq_fail(502, 'QQ平台网络请求失败: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 400) {
        qq_fail(502, 'QQ平台请求失败 (HTTP ' . $status . ')', array('raw' => qq_substr((string)$resp, 0, 500)));
    }
    if (substr((string)$resp, 0, 3) === "\x1f\x8b\x08") { // gzip 兜底解码
        $resp = gzdecode($resp);
    }
    $data = json_decode((string)$resp, true);
    if (is_array($data)) {
        return $data;
    }
    return array('raw' => qq_substr((string)$resp, 0, 2000));
}

/** 安全截断 (无 mbstring 时回退 substr) */
function qq_substr($str, $start, $length) {
    return function_exists('mb_substr') ? mb_substr($str, $start, $length) : substr($str, $start, $length);
}

/** 取上游业务码 (兼容 retcode/code/errcode，字符串 "0" 也视为成功) */
function qq_retcode($data) {
    if (!is_array($data)) {
        return null;
    }
    foreach (array('retcode', 'code', 'errcode') as $k) {
        if (array_key_exists($k, $data)) {
            return $data[$k];
        }
    }
    return null;
}

/** 取上游业务消息 (兼容 msg/message/errmsg) */
function qq_retmsg($data) {
    if (!is_array($data)) {
        return null;
    }
    foreach (array('msg', 'message', 'errmsg') as $k) {
        if (isset($data[$k]) && $data[$k] !== '') {
            return $data[$k];
        }
    }
    return null;
}

/** 上游 retcode 是否成功 (兼容字符串 "0") */
function qq_is_ret_ok($data) {
    $rc = qq_retcode($data);
    return $rc === 0 || $rc === '0';
}

/** 二维码跳转 URL (login=登录授权页, 其他=扫码确认页) */
function qq_qr_url($qr, $ticket, $login = false) {
    if ($login) {
        return QQ_Q_BASE . '/login/applist?client=qq&code=' . $qr . '&ticket=null';
    }
    return QQ_Q_BASE . '/qrcode/check?client=qq&code=' . $qr . '&ticket=' . ($ticket !== null ? $ticket : 'null');
}

/**
 * 敏感操作提交前的最后一步: 实时校验扫码票据。
 * 必须紧贴业务 CGI 调用，中间不可插入其他请求，否则 retcode=24011「二维码校验未通过」。
 * @return array 校验通过时的上游响应
 */
function qq_verify_qrcode($session, $qrcode) {
    if (!qq_valid_qrcode($qrcode)) {
        qq_fail(400, '缺少合法的扫码确认 qrcode');
    }
    $data = qq_request('POST', QQ_Q_BASE . '/qrcode/get', $session, array('qrcode' => $qrcode), 'https://q.qq.com/');
    if (!qq_is_ret_ok($data)) {
        $msg = qq_retmsg($data);
        qq_fail(400,
            '二维码校验未通过' . ($msg ? '：' . $msg : '') . '。请用【登录开放平台的同一个QQ】扫码并在手机上点击允许后，立即重试',
            array('upstream' => $data));
    }
    return $data;
}

/** 读取 JSON 请求体 (非 JSON 时回退 $_REQUEST) */
function qq_json_body() {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $d = json_decode($raw, true);
        if (is_array($d)) {
            return $d;
        }
    }
    return $_REQUEST;
}

/**
 * 业务参数提取 (cgi.php / bopen.php 共用)，四种传法任选:
 *   1) form/query 数组展开: params[start]=0&params[limit]=30
 *   2) params=JSON字符串:   params={"start":0,"limit":30}
 *   3) JSON 请求体:         {"params":{...}}
 *   4) 兜底: query/form/JSON体 中除控制键外的其余键
 * @param array $controlKeys 不作为业务参数的键名
 */
function qq_extract_params($controlKeys = array('action', 'appid', 'uin', 'developerId', 'uid', 'ticket', 'ep', 'type', 'qrcode', 'qr_type', 'no_app', 'params')) {
    if (isset($_REQUEST['params'])) {
        if (is_array($_REQUEST['params'])) {
            return $_REQUEST['params'];
        }
        if (is_string($_REQUEST['params']) && $_REQUEST['params'] !== '') {
            $decoded = json_decode($_REQUEST['params'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    $body = qq_json_body();
    if (isset($body['params']) && is_array($body['params'])) {
        return $body['params'];
    }
    $params = array();
    foreach ($body as $k => $v) { // JSON 体平铺键
        if ($k === 'params' || in_array($k, $controlKeys, true)) continue;
        $params[$k] = $v;
    }
    foreach ($_REQUEST as $k => $v) { // query/form 键优先
        if (in_array($k, $controlKeys, true)) continue;
        $params[$k] = $v;
    }
    return $params;
}

/**
 * 参数清洗 (与 Node 版 sanitizeParams 行为一致):
 * 字符串截断 4096、列表限长 100、对象限 50 键且键名受限、深度限 4 层
 */
function qq_sanitize($input, $depth = 0) {
    if ($input === null) {
        return null;
    }
    if (is_bool($input)) {
        return $input;
    }
    if (is_int($input) || is_float($input)) {
        return $input;
    }
    if (is_string($input)) {
        return qq_substr($input, 0, 4096);
    }
    if ($depth >= 4) {
        return substr(json_encode($input, JSON_UNESCAPED_UNICODE), 0, 4096);
    }
    if (is_array($input)) {
        $out = array();
        if (array_values($input) === $input) { // 列表
            foreach (array_slice($input, 0, 100) as $v) {
                $sv = qq_sanitize($v, $depth + 1);
                if ($sv !== null) {
                    $out[] = $sv;
                }
            }
            return $out;
        }
        foreach (array_slice(array_keys($input), 0, 50) as $k) { // 关联数组
            if (!preg_match('/^[A-Za-z0-9_:.]{1,64}$/', (string)$k)) {
                continue;
            }
            $sv = qq_sanitize($input[$k], $depth + 1);
            if ($sv !== null) {
                $out[$k] = $sv;
            }
        }
        return $out;
    }
    return null;
}

/**
 * [新版] 机器人管理端 CGI action 白名单 (与 src/services/openPlatformService.js 的 CGI_ACTIONS 同步)
 *
 * 字段说明:
 *   m   : GET(query参数) / POST(JSON body)
 *   p   : 上游路径 (基址 https://bot.q.qq.com)
 *   bot : true 时服务端自动注入 bot_appid (覆盖调用方传值，防越权)
 *   qr  : 扫码票据字段名 (该 action 为敏感操作，提交前实时校验票据)
 *
 * 敏感操作二维码 type 映射 (抓包 q.qq.com/qqbot SPA 模块 QRTYPE，type 用错必返回 retcode=24011):
 *   40=Ark模板删除(带appid)/可用范围切换(no_app=1, miniAppId传空串)  48=回调地址修改
 *   49=域名报备  50=Webhook设置/事件订阅/重置Token  51=IP白名单  53=重置AppSecret
 */
function qq_cgi_actions() {
    return array(
        // ---------- 查询 (GET) ----------
        'info.query'             => array('m' => 'GET',  'p' => '/cgi-bin/info/query',                       'bot' => true,  'desc' => '机器人基础信息 (旧版 info_query 同源)'),
        'msg_tpl.statistics'     => array('m' => 'GET',  'p' => '/cgi-bin/msg_tpl/statistics',               'bot' => true,  'desc' => '消息模板统计'),
        'ark_url.query'          => array('m' => 'GET',  'p' => '/cgi-bin/ark_url/query',                    'bot' => true,  'desc' => 'Ark 域名报备列表 (旧版 ark_url_query 同源)'),
        'ark_url.check'          => array('m' => 'GET',  'p' => '/cgi-bin/ark_url/check',                    'bot' => true,  'desc' => '校验 URL 可否报备 (params: url)'),
        'callback.query'         => array('m' => 'GET',  'p' => '/cgi-bin/callback/query',                   'bot' => true,  'desc' => '回调/Webhook 配置查询'),
        'callback.check_icp'     => array('m' => 'GET',  'p' => '/cgi-bin/callback/check_icp',               'bot' => false, 'desc' => '回调域名 ICP 备案校验 (params: url)'),
        'white_ip.query'         => array('m' => 'GET',  'p' => '/cgi-bin/dev_info/white_ip_config',         'bot' => true,  'desc' => 'IP 白名单查询 (旧版 white_list 同源)'),
        'access_token_ttl.query' => array('m' => 'GET',  'p' => '/cgi-bin/dev_info/access_token_ttl',        'bot' => true,  'desc' => 'AccessToken 有效期查询'),
        'dev_info.query'         => array('m' => 'GET',  'p' => '/cgi-bin/dev_info/query',                   'bot' => true,  'desc' => '开发者信息/反馈配置查询'),
        'group.list'             => array('m' => 'GET',  'p' => '/cgi-bin/dev_info/group/list',              'bot' => true,  'desc' => '机器人群列表'),
        'guild.list'             => array('m' => 'GET',  'p' => '/cgi-bin/dev_info/guild/list',              'bot' => true,  'desc' => '机器人频道列表'),
        'feature.query'          => array('m' => 'GET',  'p' => '/cgi-bin/feature/query',                    'bot' => true,  'desc' => '功能配置查询'),
        'feature.count'          => array('m' => 'GET',  'p' => '/cgi-bin/feature/count',                    'bot' => true,  'desc' => '功能数量统计'),
        'feature.limit'          => array('m' => 'GET',  'p' => '/cgi-bin/feature/limit',                    'bot' => true,  'desc' => '功能上限查询'),
        'scope.query'            => array('m' => 'GET',  'p' => '/cgi-bin/scope/query',                      'bot' => true,  'desc' => '可用范围对象列表 (params: robot_scene 1频道/2群/3单聊, page_size, page_num)'),
        'scene.query'            => array('m' => 'GET',  'p' => '/cgi-bin/scene/query',                      'bot' => true,  'desc' => '场景状态查询 (1频道/2群/3单聊)'),
        'corpus.list'            => array('m' => 'GET',  'p' => '/cgi-bin/corpus/list',                      'bot' => true,  'desc' => '官方语料列表'),
        'corpus.status'          => array('m' => 'GET',  'p' => '/cgi-bin/corpus/status',                    'bot' => true,  'desc' => '官方语料开关状态'),
        'corpus.search'          => array('m' => 'GET',  'p' => '/cgi-bin/corpus/search',                    'bot' => true,  'desc' => '官方语料搜索 (params: keyword)'),

        // ---------- 操作 (POST) ----------
        'white_ip.update'        => array('m' => 'POST', 'p' => '/cgi-bin/dev_info/update_white_ip_config',  'bot' => true, 'qr' => 'qr_code', 'desc' => '更新IP白名单 (params: ip_white_infos, qr_code 需扫码 type=51)'),
        'msg_tpl.list'           => array('m' => 'POST', 'p' => '/cgi-bin/msg_tpl/list',                     'bot' => true,  'desc' => '消息模板列表 (params: start, limit)'),
        'msg_tpl.create'         => array('m' => 'POST', 'p' => '/cgi-bin/msg_tpl/create',                   'bot' => true,  'desc' => '创建消息模板'),
        'msg_tpl.modify'         => array('m' => 'POST', 'p' => '/cgi-bin/msg_tpl/modify',                   'bot' => true,  'desc' => '修改消息模板'),
        'msg_tpl.preview'        => array('m' => 'POST', 'p' => '/cgi-bin/msg_tpl/preview',                  'bot' => true,  'desc' => '消息模板预览'),
        'msg_tpl.switch'         => array('m' => 'POST', 'p' => '/cgi-bin/msg_tpl/switch',                   'bot' => true,  'desc' => '启用/停用消息模板'),
        'msg_tpl.audit'          => array('m' => 'POST', 'p' => '/cgi-bin/msg_tpl/audit',                    'bot' => true,  'desc' => '提交消息模板审核'),
        'event.list'             => array('m' => 'POST', 'p' => '/cgi-bin/event_subscirption/list_event',    'bot' => true,  'desc' => '事件订阅列表 (旧版同源, 官方拼写就是 subscirption)'),
        'event.modify'           => array('m' => 'POST', 'p' => '/cgi-bin/event_subscirption/modify',        'bot' => true, 'qr' => 'qr_code', 'desc' => '修改事件订阅 (params: event_ids 全量已订阅ID数组, qr_code 需扫码 type=50)'),
        'ark_url.modify'         => array('m' => 'POST', 'p' => '/cgi-bin/ark_url/modify',                   'bot' => true, 'qr' => 'qr_code', 'desc' => '域名报备整体提交 (params: urls 全量https地址数组, qr_code 需扫码 type=49)'),
        'callback.modify'        => array('m' => 'POST', 'p' => '/cgi-bin/callback/modify',                  'bot' => true, 'qr' => 'qr_code', 'desc' => '修改回调配置 (params: callback_urls 全量地址数组, qr_code 需扫码 type=48)'),
        'callback.get_webhook'   => array('m' => 'POST', 'p' => '/cgi-bin/callback/get_webhook',             'bot' => true,  'desc' => '获取 webhook 地址'),
        'callback.set_webhook'   => array('m' => 'POST', 'p' => '/cgi-bin/callback/set_webhook',             'bot' => true, 'qr' => 'qr_code', 'desc' => '设置 webhook 地址 (params: webhook_url, qr_code 需扫码 type=50)'),
        'callback.check_webhook' => array('m' => 'POST', 'p' => '/cgi-bin/callback/check_webhook',           'bot' => true,  'desc' => '校验 webhook 可用性 (params: webhook_url)'),
        'callback.webhook_whitelist' => array('m' => 'POST', 'p' => '/cgi-bin/callback/webhook_whitelist',   'bot' => true,  'desc' => 'webhook 白名单状态查询'),
        'sandbox.query'          => array('m' => 'POST', 'p' => '/cgi-bin/sandbox/query',                    'bot' => true,  'desc' => '沙箱配置查询'),
        'sandbox.add'            => array('m' => 'POST', 'p' => '/cgi-bin/sandbox/add',                      'bot' => true,  'desc' => '添加沙箱对象 (params: robot_scene, ids[])'),
        'sandbox.del'            => array('m' => 'POST', 'p' => '/cgi-bin/sandbox/del',                      'bot' => true,  'desc' => '删除沙箱对象 (params: robot_scene, ids[])'),
        'sandbox.update'         => array('m' => 'POST', 'p' => '/cgi-bin/sandbox/update',                   'bot' => true,  'desc' => '更新沙箱对象 (params: robot_scene, id)'),
        'sandbox.get_creator_uin' => array('m' => 'POST', 'p' => '/cgi-bin/sandbox/get_creator_uin',         'bot' => true,  'desc' => '查询沙箱创建者QQ'),
        'scope.mode.query'       => array('m' => 'POST', 'p' => '/cgi-bin/scope/mode/query',                 'bot' => true,  'desc' => '可用范围模式查询'),
        'scope.mode.switch'      => array('m' => 'POST', 'p' => '/cgi-bin/scope/mode/switch',                'bot' => true, 'qr' => 'qrcode',  'desc' => '切换可用范围模式 (params: options[{robot_scene,mode}], qrcode 需扫码 type=40+no_app=1)'),
        'scope.add'              => array('m' => 'POST', 'p' => '/cgi-bin/scope/add',                        'bot' => true,  'desc' => '添加可用范围对象 (params: robot_scene, id)'),
        'scope.del'              => array('m' => 'POST', 'p' => '/cgi-bin/scope/del',                        'bot' => true,  'desc' => '移除可用范围对象 (params: robot_scene, ids[])'),
        'feature.modify'         => array('m' => 'POST', 'p' => '/cgi-bin/feature/modify',                   'bot' => true,  'desc' => '修改功能配置'),
        'feature.scene.query'    => array('m' => 'POST', 'p' => '/cgi-bin/feature/scene/query',              'bot' => true,  'desc' => '允许开通的场景查询'),
        'feature.queryDeveloper' => array('m' => 'POST', 'p' => '/cgi-bin/feature/queryDeveloper',            'bot' => true,  'desc' => '开发者灰度白名单查询'),
        'authz.records'          => array('m' => 'POST', 'p' => '/cgi-bin/auth_page/authz_records',          'bot' => true,  'desc' => '授权记录列表'),
        'authz.cancel'           => array('m' => 'POST', 'p' => '/cgi-bin/auth_page/cancel_authorize',       'bot' => true,  'desc' => '取消授权'),
        'audit.submit'           => array('m' => 'POST', 'p' => '/cgi-bin/audit/submit',                     'bot' => true,  'desc' => '提交发布审核'),
        'audit.recall'           => array('m' => 'POST', 'p' => '/cgi-bin/audit/recall',                     'bot' => true,  'desc' => '撤回审核'),
        'audit.publish'          => array('m' => 'POST', 'p' => '/cgi-bin/audit/publish',                    'bot' => true,  'desc' => '发布机器人'),
        'info.modify'            => array('m' => 'POST', 'p' => '/cgi-bin/info/modify',                      'bot' => true,  'desc' => '修改机器人资料'),
        'profile.audit'          => array('m' => 'POST', 'p' => '/cgi-bin/profile/audit',                   'bot' => true,  'desc' => '资料提交审核'),
        'msgbackground.modify'   => array('m' => 'POST', 'p' => '/cgi-bin/msgbackground/modify',             'bot' => true,  'desc' => '修改消息卡片背景'),
        'welcomeinfo.modify'     => array('m' => 'POST', 'p' => '/cgi-bin/welcomeinfo/modify',               'bot' => true,  'desc' => '修改欢迎语'),
        'statement.modify'       => array('m' => 'POST', 'p' => '/cgi-bin/statement/modify',                 'bot' => true,  'desc' => '修改功能声明'),
        'dev_info.modify'        => array('m' => 'POST', 'p' => '/cgi-bin/dev_info/modify',                   'bot' => true,  'desc' => '修改开发者配置'),
    );
}

/**
 * [新版] 统一开发者控制台 /bopen/v2/* 端点白名单 (三元组鉴权实测可用)
 * 响应格式: {common:{code,msg}, data:{...}}
 * 注意: auth_qq/gets 需 Page>=1；主体认证敏感操作二维码用 gen_qr_auth (scene: 9=BotModSecret/10=BotServiceRange/11=BotModProfile)
 */
function qq_bopen_eps() {
    return array(
        // ---- 认证QQ账号管理 /bopen/v2/auth_qq/* ----
        'auth_qq/gets', 'auth_qq/able_login', 'auth_qq/disable_login', 'auth_qq/get_regist_info',
        'auth_qq/get_human_face_status', 'auth_qq/check_create_status', 'auth_qq/create_auth_account',
        'auth_qq/create_mission', 'auth_qq/modify_profile', 'auth_qq/get_bind_groups',
        'auth_qq/query_bind_groups', 'auth_qq/submit_bind', 'auth_qq/withdraw_bind', 'auth_qq/revoke_bind',
        // ---- 二维码 / 开发者注册 / 认证 ----
        'gen_qr', 'gen_qr_auth', 'get_qr_status', 'get_qr_status_auth',
        'register_developer', 'register_by_email', 'verify_identity', 'authorize_subject',
        'activate_email', 'get_phone_code', 'get_ocr_code', 'getbank', 'modify_administrator',
        'get_audit_developer_info', 'check_pre_account', 'get_pre_account', 'check_subject_status', 'upload_file1',
    );
}
