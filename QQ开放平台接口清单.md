# QQ开放平台接口清单（机器人相关 · 新旧版全量）

> 抓取时间：2026-09-12
> 抓取方式：使用开发者 Cookie（quid/qticket/quin）下载新版控制台前端 JS，静态提取 + 实测验证
> 来源页面：
> - 新版机器人管理端：`https://q.qq.com/qqbot/`（标题「QQ机器人管理端」，独立 webpack SPA）
> - 新版统一开发者控制台：`https://q.qq.com/`（Vue3 SPA，构建版本 363c0751）

---

## 0. 通用鉴权与约定

| 项目 | 说明 |
|---|---|
| 鉴权 Cookie | `quid`（开发者ID）、`qticket`（开发者票据）、`quin`（QQ号）三元组；`bot.q.qq.com/cgi-bin/*` 用这套即可 |
| q.qq.com 域接口 | `q.qq.com/pb/*`、`/api/v1/*` 需要更完整的浏览器登录态（skey/p_skey 会话绑定），裸三元组会返回「登录态校验失败」 |
| 请求格式 | `Content-Type: application/json`，axios `withCredentials: true` |
| 响应格式（cgi-bin） | `{"retcode": 0, "msg": "...", "data": {...}}` |
| 响应格式（q.qq.com/pb） | `{"code": "...", "message": "...", ...}` |
| 响应格式（bopen） | `{"common": {"code": 0, "msg": ""}, "data": {...}}` |
| 关键错误码 | `0` 成功；`51` 参数校验失败；`10004` no app login permission（对该机器人无权限/appid错误）；`-101185002` Unauthorized；`-101185006` 登录态校验失败 |
| 敏感操作 | 改密钥/改白名单/删模板等需带 `qr_code` 票据，票据通过下文「扫码体系」获取（type=40/51，必须用当前登录态QQ扫码，扫码后立即提交） |
| 机器人/小程序类型 | `appType`: `1`=机器人，`2`=小程序/小游戏；消息场景 `scene`: `1`=频道channel，`2`=群group，`3`=单聊c2c |

---

## 1. 旧版接口（PHP 抓包，实测 2026-09-12 仍有效）

Base：`https://bot.q.qq.com/cgi-bin`，鉴权 `Cookie: quin={uin}; quid={developerId}; qticket={ticket}`

| 方法 | 接口 | 参数 | 说明 |
|---|---|---|---|
| GET | `/cgi-bin/info/query?bot_appid={appid}` | query | 机器人基础信息 |
| POST | `/cgi-bin/msg_tpl/list` | `{bot_appid, start, limit}` | 消息模板列表（分页） |
| POST | `/cgi-bin/msg_tpl/delete` | `{bot_appid, tpl_id: [id...], qrcode}` | 删除消息模板（需扫码票据） |
| GET | `/cgi-bin/dev_info/white_ip_config?bot_appid={appid}` | query | 查询IP白名单（`data.ip_white_infos.prod.ip_list`） |
| POST | `/cgi-bin/dev_info/update_white_ip_config` | `{bot_appid, ip_white_infos: {prod: {ip_list: [...], use: true}}, qr_code}` | 更新IP白名单（二维码必须用 **type=51** 创建；retcode=24011 见 1.1 节 type 映射） |
| POST | `/cgi-bin/event_subscirption/list_event` | `{bot_appid}` | 事件订阅列表（注意接口拼写就是 subscirption） |
| GET | `/cgi-bin/ark_url/query?bot_appid={appid}` | query | Ark 模板消息 URL 报备列表 |
| POST | `https://q.qq.com/pb/GetMemberList` | `{appType, appid}` | 成员列表（appType=1机器人） |

### 1.1 旧版扫码体系（q.qq.com 域）

| 方法 | 接口 | 参数 | 说明 |
|---|---|---|---|
| POST | `https://q.qq.com/qrcode/create` | `{type: 777}` | 登录二维码（返回 data.QrCode，拼接 `/login/applist?client=qq&code={QrCode}&ticket=null` 扫码登录） |
| POST | `https://q.qq.com/qrcode/create` | `{type: 40, miniAppId: appid}` / `{type: 51, miniAppId: appid}` | 敏感操作校验二维码（白名单/模板删除等），拼接 `/qrcode/check?client=qq&code={QrCode}&ticket={ticket}` |
| POST | `https://q.qq.com/qrcode/get` | `{qrcode}` | 轮询扫码状态（code==0 即已授权，票据可用） |

