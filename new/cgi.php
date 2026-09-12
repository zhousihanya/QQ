<?php
/**
 * [新版] 新版机器人管理端通用 CGI 代理 (q.qq.com/qqbot/ SPA 全量接口)
 * 上游基址: https://bot.q.qq.com/cgi-bin/* (与旧版同基址同鉴权, quid/qticket/quin 三元组)
 *
 * 通过 action 白名单开放新版管理端全部接口 (57 个, 清单见 actions.php / common.php qq_cgi_actions()):
 *   - bot_appid 一律以本接口的 appid 参数服务端注入，覆盖 params 中的同名值 (防越权)
 *   - 需要扫码的 action (qr 字段) 会在提交业务 CGI 前最后一步实时校验扫码票据
 *   - 上游 retcode 不做拦截，原样透传 (各接口返回结构不一)
 *
 * 参数:
 *   appid / uin / developerId(或uid) / ticket : URL query 或 form 传入
 *   action : 白名单中的 action
 *   params : 业务参数，四种传法任选 (见 common.php qq_extract_params()):
 *            1) form/query 数组展开: params[start]=0&params[limit]=30
 *            2) params=JSON字符串:   params={"start":0,"limit":30}
 *            3) JSON 请求体:         {"params":{"start":0,"limit":30}}
 *            4) query 平铺:          ?action=msg_tpl.list&start=0&limit=30
 *   GET action 参数拼到 query (数组/对象 JSON 编码，与官方前端一致)；POST action 参数走 JSON body
 *
 * 示例:
 *   GET  new/cgi.php?action=info.query&appid=123&uin=..&developerId=..&ticket=..
 *   GET  new/cgi.php?action=white_ip.query&appid=123&uin=..&developerId=..&ticket=..
 *   POST new/cgi.php?action=sandbox.add&appid=123&uin=..&developerId=..&ticket=..
 *        Content-Type: application/json
 *        {"params":{"robot_scene":2,"ids":["群ID"]}}
 *   敏感操作示例 (更新IP白名单):
 *        1) new/qrcode_create.php?qr_type=51&appid=123&...   -> 得到 qrcode
 *        2) 扫码并在手机允许
 *        3) POST new/cgi.php?action=white_ip.update&appid=123&...
 *           {"params":{"ip_white_infos":{"prod":{"ip_list":["1.2.3.4"],"use":true}},"qr_code":"<qrcode>"}}
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$appid  = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));
$action = trim((string)(isset($_REQUEST['action']) ? $_REQUEST['action'] : ''));
if ($appid === '') {
    qq_fail(400, '参数不完整 (appid)');
}

$actions = qq_cgi_actions();
if (!isset($actions[$action])) {
    qq_fail(400, '不支持的接口 action (可用清单见 actions.php)');
}
$def = $actions[$action];

// ---- 业务参数提取与清洗 ----
$params = qq_extract_params();
$params = qq_sanitize($params);
if (!is_array($params)) {
    $params = array();
}

// bot_appid 服务端强制注入 (覆盖调用方传值，防越权)
if (!empty($def['bot'])) {
    $params['bot_appid'] = $appid;
}

// ---- 敏感操作: 提交业务 CGI 前最后一步实时校验扫码票据 (防 retcode=24011) ----
if (!empty($def['qr'])) {
    $qrField = $def['qr'];
    $qrTicket = isset($params[$qrField]) ? (string)$params[$qrField] : '';
    if ($qrTicket === '') {
        qq_fail(400, "该操作需要扫码二次确认 (参数 {$qrField})。请先通过 qrcode_create.php 创建对应 type 的二维码，用登录态同一个 QQ 扫码并在手机上允许后立即提交");
    }
    if (!qq_valid_qrcode($qrTicket)) {
        qq_fail(400, "扫码票据 {$qrField} 格式不合法");
    }
    qq_verify_qrcode($session, $qrTicket);
    // 校验通过后立即提交，中间不可插入其他请求
}

// ---- 发起请求 ----
if ($def['m'] === 'GET') {
    $parts = array();
    foreach ($params as $k => $v) {
        // 数组/对象 JSON 编码后再进 query，与官方前端 axios params 序列化一致
        $val = (is_array($v) || is_object($v)) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v;
        $parts[] = rawurlencode((string)$k) . '=' . rawurlencode($val);
    }
    $qs = implode('&', $parts);
    qq_out(qq_request('GET', QQ_BOT_BASE . $def['p'] . ($qs !== '' ? '?' . $qs : ''), $session));
}

qq_out(qq_request('POST', QQ_BOT_BASE . $def['p'], $session, $params));
