<?php
/**
 * [旧版] 校验扫码结果
 * 上游: POST https://q.qq.com/qrcode/get  body: {"qrcode":"..."}
 * 上游 code==0 即已授权，票据可用于对应的敏感操作。
 *
 * 注意: 敏感操作的正确流程是「扫码 -> 手机上允许 -> 立即调用业务接口提交」。
 * 业务接口内部会在提交前再实时校验一次票据；前端不要循环轮询本接口，
 * 轮询可能干扰票据状态导致 retcode=24011。
 *
 * 参数: qrcode, uin, developerId(或uid), ticket
 * 输出: {code:0, msg:"授权成功"} / {code:-1, msg:"未授权", upstream:{...}}
 * 对应原文件: verify.php
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$qrcode  = trim((string)(isset($_REQUEST['qrcode']) ? $_REQUEST['qrcode'] : ''));
if ($qrcode === '') {
    qq_fail(400, '参数不完整 (qrcode)');
}

$data = qq_request('POST', QQ_Q_BASE . '/qrcode/get', $session, array('qrcode' => $qrcode), 'https://q.qq.com/');
if (qq_is_ret_ok($data)) {
    qq_out(array('code' => 0, 'msg' => '授权成功'));
}
qq_out(array('code' => -1, 'msg' => '未授权', 'upstream' => $data));
