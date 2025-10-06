<?php
// 1. 基础配置：允许跨域、定义响应格式、支持的请求方法
header("Access-Control-Allow-Origin: *"); // 前端页面调用需开启跨域
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST"); // 同时支持GET和POST

// 2. 核心配置项（可根据需求修改）
$CONFIG = [
    // 管理员标识（仅允许配置内的标识请求，防止非法调用）
    "admins" => [
        "442" => "某人",   // 对应请求参数admin的值
        "123" => "测试员"  // 可扩展多管理员
    ],
    // 下载源映射（与前端下载源对应，key需和请求参数choose一致）
    "sources" => [
        "al" => [
            "name" => "阿里云",
            "base_url" => "https://mirrors.aliyun.com/minecraft/",
            "manifest_url" => "https://mirrors.aliyun.com/minecraft/version_manifest.json"
        ],
        "hw" => [
            "name" => "华为云",
            "base_url" => "https://mirrors.huaweicloud.com/minecraft/",
            "manifest_url" => "https://mirrors.huaweicloud.com/minecraft/version_manifest.json"
        ],
        "mojiang" => [
            "name" => "官方源",
            "base_url" => "https://launcher.mojang.com/",
            "manifest_url" => "https://launchermeta.mojang.com/mc/game/version_manifest.json"
        ]
    ],
    // 版本类型中文映射（方便前端展示）
    "version_types" => [
        "release" => "正式版",
        "snapshot" => "快照版",
        "old_alpha" => "旧阿尔法版",
        "old_beta" => "旧测试版"
    ],
    // 网络请求超时时间（秒）
    "timeout" => 10,
    // 允许的请求方式
    "allow_methods" => ["GET", "POST"]
];

