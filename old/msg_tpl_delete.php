<?php
/**
 * [旧版] 删除 Ark 消息模板 (敏感操作，需扫码 type=40)
 * 上游: POST https://bot.q.qq.com/cgi-bin/msg_tpl/delete  body: {bot_appid, tpl_id:[], qrcode}
 *
 * 参数: appid, uin, developerId(或uid), ticket, tplid (多个用 # 分隔),
 *       qrcode (由 qrcode_auth.php type=40 取得，用登录态同一个QQ扫码并在手机上允许)
 * 输出: 上游 JSON 原样透传
 * 流程: 扫码(type=40) -> 手机允许 -> 立即调用本接口 (内部先实时校验票据再提交)
 * 对应原文件: tpl_del.php
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$appid  = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));
$tplid  = trim((string)(isset($_REQUEST['tplid']) ? $_REQUEST['tplid'] : ''));
$qrcode = trim((string)(isset($_REQUEST['qrcode']) ? $_REQUEST['qrcode'] : ''));

if ($appid === '' || $tplid === '') {
    qq_fail(400, '参数不完整 (appid / tplid)');
}
$tplIds = array_values(array_filter(array_map('trim', explode('#', $tplid)), 'strlen'));
if (count($tplIds) === 0) {
    qq_fail(400, 'tplid 不能为空');
}
if (!qq_valid_qrcode($qrcode)) {
    qq_fail(400, '缺少合法的扫码确认 qrcode (需由 qrcode_auth.php type=40 创建)');
}

// 提交删除前最后一步实时校验扫码票据 (防 retcode=24011)，校验后立即提交
qq_verify_qrcode($session, $qrcode);

qq_out(qq_request('POST', QQ_BOT_BASE . '/cgi-bin/msg_tpl/delete', $session, array(
    'bot_appid' => $appid,
    'tpl_id' => $tplIds,
    'qrcode' => $qrcode,
)));
