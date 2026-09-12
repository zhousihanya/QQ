<?php
/**
 * [新版] 新版机器人管理端 CGI action 清单
 * 上游基址: https://bot.q.qq.com/cgi-bin/* (与旧版同基址同鉴权, quid/qticket/quin 三元组)
 *
 * 白名单定义在 common.php 的 qq_cgi_actions()，与 Node 版
 * src/services/openPlatformService.js 的 CGI_ACTIONS 保持同步 (57 个 action)。
 *
 * 参数: 无
 * 输出: {code:0, msg:"ok", data:[{action, method, qr, desc}, ...]}
 *       qr = 该 action 为敏感操作所需的扫码票据字段名 (null 表示不需要扫码)
 */
require __DIR__ . '/../common.php';

$list = array();
foreach (qq_cgi_actions() as $action => $def) {
    $list[] = array(
        'action' => $action,
        'method' => $def['m'],
        'qr' => isset($def['qr']) ? $def['qr'] : null,
        'desc' => $def['desc'],
    );
}
qq_out(array('code' => 0, 'msg' => 'ok', 'total' => count($list), 'data' => $list));