// 3. 接收请求参数（优先POST，兼容GET，适配输入网址请求）
$requestMethod = $_SERVER["REQUEST_METHOD"];
// 检查请求方式是否允许
if (!in_array($requestMethod, $CONFIG["allow_methods"])) {
    echo json_encode([
        "code" => 405,
        "msg" => "不允许的请求方式，仅支持GET/POST",
        "data" => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 接收参数：POST取JSON或表单数据，GET取URL参数
if ($requestMethod === "POST") {
    $request = json_decode(file_get_contents("php://input"), true) ?: $_POST;
} else {
    $request = $_GET; // GET请求直接读取URL参数
}

// 4. 参数验证（必填项、合法性校验）
$paramCheck = validateParams($request);
if ($paramCheck["code"] !== 200) {
    echo json_encode($paramCheck, JSON_UNESCAPED_UNICODE);
    exit;
}
$validParams = $paramCheck["data"]; // 验证通过的参数

// 5. 核心业务逻辑：获取版本信息并生成下载链接
try {
    // 5.1 读取当前选择的下载源配置
    $currentSource = $CONFIG["sources"][$validParams["choose"]];
    
    // 5.2 从下载源获取版本清单（包含所有版本的基础信息）
    $versionManifest = getRemoteData($currentSource["manifest_url"]);
    if (empty($versionManifest["versions"])) {
        throw new Exception("版本清单解析失败，未找到版本列表");
    }

    // 5.3 精确匹配目标版本（按用户输入的mcbb版本号）
    $targetVersion = null;
    foreach ($versionManifest["versions"] as $version) {
        if ($version["id"] === $validParams["mcbb"]) {
            $targetVersion = $version;
            break;
        }
    }
    if (!$targetVersion) {
        throw new Exception("未找到【{$validParams["mcbb"]}】版本，请检查版本号是否正确（区分大小写）");
    }

    // 5.4 获取目标版本的详细信息（包含下载链接、SHA1、文件大小等）
    // 替换版本详情地址为当前下载源（加速访问）
    $versionDetailUrl = str_replace(
        $CONFIG["sources"]["mojiang"]["base_url"],
        $currentSource["base_url"],
        $targetVersion["url"]
    );
    $versionDetail = getRemoteData($versionDetailUrl);
    if (empty($versionDetail["downloads"]["client"])) {
        throw new Exception("版本详情解析失败，缺失客户端下载信息");
    }

    // 5.5 生成最终下载链接（替换为当前选择的下载源）
    $downloadUrl = str_replace(
        $CONFIG["sources"]["mojiang"]["base_url"],
        $currentSource["base_url"],
        $versionDetail["downloads"]["client"]["url"]
    );

    // 5.6 组装响应数据（包含所有关键信息，方便前端展示）
    $responseData = [
        "admin_info" => [
            "admin_id" => $validParams["admin"],
            "admin_name" => $CONFIG["admins"][$validParams["admin"]]
        ],
        "source_info" => [
            "source_key" => $validParams["choose"],
            "source_name" => $currentSource["name"],
            "source_base" => $currentSource["base_url"]
        ],
        "version_info" => [
            "version_id" => $targetVersion["id"],
            "version_type" => $CONFIG["version_types"][$targetVersion["type"]] ?? $targetVersion["type"],
            "release_time" => date("Y-m-d H:i:s", strtotime($targetVersion["releaseTime"])),
            "client_sha1" => $versionDetail["downloads"]["client"]["sha1"],
            "client_size" => round($versionDetail["downloads"]["client"]["size"] / 1024 / 1024, 2) . "MB"
        ],
        "download_info" => [
            "download_url" => $downloadUrl,
            "file_name" => "minecraft-{$targetVersion["id"]}-client.jar",
            "verify_guide" => [
                "windows" => "PowerShell中执行：Get-FileHash -Algorithm SHA1 文件名.jar",
                "mac_linux" => "终端中执行：sha1sum 文件名.jar",
                "verify_tip" => "对比命令输出的SHA1与version_info中的client_sha1是否一致，一致则文件完整"
            ]
        ]
    ];

    // 成功响应
    $response = [
        "code" => 200,
        "msg" => "获取下载信息成功",
        "data" => $responseData,
        "request_method" => $requestMethod // 标识当前请求方式
    ];

} catch (Exception $e) {
    // 异常响应（捕获所有错误并返回清晰提示）
    $response = [
        "code" => 500,
        "msg" => $e->getMessage(),
        "data" => [],
        "request_method" => $requestMethod
    ];
}

// 6. 输出响应结果（JSON格式，中文不转义）
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;


/**
 * 参数验证函数：检查必填项、合法性
 * @param array $request 接收的请求参数
 * @return array 验证结果（code=200表示通过）
 */
function validateParams($request) {
    global $CONFIG;
    // 必填参数列表
    $requiredParams = ["admin", "choose", "mcbb"];

    // 1. 检查必填参数是否缺失
    foreach ($requiredParams as $param) {
        if (empty($request[$param])) {
            return [
                "code" => 400,
                "msg" => "参数缺失：【{$param}】是必填项",
                "data" => []
            ];
        }
    }

    // 2. 验证管理员标识是否合法（必须在CONFIG["admins"]中配置）
    if (!isset($CONFIG["admins"][$request["admin"]])) {
        return [
            "code" => 403,
            "msg" => "非法请求：管理员标识【{$request["admin"]}】未授权",
            "data" => []
        ];
    }

    // 3. 验证下载源是否支持（必须在CONFIG["sources"]中配置）
    if (!isset($CONFIG["sources"][$request["choose"]])) {
        $supportSources = implode("、", array_keys($CONFIG["sources"]));
        return [
            "code" => 400,
            "msg" => "不支持的下载源：请选择【{$supportSources}】（分别对应阿里云、华为云、官方源）",
            "data" => []
        ];
    }

    // 4. 验证版本号格式（避免非法字符，支持数字、字母、点、下划线、短横线）
    if (!preg_match("/^[\d\.a-zA-Z_-]+$/", $request["mcbb"])) {
        return [
            "code" => 400,
            "msg" => "版本号格式非法：仅允许包含数字、字母、点（.）、下划线（_）、短横线（-）",
            "data" => []
        ];
    }

    // 验证通过：返回合法参数
    return [
        "code" => 200,
        "msg" => "参数验证通过",
        "data" => [
            "admin" => $request["admin"],
            "choose" => $request["choose"],
            "mcbb" => $request["mcbb"]
        ]
    ];
}


/**
 * 远程数据请求函数：获取版本清单、版本详情
 * @param string $url 远程请求地址
 * @return array 解析后的JSON数据
 * @throws Exception 请求失败时抛出异常
 */
function getRemoteData($url) {
    global $CONFIG;
    // 初始化curl
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true, // 结果返回为字符串
        CURLOPT_TIMEOUT => $CONFIG["timeout"], // 超时时间
        CURLOPT_FOLLOWLOCATION => true, // 跟随重定向
        CURLOPT_SSL_VERIFYPEER => false, // 跳过SSL证书验证（部分镜像源可能证书不兼容）
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    // 执行请求并获取响应
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // HTTP状态码
    curl_close($ch);

    // 处理请求错误
    if ($curlError) {
        throw new Exception("网络请求失败：{$curlError}（URL：{$url}）");
    }
    // 处理HTTP错误（非200状态码）
    if ($httpCode !== 200) {
        throw new Exception("远程资源访问失败：HTTP状态码【{$httpCode}】（URL：{$url}）");
    }
    // 解析JSON数据
    $jsonData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("数据解析失败：返回内容非合法JSON格式（URL：{$url}）");
    }

    return $jsonData;
}
?>