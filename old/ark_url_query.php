<?php
/**
 * [旧版] Ark 模板消息 URL 报备列表
 * 上游: GET https://bot.q.qq.com/cgi-bin/ark_url/query?bot_appid={appid}
 *
 * 参数: appid, uin, developerId(或uid), ticket
 * 输出: 上游 JSON 原样透传
 * 对应原文件: ark_url_query.php
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$appid = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));
if ($appid === '') {
    qq_fail(400, '参数不完整 (appid)');
}

qq_out(qq_request('GET',
    QQ_BOT_BASE . '/cgi-bin/ark_url/query?bot_appid=' . urlencode($appid),
    $session));