> **敏感操作二维码 type 精确映射（2026-09-12 抓包 SPA 模块 9390/QRTYPE，关键！）**
> 每种操作必须用对应 type 创建二维码，否则 `/qrcode/get` 即使返回 code=0，业务 CGI 仍返回 **retcode=24011**（票据场景与操作不匹配）：
>
> | type | 场景 | miniAppId | 对应业务接口 |
> |---|---|---|---|
> | 40 | Ark 消息模板删除 | bot appid | `msg_tpl/delete`（字段 qrcode） |
> | 40 | 可用范围模式切换 | **空串 ""** | `scope/mode/switch`（字段 qrcode） |
> | 48 | 事件回调地址修改 (QRTYPE.url) | bot appid | `callback/modify`（字段 qr_code） |
> | 49 | 域名报备 (QRTYPE.domain) | bot appid | `ark_url/modify`（qr_code） |
> | 50 | 开发配置：Webhook设置/事件订阅/重置Token (editDevInfo) | bot appid | `callback/set_webhook`、`event_subscirption/modify`、`dev_info/reset_token`（qr_code；reset_token body 另需同时带 appid 与 bot_appid） |
> | 51 | IP 白名单 (QRTYPE.ip/editSet) | bot appid | `dev_info/update_white_ip_config`（qr_code） |
> | 53 | 重置 AppSecret (resetSecret) | bot appid | `dev_info/reset_secret`（body `{bot_appid, qr_code}`） |
>
> 完整枚举（旧控制台遗留）：login:1 setManager:2 modifyManager:3 verifyManager:4 editMember:5 appSecret:6 modifyAppname:7 modifyCategory:8 modifyEmail:9 modifyPassword:10 versionOffline:11 versionRollback:12 upRange:13 cancelUpgrade:14 submitRelease:15 experience:16 domains:17 openVirtualPay:18 modifyCallbackUrl:19 modifyProp:20 modifyVersionUrl:23 appPrivateToken:30 unbindOfficialAccount:31 robotAuth:32 acceptBindOfficialAccount:33 rejectBindOfficialAccount:34 modifyAdJoin:38 url:48 domain:49 editDevInfo:50 ip/editSet/auditRobotInfo:51 resetSecret:53 bindWithWx:54。
> 另：新版主体认证类敏感操作走 `/bopen/v2/gen_qr_auth` + `/bopen/v2/get_qr_status_auth`（scene枚举 9=BotModSecret/10=BotServiceRange/11=BotModProfile，action=0 Verify），与机器人管理后台上述旧体系并存。

---

## 2. 新版机器人管理端接口（q.qq.com/qqbot/ SPA，2026 现行版）

Base：`https://bot.q.qq.com/cgi-bin`（新版与旧版同域同基址，鉴权方式相同：`quid`/`qticket`/`quin` Cookie，withCredentials）
调用封装：GET 走 axios `method:"get", params`；POST 走 axios `method:"post", data`；标注 FormData 的为 multipart 上传。

### 2.1 机器人信息与生命周期

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| GET | `/cgi-bin/info/query` | getRobotInfo | 机器人信息（data 含机器人资料） |
| POST | `/cgi-bin/info/modify` | modifyBotInfo | 修改机器人资料 |
| POST | `/cgi-bin/info/robot/unbind` | unbindRobot | 解绑机器人 |
| POST | `/cgi-bin/create` | createBot | 创建机器人 |
| POST | `/cgi-bin/profile/audit` | profileAudit | 资料提交审核 |

### 2.2 开发凭据（AppSecret / Token）

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| POST | `/cgi-bin/dev_info/reset_secret` | ResetSecret | 重置 AppSecret（敏感，需扫码） |
| POST | `/cgi-bin/dev_info/reset_token` | resetToken | 重置 Token |
| GET | `/cgi-bin/dev_info/access_token_ttl` | QueryAccessTokenTTL | 查询 AccessToken 有效期 |
| GET | `/cgi-bin/dev_info/query` | getFeedbackID | 查询开发者/反馈配置 |
| POST | `/cgi-bin/dev_info/modify` | modifyFeedbackID | 修改开发者配置（反馈ID等） |

