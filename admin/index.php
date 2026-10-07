<?php
session_start();

$base_dir = dirname(__DIR__);
$config_file = $base_dir . '/admin_config.json';
$playlist_file = $base_dir . '/playlist.m3u';
$notif_file = $base_dir . '/notification.json';
$devices_file = $base_dir . '/devices.json';

// Cấu hình mặc định
$default_config = [
    'admin_password' => 'tdshop2026',
    'shop_name' => 'TDSHOP TV - TRUNG TÂM QUẢN TRỊ',
    'app_name' => 'TDShop TV'
];

if (!file_exists($config_file)) {
    @file_put_contents($config_file, json_encode($default_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
$raw_cfg = file_exists($config_file) ? @file_get_contents($config_file) : false;
$admin_config = $raw_cfg ? json_decode($raw_cfg, true) : $default_config;
$current_password = $admin_config['admin_password'] ?? 'tdshop2026';
$shop_name = $admin_config['shop_name'] ?? 'TDSHOP TV';

// Khởi tạo file devices.json nếu chưa có
if (!file_exists($devices_file)) {
    $initial_devices = [
        '91286869' => [
            'code' => '91286869',
            'model' => 'Android TV Box (Box 97)',
            'customer_name' => 'Khách Hàng Demo TDShop',
            'phone' => '0908123456',
            'ip' => '192.168.2.97',
            'status' => 'active',
            'registered_at' => date('Y-m-d H:i:s'),
            'expire_at' => date('Y-m-d H:i:s', strtotime('+1 year')),
            'last_seen' => date('Y-m-d H:i:s'),
            'note' => 'Box Demo cửa hàng'
        ]
    ];
    @file_put_contents($devices_file, json_encode($initial_devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Helper: Tải / Lưu Devices
function getDevices($file) {
    if (!file_exists($file)) return [];
    $raw = @file_get_contents($file);
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function saveDevices($file, $devices) {
    @file_put_contents($file, json_encode($devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Helper: Phân tích file M3U thành mảng PHP
function parseM3U($filepath) {
    if (!file_exists($filepath)) return [];
    $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $channels = [];
    $current = null;
    $id = 1;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if (strpos($line, '#EXTINF:') === 0) {
            $name = '';
            $group = 'Mặc định';
            $logo = '';
            $tvg_id = '';

            if (preg_match('/group-title="([^"]*)"/i', $line, $m)) $group = $m[1];
            if (preg_match('/tvg-logo="([^"]*)"/i', $line, $m)) $logo = $m[1];
            if (preg_match('/tvg-id="([^"]*)"/i', $line, $m)) $tvg_id = $m[1];
            $parts = explode(',', $line, 2);
            $name = count($parts) > 1 ? trim($parts[1]) : 'Kênh ' . $id;

            $current = [
                'id' => $id++,
                'name' => $name,
                'group' => $group,
                'logo' => $logo,
                'tvg_id' => $tvg_id,
                'url' => ''
            ];
        } elseif (strpos($line, '#') !== 0 && $current !== null) {
            $current['url'] = $line;
            $channels[] = $current;
            $current = null;
        }
    }
    return $channels;
}

// Helper: Lưu mảng kênh thành file M3U
function saveM3U($filepath, $channels) {
    $content = "#EXTM3U url-tvg=\"https://vnepg.site/epg.xml.gz\" m3uautoload=1\n\n";
    $grouped = [];
    foreach ($channels as $ch) {
        $g = !empty($ch['group']) ? $ch['group'] : 'Khác';
        $grouped[$g][] = $ch;
    }

    foreach ($grouped as $group_name => $items) {
        $content .= "### " . strtoupper($group_name) . " ###\n";
        foreach ($items as $ch) {
            $logo = !empty($ch['logo']) ? ' tvg-logo="' . $ch['logo'] . '"' : '';
            $group = !empty($ch['group']) ? ' group-title="' . $ch['group'] . '"' : '';
            $tvgid = !empty($ch['tvg_id']) ? ' tvg-id="' . $ch['tvg_id'] . '"' : '';
            $content .= "#EXTINF:-1" . $tvgid . $logo . $group . "," . $ch['name'] . "\n";
            $content .= $ch['url'] . "\n\n";
        }
    }
    file_put_contents($filepath, $content);
}

// Xử lý Đăng Nhập / Đăng Xuất
$error = '';
$success = '';

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    if ($_POST['password'] === $current_password) {
        $_SESSION['tdshop_logged'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Mật khẩu quản trị không chính xác!';
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['tdshop_logged']);
    header('Location: index.php');
    exit;
}

$is_logged = isset($_SESSION['tdshop_logged']) && $_SESSION['tdshop_logged'] === true;

// XỬ LÝ ACTIONS KHI ĐÃ ĐĂNG NHẬP
if ($is_logged && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // === 1. QUẢN LÝ BOX / THIẾT BỊ ===
    if ($action === 'add_device') {
        $code = trim($_POST['code'] ?? '');
        $customer = trim($_POST['customer_name'] ?? 'Khách Mới');
        $phone = trim($_POST['phone'] ?? '');
        $model = trim($_POST['model'] ?? 'Android TV Box');
        $duration = $_POST['duration'] ?? '1year';
        $note = trim($_POST['note'] ?? '');

        if (!empty($code)) {
            $devices = getDevices($devices_file);
            $expire_time = null;
            if ($duration === '1month') $expire_time = date('Y-m-d H:i:s', strtotime('+1 month'));
            elseif ($duration === '3months') $expire_time = date('Y-m-d H:i:s', strtotime('+3 months'));
            elseif ($duration === '6months') $expire_time = date('Y-m-d H:i:s', strtotime('+6 months'));
            elseif ($duration === '1year') $expire_time = date('Y-m-d H:i:s', strtotime('+1 year'));
            elseif ($duration === 'forever') $expire_time = '2099-12-31 23:59:59';

            $devices[$code] = [
                'code' => $code,
                'model' => $model,
                'customer_name' => $customer,
                'phone' => $phone,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'status' => 'active',
                'registered_at' => date('Y-m-d H:i:s'),
                'expire_at' => $expire_time,
                'last_seen' => date('Y-m-d H:i:s'),
                'note' => $note
            ];
            saveDevices($devices_file, $devices);
            $success = "Đã thêm và kích hoạt thành công Box: <b>$code</b> ($customer)";
        } else {
            $error = "Vui lòng nhập mã thiết bị / Box!";
        }
    } elseif ($action === 'activate_device') {
        $code = trim($_POST['code'] ?? '');
        $duration = $_POST['duration'] ?? '1year';
        $devices = getDevices($devices_file);
        if (isset($devices[$code])) {
            $devices[$code]['status'] = 'active';
            if ($duration === '1month') $devices[$code]['expire_at'] = date('Y-m-d H:i:s', strtotime('+1 month'));
            elseif ($duration === '3months') $devices[$code]['expire_at'] = date('Y-m-d H:i:s', strtotime('+3 months'));
            elseif ($duration === '6months') $devices[$code]['expire_at'] = date('Y-m-d H:i:s', strtotime('+6 months'));
            elseif ($duration === '1year') $devices[$code]['expire_at'] = date('Y-m-d H:i:s', strtotime('+1 year'));
            elseif ($duration === 'forever') $devices[$code]['expire_at'] = '2099-12-31 23:59:59';
            saveDevices($devices_file, $devices);
            $success = "Đã kích hoạt gia hạn Box <b>$code</b> thành công!";
        }
    } elseif ($action === 'block_device') {
        $code = trim($_POST['code'] ?? '');
        $devices = getDevices($devices_file);
        if (isset($devices[$code])) {
            $devices[$code]['status'] = 'blocked';
            saveDevices($devices_file, $devices);
            $success = "Đã khóa thiết bị Box <b>$code</b>!";
        }
    } elseif ($action === 'unblock_device') {
        $code = trim($_POST['code'] ?? '');
        $devices = getDevices($devices_file);
        if (isset($devices[$code])) {
            $devices[$code]['status'] = 'active';
            saveDevices($devices_file, $devices);
            $success = "Đã mở khóa thiết bị Box <b>$code</b>!";
        }
    } elseif ($action === 'delete_device') {
        $code = trim($_POST['code'] ?? '');
        $devices = getDevices($devices_file);
        if (isset($devices[$code])) {
            unset($devices[$code]);
            saveDevices($devices_file, $devices);
            $success = "Đã xóa Box <b>$code</b> khỏi danh sách!";
        }
    } elseif ($action === 'edit_device') {
        $code = trim($_POST['code'] ?? '');
        $customer = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $expire_at = trim($_POST['expire_at'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $status = $_POST['status'] ?? 'active';

        $devices = getDevices($devices_file);
        if (isset($devices[$code])) {
            $devices[$code]['customer_name'] = $customer;
            $devices[$code]['phone'] = $phone;
            if (!empty($expire_at)) $devices[$code]['expire_at'] = $expire_at;
            $devices[$code]['note'] = $note;
            $devices[$code]['status'] = $status;
            saveDevices($devices_file, $devices);
            $success = "Đã cập nhật thông tin Box <b>$code</b>!";
        }
    }

    // === 2. QUẢN LÝ KÊNH IPTV ===
    elseif ($action === 'add_channel') {
        $channels = parseM3U($playlist_file);
        $new_channel = [
            'id' => count($channels) + 1,
            'name' => trim($_POST['name'] ?? 'Kênh mới'),
            'group' => trim($_POST['group'] ?? 'Mặc định'),
            'logo' => trim($_POST['logo'] ?? ''),
            'tvg_id' => trim($_POST['tvg_id'] ?? ''),
            'url' => trim($_POST['url'] ?? '')
        ];
        if (!empty($new_channel['url'])) {
            $channels[] = $new_channel;
            saveM3U($playlist_file, $channels);
            $success = "Đã thêm kênh: <b>" . htmlspecialchars($new_channel['name']) . "</b>";
        } else {
            $error = "Link luồng phát (Stream URL) không được để trống!";
        }
    } elseif ($action === 'delete_channel') {
        $idx = intval($_POST['channel_index'] ?? -1);
        $channels = parseM3U($playlist_file);
        if ($idx >= 0 && $idx < count($channels)) {
            $deleted_name = $channels[$idx]['name'];
            array_splice($channels, $idx, 1);
            saveM3U($playlist_file, $channels);
            $success = "Đã xóa kênh: <b>" . htmlspecialchars($deleted_name) . "</b>";
        }
    } elseif ($action === 'edit_channel') {
        $idx = intval($_POST['channel_index'] ?? -1);
        $channels = parseM3U($playlist_file);
        if ($idx >= 0 && $idx < count($channels)) {
            $channels[$idx]['name'] = trim($_POST['name'] ?? $channels[$idx]['name']);
            $channels[$idx]['group'] = trim($_POST['group'] ?? $channels[$idx]['group']);
            $channels[$idx]['logo'] = trim($_POST['logo'] ?? $channels[$idx]['logo']);
            $channels[$idx]['tvg_id'] = trim($_POST['tvg_id'] ?? $channels[$idx]['tvg_id']);
            $channels[$idx]['url'] = trim($_POST['url'] ?? $channels[$idx]['url']);
            saveM3U($playlist_file, $channels);
            $success = "Đã cập nhật kênh: <b>" . htmlspecialchars($channels[$idx]['name']) . "</b>";
        }
    } elseif ($action === 'save_raw_m3u') {
        $raw_m3u = $_POST['raw_m3u_content'] ?? '';
        if (strpos($raw_m3u, '#EXTM3U') !== false) {
            file_put_contents($playlist_file, $raw_m3u);
            $success = "Đã lưu và cập nhật toàn bộ danh sách kênh M3U thành công!";
        } else {
            $error = "Nội dung file không hợp lệ! File M3U phải bắt đầu bằng #EXTM3U";
        }
    }

    // === 3. QUẢN LÝ THÔNG BÁO ===
    elseif ($action === 'save_notification') {
        $notif_data = [
            'active' => isset($_POST['notif_active']) ? true : false,
            'text' => trim($_POST['notif_text'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        file_put_contents($notif_file, json_encode($notif_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $success = "Đã cập nhật nội dung chữ chạy thông báo Tivi!";
    }

    // === 4. ĐỔI MẬT KHẨU & CẤU HÌNH ===
    elseif ($action === 'change_password') {
        $old_p = $_POST['old_password'] ?? '';
        $new_p = $_POST['new_password'] ?? '';
        $new_shop = trim($_POST['shop_name'] ?? $shop_name);

        if ($old_p === $current_password) {
            if (!empty($new_p)) {
                $admin_config['admin_password'] = $new_p;
                $current_password = $new_p;
            }
            $admin_config['shop_name'] = $new_shop;
            file_put_contents($config_file, json_encode($admin_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $success = "Đã cập nhật thông tin và mật khẩu quản trị mới thành công!";
        } else {
            $error = "Mật khẩu hiện tại không chính xác!";
        }
    }
}

// Dữ liệu đọc cho giao diện
$devices_data = getDevices($devices_file);
$channels_data = parseM3U($playlist_file);
$notif_data = file_exists($notif_file) ? json_decode(file_get_contents($notif_file), true) : ['active' => true, 'text' => ''];
$raw_m3u_text = file_exists($playlist_file) ? file_get_contents($playlist_file) : '';

// Thống kê Box
$total_devices = count($devices_data);
$active_devices = 0;
$pending_devices = 0;
$expired_devices = 0;
$blocked_devices = 0;
$now_ts = time();

foreach ($devices_data as $dev) {
    $st = $dev['status'] ?? 'active';
    $exp_ts = !empty($dev['expire_at']) ? strtotime($dev['expire_at']) : 0;
    if ($st === 'blocked') {
        $blocked_devices++;
    } elseif ($st === 'pending') {
        $pending_devices++;
    } elseif ($exp_ts > 0 && $exp_ts < $now_ts) {
        $expired_devices++;
    } else {
        $active_devices++;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($shop_name) ?> - Trình Quản Trị Hệ Thống</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-dark: #0A0F1D;
            --card-bg: #11192E;
            --card-border: #1E2B4D;
            --primary: #38BDF8;
            --primary-gradient: linear-gradient(135deg, #38BDF8, #818CF8);
            --accent-green: #10B981;
            --accent-yellow: #F59E0B;
            --accent-red: #EF4444;
            --text-main: #FFFFFF;
            --text-muted: #94A3B8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg-dark); color: var(--text-main); min-height: 100vh; display: flex; flex-direction: column; }

        /* HEADER */
        header {
            background: linear-gradient(180deg, #16203A 0%, rgba(17, 25, 46, 0.95) 100%);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--card-border);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .brand { display: flex; align-items: center; gap: 14px; }
        .brand-logo {
            width: 44px; height: 44px;
            background: var(--primary-gradient);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; color: #0A0F1D; font-weight: 800;
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.4);
        }
        .brand-info h1 { font-size: 18px; font-weight: 800; background: linear-gradient(90deg, #FFFFFF, #38BDF8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .brand-info p { font-size: 12px; color: var(--text-muted); }

        .btn-logout {
            background: rgba(239, 68, 68, 0.15); color: var(--accent-red);
            border: 1px solid rgba(239, 68, 68, 0.3); padding: 8px 16px;
            border-radius: 8px; font-size: 13px; font-weight: 600;
            text-decoration: none; display: flex; align-items: center; gap: 6px;
            transition: all 0.2s;
        }
        .btn-logout:hover { background: var(--accent-red); color: white; }

        /* CONTAINER */
        .container { max-width: 1280px; width: 100%; margin: 0 auto; padding: 24px 16px; flex: 1; }

        /* ALERTS */
        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid var(--accent-green); color: var(--accent-green); }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid var(--accent-red); color: var(--accent-red); }

        /* STATS GRID */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 16px; padding: 18px; display: flex; align-items: center; gap: 14px;
        }
        .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 22px;
        }
        .stat-icon.total { background: rgba(56, 189, 248, 0.15); color: var(--primary); }
        .stat-icon.active { background: rgba(16, 185, 129, 0.15); color: var(--accent-green); }
        .stat-icon.pending { background: rgba(245, 158, 11, 0.15); color: var(--accent-yellow); }
        .stat-icon.expired { background: rgba(239, 68, 68, 0.15); color: var(--accent-red); }
        .stat-icon.channel { background: rgba(129, 140, 248, 0.15); color: #818CF8; }

        .stat-info .num { font-size: 24px; font-weight: 800; color: white; }
        .stat-info .label { font-size: 12px; color: var(--text-muted); font-weight: 500; }

        /* NAVIGATION TABS */
        .nav-tabs {
            display: flex; gap: 10px; border-bottom: 1px solid var(--card-border);
            margin-bottom: 24px; overflow-x: auto; padding-bottom: 8px;
        }
        .nav-tab-btn {
            background: #0A0F1D; border: 1px solid var(--card-border); color: var(--text-muted);
            padding: 12px 20px; border-radius: 12px; font-size: 14px; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; gap: 8px; white-space: nowrap;
            transition: all 0.2s;
        }
        .nav-tab-btn.active {
            background: var(--primary-gradient); color: #0A0F1D; border-color: var(--primary);
            box-shadow: 0 4px 15px rgba(56, 189, 248, 0.3);
        }

        /* TAB PANES */
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        /* CARDS & FORMS */
        .card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 18px; padding: 22px; margin-bottom: 24px;
        }
        .card-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--card-border);
        }
        .card-header h2 { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px; color: white; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px; font-weight: 600; }
        .form-control {
            width: 100%; background: #0A0F1D; border: 1px solid var(--card-border);
            padding: 12px 14px; border-radius: 10px; color: white; font-size: 14px; outline: none;
            transition: border-color 0.2s;
        }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 10px rgba(56, 189, 248, 0.2); }

        .btn {
            padding: 10px 18px; border-radius: 10px; font-size: 14px; font-weight: 700;
            cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 8px;
            transition: all 0.2s; text-decoration: none;
        }
        .btn-primary { background: var(--primary-gradient); color: #0A0F1D; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-success { background: var(--accent-green); color: white; }
        .btn-danger { background: var(--accent-red); color: white; }
        .btn-warning { background: var(--accent-yellow); color: #0A0F1D; }
        .btn-secondary { background: #1E2B4D; color: white; }

        /* DEVICE CARDS */
        .device-card {
            background: #0D1426; border: 1px solid var(--card-border);
            border-radius: 16px; padding: 18px; margin-bottom: 14px;
            display: flex; flex-direction: column; gap: 12px;
            transition: border-color 0.2s;
        }
        .device-card:hover { border-color: rgba(56, 189, 248, 0.4); }
        .device-header { display: flex; justify-content: space-between; align-items: center; }
        .device-main { display: flex; align-items: center; gap: 14px; }
        .device-icon {
            width: 44px; height: 44px; background: #0A0F1D; border: 1px solid var(--card-border);
            border-radius: 12px; display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: var(--primary);
        }
        .device-titles h3 { font-size: 16px; font-weight: 700; color: white; }
        .device-code {
            font-family: monospace; font-size: 13px; color: var(--primary);
            background: rgba(56, 189, 248, 0.1); padding: 2px 8px; border-radius: 6px;
        }

        .badge { font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; }
        .badge.active { background: rgba(16, 185, 129, 0.15); color: var(--accent-green); border: 1px solid var(--accent-green); }
        .badge.pending { background: rgba(245, 158, 11, 0.15); color: var(--accent-yellow); border: 1px solid var(--accent-yellow); }
        .badge.expired { background: rgba(239, 68, 68, 0.15); color: var(--accent-red); border: 1px solid var(--accent-red); }
        .badge.blocked { background: rgba(148, 163, 184, 0.15); color: var(--text-muted); border: 1px solid var(--text-muted); }

        .device-details-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px 16px; background: #0A0F1D; padding: 12px 16px; border-radius: 10px; font-size: 13px;
        }
        .detail-item { display: flex; gap: 6px; color: var(--text-muted); }
        .detail-item span { color: white; font-weight: 600; }

        .device-actions { display: flex; flex-wrap: wrap; gap: 8px; }

        /* CHANNEL LIST */
        .channel-table { width: 100%; border-collapse: collapse; text-align: left; }
        .channel-table th { background: #0A0F1D; padding: 12px 14px; font-size: 13px; color: var(--text-muted); border-bottom: 1px solid var(--card-border); }
        .channel-table td { padding: 12px 14px; font-size: 14px; border-bottom: 1px solid var(--card-border); vertical-align: middle; }
        .channel-logo { width: 36px; height: 36px; object-fit: contain; background: #0A0F1D; border-radius: 6px; padding: 2px; }

        /* LOGIN FORM */
        .login-box { max-width: 420px; width: 100%; margin: 60px auto; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 20px; padding: 36px 28px; text-align: center; }
        .login-logo { width: 64px; height: 64px; margin: 0 auto 16px; background: var(--primary-gradient); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 32px; color: #0A0F1D; }
    </style>
</head>
<body>

<?php if (!$is_logged): ?>
    <!-- GIAO DIỆN ĐĂNG NHẬP -->
    <div class="login-box">
        <div class="login-logo"><i class="fa-solid fa-tv"></i></div>
        <h2 style="font-size: 20px; font-weight: 800; margin-bottom: 6px;"><?= htmlspecialchars($shop_name) ?></h2>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 24px;">Vui lòng nhập mật khẩu quản trị để tiếp tục</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= $error ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="form-group" style="text-align: left;">
                <label>Mật khẩu quản trị</label>
                <input type="password" name="password" class="form-control" placeholder="Nhập mật khẩu (Mặc định: tdshop2026)" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px;">
                <i class="fa-solid fa-right-to-bracket"></i> Đăng Nhập Hệ Thống
            </button>
        </form>
    </div>
<?php else: ?>
    <!-- GIAO DIỆN QUẢN TRỊ VIÊN -->
    <header>
        <div class="brand">
            <div class="brand-logo"><i class="fa-solid fa-tv"></i></div>
            <div class="brand-info">
                <h1><?= htmlspecialchars($shop_name) ?></h1>
                <p>Hệ thống Quản Trị Box & IPTV Chuyên Nghiệp</p>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="?logout=1" class="btn-logout"><i class="fa-solid fa-power-off"></i> Đăng Xuất</a>
        </div>
    </header>

    <div class="container">
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= $success ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= $error ?></div>
        <?php endif; ?>

        <!-- THỐNG KÊ TỔNG QUAN -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total"><i class="fa-solid fa-boxes-stacked"></i></div>
                <div class="stat-info">
                    <div class="num"><?= $total_devices ?></div>
                    <div class="label">Tổng Số Box</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon active"><i class="fa-solid fa-circle-check"></i></div>
                <div class="stat-info">
                    <div class="num"><?= $active_devices ?></div>
                    <div class="label">Đang Hoạt Động</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon pending"><i class="fa-solid fa-clock"></i></div>
                <div class="stat-info">
                    <div class="num"><?= $pending_devices ?></div>
                    <div class="label">Chờ Duyệt Box</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon expired"><i class="fa-solid fa-calendar-xmark"></i></div>
                <div class="stat-info">
                    <div class="num"><?= $expired_devices ?></div>
                    <div class="label">Hết Hạn</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon channel"><i class="fa-solid fa-play"></i></div>
                <div class="stat-info">
                    <div class="num"><?= count($channels_data) ?></div>
                    <div class="label">Tổng Kênh IPTV</div>
                </div>
            </div>
        </div>

        <!-- NAVIGATION TABS -->
        <div class="nav-tabs">
            <button class="nav-tab-btn active" onclick="switchTab('tab-devices')"><i class="fa-solid fa-microchip"></i> 1. Quản Lý Box (Thiết Bị)</button>
            <button class="nav-tab-btn" onclick="switchTab('tab-channels')"><i class="fa-solid fa-list-ol"></i> 2. Danh Sách Kênh IPTV</button>
            <button class="nav-tab-btn" onclick="switchTab('tab-raw-m3u')"><i class="fa-solid fa-file-lines"></i> 3. Dán M3U Nhanh</button>
            <button class="nav-tab-btn" onclick="switchTab('tab-notification')"><i class="fa-solid fa-bullhorn"></i> 4. Thông Báo Tivi</button>
            <button class="nav-tab-btn" onclick="switchTab('tab-settings')"><i class="fa-solid fa-gear"></i> 5. Cấu Hình & Mật Khẩu</button>
        </div>

        <!-- TAB 1: QUẢN LÝ BOX / THIẾT BỊ -->
        <div id="tab-devices" class="tab-pane active">
            <!-- FORM THÊM BOX -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-plus-circle" style="color: var(--primary);"></i> Thêm & Kích Hoạt Box Mới</h2>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_device">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Mã Thiết Bị / Box ID *</label>
                            <input type="text" name="code" class="form-control" placeholder="VD: 91286869" required>
                        </div>
                        <div class="form-group">
                            <label>Tên Khách Hàng</label>
                            <input type="text" name="customer_name" class="form-control" placeholder="VD: Anh Nam - Q.3">
                        </div>
                        <div class="form-group">
                            <label>Số Điện Thoại</label>
                            <input type="text" name="phone" class="form-control" placeholder="VD: 0912345678">
                        </div>
                        <div class="form-group">
                            <label>Loại Box / Model</label>
                            <input type="text" name="model" class="form-control" placeholder="VD: Tanix TX3 / Box 97" value="Android TV Box">
                        </div>
                        <div class="form-group">
                            <label>Gói Kích Hoạt</label>
                            <select name="duration" class="form-control">
                                <option value="1year" selected>1 Năm (365 Ngày)</option>
                                <option value="6months">6 Tháng</option>
                                <option value="3months">3 Tháng</option>
                                <option value="1month">1 Tháng</option>
                                <option value="forever">Vĩnh Viễn (Trọn Đời)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Ghi Chú</label>
                            <input type="text" name="note" class="form-control" placeholder="VD: Kèm hóa đơn #1234">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Lưu & Kích Hoạt Ngay</button>
                </form>
            </div>

            <!-- DANH SÁCH BOX ĐÃ CÀI ĐẶT -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-list-check" style="color: var(--primary);"></i> Danh Sách Box Khách Hàng (<?= count($devices_data) ?>)</h2>
                    <input type="text" id="searchBoxInput" onkeyup="filterDeviceCards()" placeholder="🔍 Tìm mã Box, khách hàng, SĐT..." class="form-control" style="max-width: 320px; padding: 8px 14px;">
                </div>

                <div id="deviceListContainer">
                    <?php if (empty($devices_data)): ?>
                        <p style="text-align: center; color: var(--text-muted); padding: 30px;">Chưa có thiết bị nào trong danh sách.</p>
                    <?php else: ?>
                        <?php foreach ($devices_data as $code => $dev): 
                            $st = $dev['status'] ?? 'active';
                            $exp_ts = !empty($dev['expire_at']) ? strtotime($dev['expire_at']) : 0;
                            $is_exp = ($exp_ts > 0 && $exp_ts < $now_ts);
                            $badge_class = 'active';
                            $badge_text = 'Đang Hoạt Động';

                            if ($st === 'blocked') {
                                $badge_class = 'blocked';
                                $badge_text = 'Đã Khóa';
                            } elseif ($st === 'pending') {
                                $badge_class = 'pending';
                                $badge_text = 'Chờ Kích Hoạt';
                            } elseif ($is_exp) {
                                $badge_class = 'expired';
                                $badge_text = 'Đã Hết Hạn';
                            }
                        ?>
                            <div class="device-card" data-search="<?= strtolower($code . ' ' . ($dev['customer_name']??'') . ' ' . ($dev['phone']??'') . ' ' . ($dev['model']??'')) ?>">
                                <div class="device-header">
                                    <div class="device-main">
                                        <div class="device-icon"><i class="fa-solid fa-tv"></i></div>
                                        <div class="device-titles">
                                            <h3><?= htmlspecialchars($dev['customer_name'] ?? 'Khách Chưa Đặt Tên') ?> <span class="device-code">MÃ: <?= htmlspecialchars($code) ?></span></h3>
                                            <span style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($dev['model'] ?? 'Android TV Box') ?></span>
                                        </div>
                                    </div>
                                    <span class="badge <?= $badge_class ?>"><?= $badge_text ?></span>
                                </div>

                                <div class="device-details-grid">
                                    <div class="detail-item"><i class="fa-solid fa-phone"></i> SĐT: <span><?= htmlspecialchars($dev['phone'] ?? 'Chưa có') ?></span></div>
                                    <div class="detail-item"><i class="fa-solid fa-calendar-plus"></i> Đăng ký: <span><?= htmlspecialchars($dev['registered_at'] ?? 'N/A') ?></span></div>
                                    <div class="detail-item"><i class="fa-solid fa-hourglass-end"></i> Hết hạn: <span style="color: <?= $is_exp ? 'var(--accent-red)' : 'var(--primary)' ?>;"><?= htmlspecialchars($dev['expire_at'] ?? 'Vĩnh viễn') ?></span></div>
                                    <div class="detail-item"><i class="fa-solid fa-wifi"></i> IP gần nhất: <span><?= htmlspecialchars($dev['ip'] ?? 'N/A') ?></span></div>
                                </div>

                                <?php if (!empty($dev['note'])): ?>
                                    <div style="font-size: 13px; color: var(--text-muted);"><i class="fa-solid fa-note-sticky"></i> Ghi chú: <i><?= htmlspecialchars($dev['note']) ?></i></div>
                                <?php endif; ?>

                                <div class="device-actions">
                                    <!-- Kích hoạt 1 năm -->
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="activate_device">
                                        <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
                                        <input type="hidden" name="duration" value="1year">
                                        <button type="submit" class="btn btn-success" style="font-size: 12px; padding: 6px 12px;"><i class="fa-solid fa-calendar-check"></i> Gia Hạn 1 Năm</button>
                                    </form>

                                    <!-- Khóa / Mở khóa -->
                                    <?php if ($st === 'blocked'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="unblock_device">
                                            <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
                                            <button type="submit" class="btn btn-warning" style="font-size: 12px; padding: 6px 12px;"><i class="fa-solid fa-lock-open"></i> Mở Khóa</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="block_device">
                                            <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
                                            <button type="submit" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px;" onclick="return confirm('Bạn có chắc muốn khóa Box này?')"><i class="fa-solid fa-lock"></i> Khóa Box</button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Xóa -->
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_device">
                                        <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
                                        <button type="submit" class="btn btn-danger" style="font-size: 12px; padding: 6px 12px;" onclick="return confirm('Bạn có chắc muốn xóa Box <?= htmlspecialchars($code) ?>?')"><i class="fa-solid fa-trash"></i> Xóa</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TAB 2: QUẢN LÝ KÊNH IPTV -->
        <div id="tab-channels" class="tab-pane">
            <!-- FORM THÊM KÊNH -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-plus" style="color: var(--primary);"></i> Thêm Kênh Truyền Hình Mới</h2>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_channel">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Tên Kênh *</label>
                            <input type="text" name="name" class="form-control" placeholder="VD: VTV1 HD" required>
                        </div>
                        <div class="form-group">
                            <label>Nhóm / Thể Loại</label>
                            <input type="text" name="group" class="form-control" placeholder="VD: VTV, HTV, Thể Thao" value="VTV">
                        </div>
                        <div class="form-group">
                            <label>Link Logo Kênh (PNG / JPG)</label>
                            <input type="text" name="logo" class="form-control" placeholder="VD: https://raw.githubusercontent.com/.../vtv1.png">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Link Luồng Phát Trực Tiếp (.m3u8 / .mpd / http) *</label>
                            <input type="text" name="url" class="form-control" placeholder="VD: https://stream.example.com/vtv1/playlist.m3u8" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Lưu Kênh</button>
                </form>
            </div>

            <!-- BẢNG DANH SÁCH KÊNH -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-tv" style="color: var(--primary);"></i> Danh Sách Kênh Hiện Tại (<?= count($channels_data) ?> Kênh)</h2>
                    <a href="../playlist.m3u" target="_blank" class="btn btn-secondary" style="font-size: 13px;"><i class="fa-solid fa-download"></i> Xem File .M3U</a>
                </div>

                <div style="overflow-x: auto;">
                    <table class="channel-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">STT</th>
                                <th style="width: 60px;">Logo</th>
                                <th>Tên Kênh</th>
                                <th>Nhóm</th>
                                <th>Link Luồng</th>
                                <th style="width: 100px; text-align: right;">Hành Động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($channels_data)): ?>
                                <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">Chưa có kênh nào trong danh sách.</td></tr>
                            <?php else: ?>
                                <?php foreach ($channels_data as $i => $ch): ?>
                                    <tr>
                                        <td style="font-weight: 700; color: var(--primary);"><?= $i + 1 ?></td>
                                        <td>
                                            <?php if (!empty($ch['logo'])): ?>
                                                <img src="<?= htmlspecialchars($ch['logo']) ?>" class="channel-logo" alt="logo" onerror="this.style.display='none'">
                                            <?php else: ?>
                                                <div class="channel-logo" style="display: flex; align-items: center; justify-content: center; color: var(--text-muted);"><i class="fa-solid fa-tv"></i></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-weight: 700;"><?= htmlspecialchars($ch['name']) ?></td>
                                        <td><span class="badge" style="background: rgba(56, 189, 248, 0.1); color: var(--primary);"><?= htmlspecialchars($ch['group']) ?></span></td>
                                        <td style="font-family: monospace; font-size: 12px; color: var(--text-muted); max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?= htmlspecialchars($ch['url']) ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_channel">
                                                <input type="hidden" name="channel_index" value="<?= $i ?>">
                                                <button type="submit" class="btn btn-danger" style="padding: 6px 10px; font-size: 12px;" onclick="return confirm('Bạn có chắc muốn xóa kênh <?= htmlspecialchars($ch['name']) ?>?')"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 3: DÁN M3U RAW -->
        <div id="tab-raw-m3u" class="tab-pane">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-file-code" style="color: var(--primary);"></i> Chỉnh Sửa Hoặc Dán Hàng Loạt File M3U</h2>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="save_raw_m3u">
                    <div class="form-group">
                        <label>Toàn Bộ Nội Dung File M3U (Bắt đầu bằng #EXTM3U)</label>
                        <textarea name="raw_m3u_content" class="form-control" rows="18" style="font-family: monospace; font-size: 13px; line-height: 1.5;"><?= htmlspecialchars($raw_m3u_text) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Lưu & Cập Nhật Danh Sách Kênh</button>
                </form>
            </div>
        </div>

        <!-- TAB 4: THÔNG BÁO TIVI -->
        <div id="tab-notification" class="tab-pane">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-bullhorn" style="color: var(--primary);"></i> Cấu Hình Chữ Chạy Thông Báo Trên Tivi Khách Hàng</h2>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="save_notification">
                    <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px;">
                        <input type="checkbox" name="notif_active" id="notif_active" value="1" <?= (!empty($notif_data['active'])) ? 'checked' : '' ?> style="width: 20px; height: 20px; cursor: pointer;">
                        <label for="notif_active" style="margin: 0; font-size: 15px; font-weight: 700; color: white; cursor: pointer;">Hiển thị thông báo trên màn hình Tivi</label>
                    </div>
                    <div class="form-group">
                        <label>Nội Dung Thông Báo (Chữ chạy banner phía trên cùng)</label>
                        <textarea name="notif_text" class="form-control" rows="4" placeholder="Nhập lời chào hoặc thông báo khuyến mãi..."><?= htmlspecialchars($notif_data['text'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Cập Nhật Thông Báo</button>
                </form>
            </div>
        </div>

        <!-- TAB 5: CẤU HÌNH & MẬT KHẨU -->
        <div id="tab-settings" class="tab-pane">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-shield-halved" style="color: var(--primary);"></i> Đổi Tên Shop & Mật Khẩu Quản Trị</h2>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Tên Shop / Tiêu Đề</label>
                            <input type="text" name="shop_name" class="form-control" value="<?= htmlspecialchars($shop_name) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Mật Khẩu Hiện Tại *</label>
                            <input type="password" name="old_password" class="form-control" placeholder="Nhập mật khẩu hiện tại" required>
                        </div>
                        <div class="form-group">
                            <label>Mật Khẩu Mới (Bỏ trống nếu không đổi)</label>
                            <input type="password" name="new_password" class="form-control" placeholder="Nhập mật khẩu mới">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-key"></i> Lưu Cấu Hình</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
function switchTab(tabId) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-tab-btn').forEach(b => b.classList.remove('active'));
    
    const target = document.getElementById(tabId);
    if (target) target.classList.add('active');
    
    event.currentTarget.classList.add('active');
}

function filterDeviceCards() {
    const q = document.getElementById('searchBoxInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.device-card');
    cards.forEach(c => {
        const text = c.getAttribute('data-search') || '';
        c.style.display = text.includes(q) ? 'flex' : 'none';
    });
}
</script>
</body>
</html>
