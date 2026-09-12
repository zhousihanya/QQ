<?php
/**
 * [新版] 重置 AppSecret / AccessToken (敏感操作，需扫码二次确认)
 * 上游 (抓包 q.qq.com/qqbot SPA c159.js 字段):
 *   secret -> POST https://bot.q.qq.com/cgi-bin/dev_info/reset_secret  body {bot_appid, qr_code}      二维码 type=53
 *   token  -> POST https://bot.q.qq.com/cgi-bin/dev_info/reset_token   body {appid, bot_appid, qr_code} 二维码 type=50 (appid 与 bot_appid 同时携带)
 *
 * 参数: appid, uin, developerId(或uid), ticket, type=secret|token, qrcode
 *       (qrcode 由 qrcode_create.php qr_type=53|50 取得，用登录态同一个QQ扫码并在手机上允许)
 * 输出: {code:0, msg:"重置AppSecret成功", upstream:{...}}；失败时 {code:-1, msg, upstream}
 * 流程: 扫码 -> 手机允许 -> 立即调用本接口 (内部先实时校验票据再提交)
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$appid  = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));
$type   = strtolower(trim((string)(isset($_REQUEST['type']) ? $_REQUEST['type'] : '')));
$qrcode = trim((string)(isset($_REQUEST['qrcode']) ? $_REQUEST['qrcode'] : ''));

if ($appid === '') {
    qq_fail(400, '参数不完整 (appid)');
}
if (!in_array($type, array('secret', 'token'), true)) {
    qq_fail(400, "type 只支持 'secret' 或 'token'");
}
if (!qq_valid_qrcode($qrcode)) {
    qq_fail(400, '缺少合法的扫码确认 qrcode' . ($type === 'secret' ? ' (qr_type=53)' : ' (qr_type=50)'));
}

$label = $type === 'secret' ? '重置AppSecret' : '重置Token';

// 提交前最后一步实时校验扫码票据 (防 retcode=24011)，校验后立即提交
qq_verify_qrcode($session, $qrcode);

if ($type === 'secret') {
    $result = qq_request('POST', QQ_BOT_BASE . '/cgi-bin/dev_info/reset_secret', $session, array(
        'bot_appid' => $appid,
        'qr_code' => $qrcode,
    ));
} else {
    // reset_token 需同时携带 appid 与 bot_appid (抓包字段)
    $result = qq_request('POST', QQ_BOT_BASE . '/cgi-bin/dev_info/reset_token', $session, array(
        'appid' => $appid,
        'bot_appid' => $appid,
        'qr_code' => $qrcode,
    ));
}

if (!qq_is_ret_ok($result)) {
    qq_out(array(
        'code' => -1,
        'msg' => $label . '失败'
            . (qq_retmsg($result) ? '：' . qq_retmsg($result) : '')
            . ' (retcode=' . var_export(qq_retcode($result), true) . ')',
        'upstream' => $result,
    ));
}
qq_out(array('code' => 0, 'msg' => $label . '成功', 'upstream' => $result));
