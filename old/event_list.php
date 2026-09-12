<?php
/**
 * [旧版] 事件订阅列表 (接口拼写沿用官方 subscirption)
 * 上游: POST https://bot.q.qq.com/cgi-bin/event_subscirption/list_event  body: {bot_appid}
 *
 * 参数: appid, uin, developerId(或uid), ticket
 * 输出: 上游 JSON 原样透传
 * 对应原文件: bot_event.php
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$appid = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));
if ($appid === '') {
    qq_fail(400, '参数不完整 (appid)');
}

qq_out(qq_request('POST', QQ_BOT_BASE . '/cgi-bin/event_subscirption/list_event', $session, array(
    'bot_appid' => $appid,
)));
