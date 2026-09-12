<?php
/**
 * [新版] 统一开发者控制台 /bopen/v2/* 白名单代理
 * 上游: POST https://q.qq.com/bopen/v2/{ep}  (三元组鉴权实测有效)
 * 响应格式: {common:{code,msg}, data:{...}}
 *
 * 参数:
 *   ep : 白名单端点路径 (如 auth_qq/gets)，完整清单见 common.php qq_bopen_eps()
 *   uin / developerId(或uid) / ticket : 会话三元组
 *   其余业务参数传法同 cgi.php (params 数组展开 / params=JSON / JSON体 / query 平铺)
 *
 * 常用端点:
 *   auth_qq/gets        认证账号列表 (需 Page>=1)      auth_qq/able_login    是否可登录
 *   auth_qq/disable_login 禁用登录                     gen_qr / gen_qr_auth  生成二维码/认证二维码
 *   get_qr_status / get_qr_status_auth 轮询二维码状态   register_developer    注册开发者
 *   verify_identity     实名核验                        check_subject_status  主体状态校验
 *
 * 注意:
 *   - 主体认证类敏感操作二维码走 gen_qr_auth + get_qr_status_auth
 *     (scene: 9=BotModSecret / 10=BotServiceRange / 11=BotModProfile, action=0 Verify)，
 *     与机器人管理后台的 qrcode/create type 体系并存。
 *   - q.qq.com/api/v1/* 与 /pb/* 需完整浏览器登录态，不在本代理范围。
 */
require __DIR__ . '/../common.php';

$session = qq_session();
$ep = trim((string)(isset($_REQUEST['ep']) ? $_REQUEST['ep'] : ''), "/");

$eps = qq_bopen_eps();
if ($ep === '' || !in_array($ep, $eps, true)) {
    qq_fail(400, '不支持的 bopen 端点 ep，可用清单: ' . implode(', ', $eps));
}

$params = qq_extract_params(array('ep', 'uin', 'developerId', 'uid', 'ticket', 'params'));
$params = qq_sanitize($params);
if (!is_array($params)) {
    $params = array();
}

qq_out(qq_request('POST', QQ_Q_BASE . '/bopen/v2/' . $ep, $session,
    $params !== array() ? $params : new stdClass(), 'https://q.qq.com/'));
