<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$playlist_file = __DIR__ . '/playlist.m3u';
$notif_file = __DIR__ . '/notification.json';

// Lấy thông báo
$notif_text = "Chào mừng quý khách đến với TDShop TV!";
if (file_exists($notif_file)) {
    $notif_data = json_decode(file_get_contents($notif_file), true);
    if (!empty($notif_data['text'])) {
        $notif_text = $notif_data['text'];
    }
}

$devices_file = __DIR__ . '/devices.json';

// Giả lập Firebase RTDB endpoint cho App TDS_TV & Đồng bộ devices.json
if (isset($_GET['mode']) && $_GET['mode'] === 'rtdb') {
    header('Content-Type: application/json; charset=utf-8');
    
    $devices_data = [];
    if (file_exists($devices_file)) {
        $devices_data = json_decode(file_get_contents($devices_file), true) ?: [];
    }

    $response_devices = [
        "default" => [
            "active" => true,
            "status" => "active",
            "name" => "TDShop Smart TV",
            "expire_date" => "2099-12-31"
        ]
    ];

    foreach ($devices_data as $code => $dev) {
        $is_act = ($dev['status'] ?? 'active') === 'active';
        $exp_ts = !empty($dev['expire_at']) ? strtotime($dev['expire_at']) : 0;
        if ($exp_ts > 0 && $exp_ts < time()) {
            $is_act = false;
        }
        $response_devices[$code] = [
            "active" => $is_act,
            "status" => $dev['status'] ?? 'active',
            "name" => $dev['customer_name'] ?? ('Box ' . $code),
            "expire_date" => $dev['expire_at'] ?? '2099-12-31'
        ];
    }

    // Nếu app gửi ID thiết bị cụ thể lên
    if (isset($_GET['device_id']) && !empty($_GET['device_id'])) {
        $dev_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['device_id']);
        if (!isset($devices_data[$dev_id])) {
            // Tự động thêm box mới vào danh sách chờ duyệt / kích hoạt
            $devices_data[$dev_id] = [
                'code' => $dev_id,
                'model' => 'Android TV Box',
                'customer_name' => 'Box Khách Mới (' . $dev_id . ')',
                'phone' => '',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'status' => 'active',
                'registered_at' => date('Y-m-d H:i:s'),
                'expire_at' => date('Y-m-d H:i:s', strtotime('+1 year')),
                'last_seen' => date('Y-m-d H:i:s'),
                'note' => 'Tự động kết nối lần đầu'
            ];
            file_put_contents($devices_file, json_encode($devices_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            // Cập nhật last_seen và ip
            $devices_data[$dev_id]['last_seen'] = date('Y-m-d H:i:s');
            $devices_data[$dev_id]['ip'] = $_SERVER['REMOTE_ADDR'] ?? $devices_data[$dev_id]['ip'];
            file_put_contents($devices_file, json_encode($devices_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $response_devices[$dev_id] = [
            "active" => true,
            "status" => "active",
            "name" => $devices_data[$dev_id]['customer_name'] ?? ("TV-" . $dev_id),
            "expire_date" => $devices_data[$dev_id]['expire_at'] ?? "2099-12-31"
        ];
    }
    
    $response = [
        "config" => [
            "notification" => $notif_text,
            "password" => "1234",
            "theme" => "dark",
            "theme_presets" => [
                "primary" => "#38BDF8",
                "background" => "#0A0F1D"
            ]
        ],
        "devices" => $response_devices,
        "update" => [
            "version" => "1.0.0",
            "note" => "Phiên bản ổn định của TDShop TV",
            "url" => ""
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Nếu gọi file M3U trực tiếp
if (isset($_GET['format']) && $_GET['format'] === 'm3u' || basename($_SERVER['PHP_SELF']) === 'playlist.m3u') {
    header('Content-Type: audio/x-mpegurl; charset=utf-8');
    if (file_exists($playlist_file)) {
        readfile($playlist_file);
    } else {
        echo "#EXTM3U\n";
    }
    exit;
}

// Mặc định trả về JSON tổng hợp
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    "status" => "success",
    "service" => "TDShop IPTV API",
    "server" => "dvtshophcm.com",
    "notification" => $notif_text,
    "m3u_url" => "https://dvtshophcm.com/iptv/tdshop/playlist.m3u",
    "time" => date("Y-m-d H:i:s")
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
