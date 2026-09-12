<?php
/**
 * [旧版] 获取敏感操作授权二维码 (需登录态)
 * 上游: POST https://q.qq.com/qrcode/create  body: {"type":40|51, "miniAppId":appid}
 *
 * type 场景映射 (type 用错时业务接口会返回 retcode=24011):
 *   40               = Ark 消息模板删除 (miniAppId=机器人appid)
 *   40 + miniAppId=""= 可用范围模式切换
 *   51               = IP 白名单更新
 *
 * 参数: appid, uin, developerId(或uid), ticket, type=40|51 (默认51)
 *       (type=40 且 appid 传空 = 可用范围模式切换场景)
 * 输出: {code:0, msg:"获取成功", qrcode, url, type}
 * 对应原文件: white_login.php (type=51) / get_check.php (type=40)
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$qrType  = (int)(isset($_REQUEST['type']) ? $_REQUEST['type'] : 51);
$appid   = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));

if (!in_array($qrType, array(40, 51), true)) {
    qq_fail(400, '旧版授权二维码 type 仅支持 40 (模板删除/范围切换) 或 51 (IP白名单)');
}
if ($appid === '' && $qrType !== 40) {
    qq_fail(400, '参数不完整 (appid)');
}

$data = qq_request('POST', QQ_Q_BASE . '/qrcode/create', $session,
    array('type' => $qrType, 'miniAppId' => $appid), 'https://q.qq.com/');
$qr = isset($data['data']['QrCode']) ? $data['data']['QrCode'] : null;
if (!$qr) {
    qq_fail(-1, '获取二维码失败', array('upstream' => $data));
}
qq_out(array(
    'code' => 0,
    'msg' => '获取成功',
    'qrcode' => $qr,
    'url' => qq_qr_url($qr, $session['ticket']),
    'type' => $qrType,
));