### 2.3 IP 白名单

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| GET | `/cgi-bin/dev_info/white_ip_config` | getIpAccessList | 查询IP白名单 |
| POST | `/cgi-bin/dev_info/update_white_ip_config` | modifyIpAccessList | 更新IP白名单（需扫码票据） |

### 2.4 回调 / Webhook

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| GET | `/cgi-bin/callback/query` | getList | 查询回调配置 |
| POST | `/cgi-bin/callback/modify` | modifyList | 修改回调配置 |
| POST | `/cgi-bin/callback/get_webhook` | getWebhook | 获取 webhook 地址 |
| POST | `/cgi-bin/callback/set_webhook` | setWebhook | 设置 webhook 地址 |
| POST | `/cgi-bin/callback/check_webhook` | checkWebhook | 校验 webhook 可用性 |
| POST | `/cgi-bin/callback/webhook_whitelist` | isInWebhookWhitelist | 是否在 webhook 白名单 |
| GET | `/cgi-bin/callback/check_icp` | checkUrl | 校验回调域名 ICP（data: `{url}`） |

### 2.5 事件订阅

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| POST | `/cgi-bin/event_subscirption/list_event` | getListEvent | 事件订阅列表（拼写沿用官方 subscirption） |
| POST | `/cgi-bin/event_subscirption/modify` | modifyListEvent | 修改事件订阅 |

### 2.6 消息模板（Ark / Markdown 模板）

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| POST | `/cgi-bin/msg_tpl/list` | list | 模板列表（`{bot_appid, start, limit}`） |
| GET | `/cgi-bin/msg_tpl/get` | fetchMsgTpl | 模板详情 |
| POST | `/cgi-bin/msg_tpl/create` | create | 创建模板 |
| POST | `/cgi-bin/msg_tpl/modify` | modify | 修改模板 |
| POST | `/cgi-bin/msg_tpl/delete` | delete | 删除模板（需扫码） |
| POST | `/cgi-bin/msg_tpl/preview` | preview | 模板预览 |
| POST | `/cgi-bin/msg_tpl/switch` | switch | 启用/停用模板 |
| POST | `/cgi-bin/msg_tpl/audit` | audit | 提交模板审核 |
| GET | `/cgi-bin/msg_tpl/statistics` | statistics | 模板统计 |

### 2.7 Ark 消息 URL 报备（消息URL配置）

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| GET | `/cgi-bin/ark_url/query` | getDomainList | 报备域名列表（data: `{bot_appid}`） |
| GET | `/cgi-bin/ark_url/check` | checkDomainUrl | 校验单个 URL 可否报备（data: `{bot_appid, url}`） |
| POST | `/cgi-bin/ark_url/modify` | modifyDomainList | 增删报备域名 |

### 2.8 可用范围（场景/白名单群）

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| POST | `/cgi-bin/scope/mode/query` | modeQuery | 查询可用范围模式（data: `{bot_appid}`） |
| POST | `/cgi-bin/scope/mode/switch` | modeSwitch | 切换模式（全量/白名单） |
| GET | `/cgi-bin/scope/query` | scopeQuery | 查询范围列表（代码中 url 无前导斜杠） |
| POST | `/cgi-bin/scope/add` | scopeAdd | 添加可用对象 |
| POST | `/cgi-bin/scope/del` | scopeDel | 移除可用对象 |
| GET | `/cgi-bin/scene/query` | QueryScenesStateInfos | 场景状态查询（1=频道 2=群 3=单聊） |
| POST | `/cgi-bin/feature/scene/query` | queryAllowedScenes | 查询允许开通的场景 |

### 2.9 沙箱测试

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| POST | `/cgi-bin/sandbox/query` | — | 查询沙箱配置（data: `{bot_appid}`） |
| POST | `/cgi-bin/sandbox/add` | — | 添加沙箱对象（data: `{bot_appid, robot_scene, ids}`） |
| POST | `/cgi-bin/sandbox/del` | — | 删除沙箱对象（data: `{bot_appid, robot_scene, ids}`） |
| POST | `/cgi-bin/sandbox/update` | — | 更新沙箱对象（data: `{bot_appid, robot_scene, id}`） |
| POST | `/cgi-bin/sandbox/get_creator_uin` | — | 查询沙箱创建者QQ（data: `{bot_appid}`） |

