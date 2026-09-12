<?php
/**
 * [旧版] 查询机器人基础信息
 * 上游: GET https://bot.q.qq.com/cgi-bin/info/query?bot_appid={appid}
 *
 * 参数: appid, uin, developerId(或uid), ticket
 * 输出: 上游 JSON 原样透传 {retcode, msg, data}
 * 对应原文件: info_query.php
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$appid = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));
if ($appid === '') {
    qq_fail(400, '参数不完整 (appid)');
}

qq_out(qq_request('GET',
    QQ_BOT_BASE . '/cgi-bin/info/query?bot_appid=' . urlencode($appid),
    $session, null,
    'https://q.qq.com/qqbot/#/developer/developer-setting'));
