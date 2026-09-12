<?php
/**
 * [旧版] 查询 IP 白名单
 * 上游: GET https://bot.q.qq.com/cgi-bin/dev_info/white_ip_config?bot_appid={appid}
 *
 * 参数: appid, uin, developerId(或uid), ticket
 * 输出: {code:0, msg:"获取成功", data:[ip...]}；失败时 {code:-1, msg, upstream}
 * 对应原文件: white_list.php
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$appid = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));
if ($appid === '') {
    qq_fail(400, '参数不完整 (appid)');
}

$data = qq_request('GET',
    QQ_BOT_BASE . '/cgi-bin/dev_info/white_ip_config?bot_appid=' . urlencode($appid),
    $session);

if (!qq_is_ret_ok($data)) {
    qq_out(array('code' => -1, 'msg' => '获取白名单失败', 'upstream' => $data));
}
$ipList = array();
if (isset($data['data']['ip_white_infos']['prod']['ip_list'])
    && is_array($data['data']['ip_white_infos']['prod']['ip_list'])) {
    $ipList = array_values($data['data']['ip_white_infos']['prod']['ip_list']);
}
qq_out(array('code' => 0, 'msg' => '获取成功', 'data' => $ipList));