### 2.10 功能配置（菜单/指令等）

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| GET | `/cgi-bin/feature/query` | getList | 查询功能配置 |
| POST | `/cgi-bin/feature/modify` | setCommands / setFeatureConfig | 修改功能配置（指令/功能开关） |
| GET | `/cgi-bin/feature/count` | getCountOfFeature | 功能数量统计 |
| GET | `/cgi-bin/feature/limit` | getFeatureUpperLimit | 功能上限 |
| POST | `/cgi-bin/feature/queryDeveloper` | isInWhiteList | 开发者是否在灰度白名单 |

### 2.11 资源上传

| 方法 | 接口 | 说明 |
|---|---|---|
| POST | `/cgi-bin/resource/upload` | FormData 通用上传（JSON数组字段会 stringify 后 append） |
| POST | `/cgi-bin/resource/upload_avatar` | FormData 头像上传，成功后资源落在 `https://bot-resource-1251316161.cos.ap-guangzhou.myqcloud.com/avatar/` |

### 2.12 词库（语料）

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| GET | `/cgi-bin/corpus/list` | getCorpusList | 语料列表 |
| GET | `/cgi-bin/corpus/search` | searchCorpus | 搜索语料 |
| GET | `/cgi-bin/corpus/status` | getCorpusStatus | 语料开关状态 |
| POST | `/cgi-bin/corpus/add` | addCorpus | 新增语料 |
| POST | `/cgi-bin/corpus/modify` | modifyCorpus | 修改语料 |
| POST | `/cgi-bin/corpus/delete` | deleteCorpus | 删除语料 |

### 2.13 外观自定义

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| POST | `/cgi-bin/msgbackground/modify` | modifyMsgBackground | 修改消息卡片背景 |
| POST | `/cgi-bin/welcomeinfo/modify` | modifyWelcomeInfo | 修改欢迎语 |
| POST | `/cgi-bin/statement/modify` | modifyStatement | 修改功能声明 |

### 2.14 发布审核

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| POST | `/cgi-bin/audit/submit` | auditBot | 提交发布审核 |
| POST | `/cgi-bin/audit/recall` | recallAudit | 撤回审核 |
| POST | `/cgi-bin/audit/publish` | publishBot | 发布 |

### 2.15 授权管理

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| POST | `/cgi-bin/auth_page/authz_records` | getAuthRecords | 授权记录列表 |
| POST | `/cgi-bin/auth_page/cancel_authorize` | cancelAuthorize | 取消授权 |

### 2.16 群 / 频道列表

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| GET | `/cgi-bin/dev_info/group/list` | getGroupList | 机器人群列表 |
| GET | `/cgi-bin/dev_info/guild/list` | getGuildList | 机器人频道列表 |

### 2.17 数据报表

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| GET | `/cgi-bin/datareport/read` | getData | 数据看板读取 |

---

## 3. 成员/管理员/私信管理（q.qq.com 域 /pb/*，均为 POST）

> 机器人管理端 SPA 直接以完整 URL `https://q.qq.com/pb/*` 调用（dv 封装 = axios POST）。鉴权用 q.qq.com 完整登录态（浏览器 Cookie），纯 `quin/qticket/quid` 三元组在无浏览器会话时会返回 `-101185006 登录态校验失败`。
> 私信接口 `appType`: `1`=机器人，`2`=小程序。

| 方法 | 接口 | 前端函数 | 说明 |
|---|---|---|---|
| POST | `https://q.qq.com/pb/GetMemberList` | getMemberList | 成员列表 |
| POST | `https://q.qq.com/pb/AddMember` | addMember | 添加成员 |
| POST | `https://q.qq.com/pb/DelMember` | delMember | 删除成员 |
| POST | `https://q.qq.com/pb/ModifyMember` | modifyMember | 修改成员角色 |
| POST | `https://q.qq.com/pb/CheckAdminStatus` | — | 查询管理员状态 |
| POST | `https://q.qq.com/pb/CheckAdministratorInfo` | — | 校验管理员信息 |
| POST | `https://q.qq.com/pb/GetUinInfo` | — | 查询 UIN 信息 |
| POST | `https://q.qq.com/pb/GetPhoneCode` | — | 获取短信验证码 |
| POST | `https://q.qq.com/pb/CheckPhoneCode` | — | 校验短信验证码 |
| POST | `https://q.qq.com/pb/ModifyAdministrator` | — | 修改管理员 |
| POST | `https://q.qq.com/pb/AppFetchPrivateMsg` | getMessageList | 拉取私信/消息列表（`{...e, appType}`） |
| POST | `https://q.qq.com/pb/AppOperatePrivateMsg` | — | 操作私信（通过/拒绝等） |
| POST | `https://q.qq.com/pb/GetDeveloper` | — | 开发者信息 |
| POST | `https://q.qq.com/pb/GetAppSecret` | — | 获取 AppSecret（配合扫码 type） |
| POST | `https://q.qq.com/pb/CheckRegisterLimit` | — | 注册限额校验 |
| POST | `https://q.qq.com/pb/GetTmpKey` | — | 获取临时密钥 |
| POST | `https://q.qq.com/pb/QueryOperateMsgList` | — | 操作通知列表 |
| GET/POST | `https://q.qq.com/cgi/mdmToken?bot=1&...` | — | 数据模块（MDM）令牌 |

