<?php
/**
 * [旧版] Ark 消息模板列表 (分页)
 * 上游: POST https://bot.q.qq.com/cgi-bin/msg_tpl/list  body: {bot_appid, start, limit}
 *
 * 参数: appid, uin, developerId(或uid), ticket, start=0, limit=30 (1~100)
 * 输出: 上游 JSON 原样透传
 * 对应原文件: msg_tpl_list.php
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$appid = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));
if ($appid === '') {
    qq_fail(400, '参数不完整 (appid)');
}
$start = isset($_REQUEST['start']) ? (int)$_REQUEST['start'] : 0;
$limit = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 30;
$limit = min(max($limit, 1), 100);

qq_out(qq_request('POST', QQ_BOT_BASE . '/cgi-bin/msg_tpl/list', $session, array(
    'bot_appid' => $appid,
    'start' => $start,
    'limit' => $limit,
)));
