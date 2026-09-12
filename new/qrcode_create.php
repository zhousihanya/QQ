<?php
/**
 * [新版] 创建敏感操作确认二维码 (需登录态)
 * 上游: POST https://q.qq.com/qrcode/create  body: {type, miniAppId}
 *
 * type 场景精确映射 (抓包 q.qq.com/qqbot SPA 模块 QRTYPE；type 用错时
 * 业务接口即使票据有效也会返回 retcode=24011「票据场景与操作不匹配」):
 *   40 = Ark 消息模板删除 (miniAppId=appid)
 *        可用范围模式切换 (no_app=1 时 miniAppId 传空串)
 *   48 = 事件回调地址修改 (callback.modify)
 *   49 = 域名报备 (ark_url.modify)
 *   50 = 开发配置: Webhook设置(callback.set_webhook) / 事件订阅(event.modify) / 重置Token
 *   51 = IP 白名单更新 (white_ip.update)
 *   53 = 重置 AppSecret (reset_credentials.php type=secret)
 *
 * 参数: appid, uin, developerId(或uid), ticket, qr_type=40|48|49|50|51|53, no_app=1 (仅 type=40 范围切换)
 * 输出: {code:0, msg:"获取成功", qrcode, url, type}
 *
 * 正确流程: 拿到 url 生成二维码 -> 用【登录开放平台的同一个QQ】扫码并在手机上允许
 *          -> 立即调用业务接口提交 (业务接口内部会实时校验票据，不要轮询)
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$qrType = isset($_REQUEST['qr_type']) ? (int)$_REQUEST['qr_type'] : 0;
$appid  = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));
$noApp  = !empty($_REQUEST['no_app']);

if (!in_array($qrType, array(40, 48, 49, 50, 51, 53), true)) {
    qq_fail(400, 'qr_type 仅支持 40/48/49/50/51/53 (场景映射见文件头注释)');
}
// 仅 type=40 且 no_app=1 (可用范围切换) 允许不带 appid
if ($appid === '' && !($noApp && $qrType === 40)) {
    qq_fail(400, '该类型二维码需要 appid');
}

$body = ($noApp && $qrType === 40)
    ? array('type' => $qrType, 'miniAppId' => '')
    : array('type' => $qrType, 'miniAppId' => $appid);

$data = qq_request('POST', QQ_Q_BASE . '/qrcode/create', $session, $body, 'https://q.qq.com/');
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