---

## 4. 新版统一开发者控制台接口（q.qq.com 首页 SPA）

前缀（SPA 内配置）：
- `API_PREFIX = /bopen`（新版业务接口）
- `AUTH_QQ_LIST_API_PREFIX = /bopen/v2`
- `LEGACY_API_PREFIX = /api/v1`（legacy）
- `LOGIN_API_PREFIX = /api/v3`（登录）

### 4.1 认证QQ账号管理 /bopen/v2/auth_qq/*（均 POST，实测鉴权有效）

| 接口 | 说明 |
|---|---|
| `/bopen/v2/auth_qq/gets` | 认证账号列表（需 Page>=1） |
| `/bopen/v2/auth_qq/able_login` | 是否可登录 |
| `/bopen/v2/auth_qq/disable_login` | 禁用登录 |
| `/bopen/v2/auth_qq/get_regist_info` | 注册信息 |
| `/bopen/v2/auth_qq/get_human_face_status` | 人脸核验状态 |
| `/bopen/v2/auth_qq/check_create_status` | 创建状态校验 |
| `/bopen/v2/auth_qq/create_auth_account` | 创建认证账号 |
| `/bopen/v2/auth_qq/create_mission` | 创建任务 |
| `/bopen/v2/auth_qq/modify_profile` | 修改认证账号资料 |
| `/bopen/v2/auth_qq/get_bind_groups` | 获取绑定群 |
| `/bopen/v2/auth_qq/query_bind_groups` | 查询绑定群 |
| `/bopen/v2/auth_qq/submit_bind` | 提交绑定 |
| `/bopen/v2/auth_qq/withdraw_bind` | 撤回绑定 |
| `/bopen/v2/auth_qq/revoke_bind` | 解除绑定 |

### 4.2 二维码 / 开发者注册 / 认证 /bopen/v2/*

| 接口 | 方法 | 说明 |
|---|---|---|
| `/bopen/v2/gen_qr` | POST | 生成二维码 |
| `/bopen/v2/gen_qr_auth` | POST | 生成认证二维码（敏感操作 type=9/10/11 场景） |
| `/bopen/v2/get_qr_status` | POST | 轮询二维码状态 |
| `/bopen/v2/get_qr_status_auth` | POST | 轮询认证二维码状态 |
| `/bopen/v2/register_developer` | POST | 注册开发者 |
| `/bopen/v2/register_by_email` | POST | 邮箱注册 |
| `/bopen/v2/verify_identity` | POST | 实名核验 |
| `/bopen/v2/authorize_subject` | POST | 主体授权 |
| `/bopen/v2/activate_email` | POST | 激活邮箱 |
| `/bopen/v2/get_phone_code` | POST | 获取手机验证码 |
| `/bopen/v2/get_ocr_code` | POST | OCR 识别 |
| `/bopen/v2/getbank` | POST | 银行卡信息 |
| `/bopen/v2/modify_administrator` | POST | 修改管理员 |
| `/bopen/v2/get_audit_developer_info` | POST | 审核中开发者信息 |
| `/bopen/v2/check_pre_account` | POST | 预账号校验 |
| `/bopen/v2/get_pre_account` | POST | 预账号信息 |
| `/bopen/v2/check_subject_status` | POST | 主体状态校验 |
| `/bopen/v2/upload_file1` | POST | 文件上传 |

