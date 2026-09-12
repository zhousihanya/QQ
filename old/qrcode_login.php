<?php
/**
 * [旧版] 获取开发者登录二维码
 * 上游: POST https://q.qq.com/qrcode/create  body: {"type":777}
 *
 * 无需开发者会话。用 QQ 扫码返回 url 中的二维码后，通过 qrcode_verify.php
 * (或上游 /qrcode/get) 换取开发者会话 (uin/quid/qticket)。
 *
 * 参数: 无
 * 输出: {code:0, msg:"获取成功", qrcode:"...", url:"https://q.qq.com/login/applist?client=qq&code=...&ticket=null", type:777}
 * 对应原文件: get_login.php
 */
require __DIR__ . '/../common.php';

$data = qq_request('POST', QQ_Q_BASE . '/qrcode/create', null, array('type' => 777), 'https://q.qq.com/');
$qr = isset($data['data']['QrCode']) ? $data['data']['QrCode'] : null;
if (!$qr) {
    qq_fail(-1, '获取登录二维码失败', array('upstream' => $data));
}
qq_out(array(
    'code' => 0,
    'msg' => '获取成功',
    'qrcode' => $qr,
    'url' => qq_qr_url($qr, null, true),
    'type' => 777,
));
