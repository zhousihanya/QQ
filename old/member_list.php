<?php
/**
 * [旧版] 获取成员列表 (q.qq.com/pb 域)
 * 上游: POST https://q.qq.com/pb/GetMemberList  body: {appType, appid}
 *
 * 参数: appid, uin, developerId(或uid), ticket, appType (默认1, 1=机器人 2=小程序)
 * 输出: 上游 JSON 原样透传
 *
 * 注意: q.qq.com/pb/* 需完整浏览器登录态（浏览器 Cookie 中的 skey/p_skey 等会话绑定），
 * 裸三元组 (quin/quid/qticket) 会返回 code=-101185006「登录态校验失败」。
 * 如需可用，须在浏览器登录 q.qq.com 后将该域完整 Cookie 传入，
 * 本脚本仅透传参数中的三元组，完整 Cookie 场景请自行扩展 qq_headers()。
 * 对应原文件: get_member_list.php
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$appid = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));
if ($appid === '') {
    qq_fail(400, '参数不完整 (appid)');
}
$appType = isset($_REQUEST['appType']) ? (int)$_REQUEST['appType'] : 1;
if ($appType !== 1 && $appType !== 2) {
    $appType = 1;
}

qq_out(qq_request('POST', QQ_Q_BASE . '/pb/GetMemberList', $session, array(
    'appType' => $appType,
    'appid' => $appid,
)));