### 4.3 登录 /api/v3 与 legacy /api/v1

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/v3/cgi/login` | POST | 登录（skey 换开发者票据） |
| `/api/v3/login/qrcode/authorize` | GET | 扫码授权 |
| `/api/v3/login/qrcode/query` | GET | 扫码状态查询 |
| `/api/v3/login/qrcode/update` | GET | 刷新二维码 |
| `/api/v3/login/developer_list` | GET | 开发者列表 |
| `/api/v1/pb/GetDeveloper` | GET/POST | 开发者信息（需完整登录态） |
| `/api/v1/pb/GetResetPwdEmail` | POST | 重置密码邮件 |
| `/api/v1/pb/ResetPwdByTicket` | POST | 凭票据重置密码 |
| `/api/v1/cgi/getModifyPwdEmail` | POST | 修改密码邮件 |
| `/api/v1/homepagepb/GetAppListForLogin` | POST | 登录后应用列表（含机器人 appType=1） |
| `/api/v1/homepagepb/GetWxAppListBindNoQQAppLackWxAuth` | POST | 微信侧小程序绑定列表 |
| `/api/v1/pb/GetWxAppAuthUrl` | POST | 微信授权 URL |
| `/api/v1/pb/GetWxAppAuthResult` | POST | 微信授权结果 |
| `/api/v1/pb/WxAppBindNoQQApp` | POST | 绑定 |
| `/api/v1/pb/WxAppBindNoQQAppLackWxAuth` | POST | 缺微信授权时绑定 |
| `/api/v1/pb/WxAppUnBind` | POST | 解绑 |
| `/api/v1/cgi-bin/v2/info/hit` | GET | 埋点/命中 |

---

## 5. 实测记录（2026-09-12，使用当次有效 CK）

| 测试 | 请求 | 结果 | 结论 |
|---|---|---|---|
| 旧版鉴权 | `GET bot.q.qq.com/cgi-bin/info/query`（无appid） | `retcode=51` 参数校验错误 | 三元组鉴权**通过**（非登录错误） |
| 新版消息模板 | `POST bot.q.qq.com/cgi-bin/msg_tpl/list`（占位appid=100001） | `retcode=10004 no app login permission` | 鉴权**通过**，appid 不存在/无权限 |
| 新版白名单 | `GET bot.q.qq.com/cgi-bin/dev_info/white_ip_config?bot_appid=100001` | `retcode=10004` | 鉴权**通过** |
| bopen 新接口 | `GET q.qq.com/bopen/v2/auth_qq/gets` | `code=51 Page>=1` 参数校验 | 鉴权**通过** |
| q.qq.com/pb | `POST q.qq.com/pb/GetMemberList`（裸三元组） | `code=-101185006 登录态校验失败` | pb/* 需完整浏览器登录态 |
| /api/v1/pb | `GET q.qq.com/api/v1/pb/GetDeveloper` | `code=-101185006` | 同上 |
| 旧版控制台页面 | `GET bot.q.qq.com/qqbot/` | `302 → https://q.qq.com` | 旧独立机器人控制台前端已下线，跳转新版 |
| 新版机器人端页面 | `GET q.qq.com/qqbot/` | 200，SPA 入口 `main.7b0417be27f6d10e0ac2.js` | 新版机器人管理端入口 |

---

## 6. 框架集成状态（2026-09-12 已完成）

已集成到框架，改动文件：
- `src/services/openPlatformService.js`：新增 `CGI_ACTIONS` 白名单（52 个 action）+ `callCgi()` 通用代理 + `listCgiActions()` + `resetCredentials()`（重置密钥，内嵌扫码校验）；旧版函数全部保留。
- `src/api/index.js`：新增 `POST /api/bots/:id/open-platform/cgi`（通用代理，bot_appid 服务端强制注入）、`GET /api/open-platform/cgi-actions`（action 清单）、`POST /api/bots/:id/open-platform/reset-credentials`。
- `public/js/api.js`：新增 `opCgi / opCgiActions / opResetCredentials`。
- `public/open-platform.html`：新增 8 个功能页签（事件订阅 / 回调Webhook / 沙箱管理 / 可用范围 / 群·频道 / 密钥Token / 官方语料 / 高级接口）。「高级接口」面板可直调全部 52 个 action，参数示例自动填充。

集成原则：旧版接口（已实测有效）原样保留；新版接口与旧版同基址同鉴权，通过 action 白名单开放；上游 retcode 原样透传由前端展示；`bot_appid` 一律服务端注入防越权；重置 AppSecret/Token 等敏感操作强制走扫码二次确认（verifyActionQrcode 紧耦合提交）。
