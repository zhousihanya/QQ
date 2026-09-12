<?php
/**
 * [旧版] 更新 IP 白名单 (新增/删除单个 IP，敏感操作需扫码 type=51)
 * 上游: GET  /cgi-bin/dev_info/white_ip_config        (取当前列表)
 *       POST /cgi-bin/dev_info/update_white_ip_config (提交全量列表)
 *
 * 参数: appid, uin, developerId(或uid), ticket, ip, action=add|del,
 *       qrcode (由 qrcode_auth.php type=51 取得，用登录态同一个QQ扫码并在手机上允许)
 * 输出: {code:0, msg:"新增成功"|"删除成功"}；各失败分支带 upstream 详情与 retcode
 * 流程: 扫码(type=51) -> 手机允许 -> 立即调用本接口 (内部先实时校验票据再提交)
 * 对应原文件: update_white_list.php
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$appid  = trim((string)(isset($_REQUEST['appid']) ? $_REQUEST['appid'] : ''));
$ip     = trim((string)(isset($_REQUEST['ip']) ? $_REQUEST['ip'] : ''));
$action = trim((string)(isset($_REQUEST['action']) ? $_REQUEST['action'] : ''));
$qrcode = trim((string)(isset($_REQUEST['qrcode']) ? $_REQUEST['qrcode'] : ''));

if ($appid === '' || $ip === '' || $action === '') {
    qq_fail(400, '参数不完整 (appid / ip / action)');
}
if (!in_array($action, array('add', 'del'), true)) {
    qq_fail(400, 'action 仅支持 add / del');
}
if (!qq_valid_qrcode($qrcode)) {
    qq_fail(400, '缺少合法的扫码确认 qrcode (需由 qrcode_auth.php type=51 创建)');
}

// 1. 查询当前白名单
$data = qq_request('GET',
    QQ_BOT_BASE . '/cgi-bin/dev_info/white_ip_config?bot_appid=' . urlencode($appid),
    $session);
if (!qq_is_ret_ok($data)) {
    qq_out(array(
        'code' => -1,
        'msg' => ($action === 'add' ? '获取白名单失败：无法新增 IP' : '获取白名单失败：无法删除 IP')
            . ' (retcode=' . var_export(qq_retcode($data), true) . ')',
        'upstream' => $data,
    ));
}
$ipList = array();
if (isset($data['data']['ip_white_infos']['prod']['ip_list'])
    && is_array($data['data']['ip_white_infos']['prod']['ip_list'])) {
    $ipList = $data['data']['ip_white_infos']['prod']['ip_list'];
}

// 2. 增删 IP
if ($action === 'add') {
    if (in_array($ip, $ipList, true)) {
        qq_fail(409, 'IP 已存在于白名单中');
    }
    $ipList[] = $ip;
} else {
    $key = array_search($ip, $ipList, true);
    if ($key === false) {
        qq_fail(404, 'IP 不在白名单中');
    }
    unset($ipList[$key]);
    $ipList = array_values($ipList);
}

// 3. 提交前最后一步实时校验扫码票据 (防 retcode=24011)，校验后立即提交
qq_verify_qrcode($session, $qrcode);

// 4. 提交全量列表
$result = qq_request('POST', QQ_BOT_BASE . '/cgi-bin/dev_info/update_white_ip_config', $session, array(
    'bot_appid' => $appid,
    'ip_white_infos' => array(
        'prod' => array(
            'ip_list' => array_values($ipList),
            'use' => true,
        ),
    ),
    'qr_code' => $qrcode,
));

if (!qq_is_ret_ok($result)) {
    qq_out(array(
        'code' => -1,
        'msg' => ($action === 'add' ? '新增失败' : '删除失败')
            . (qq_retmsg($result) ? '：' . qq_retmsg($result) : '')
            . ' (retcode=' . var_export(qq_retcode($result), true) . ')',
        'upstream' => $result,
    ));
}
qq_out(array('code' => 0, 'msg' => $action === 'add' ? '新增成功' : '删除成功'));
