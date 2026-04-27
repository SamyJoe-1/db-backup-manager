<?php
// -------------------------------------------------------
// /var/www/dbbackup/back-up.php
// -------------------------------------------------------
require_once '/etc/dbbackup/config.php';

session_start();
// -------------------------------------------------------
// AUTH
// -------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['login_user'])) {
    if (
        $_POST['login_user'] === AUTH_USER &&
        password_verify($_POST['login_pass'], AUTH_HASH)
    ) {
        $_SESSION['auth'] = true;
        $_SESSION['auth_time'] = time();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $login_error = 'Invalid credentials';
    }
}

if (
    empty($_SESSION['auth']) ||
    (time() - ($_SESSION['auth_time'] ?? 0)) > SESSION_LIFETIME
) {
    session_destroy();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login — DB Backup Manager</title>
        <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            body{background:#0a0a0f;color:#e2e8f0;font-family:'JetBrains Mono',monospace;display:flex;align-items:center;justify-content:center;min-height:100vh}
            .box{background:#13131a;border:1px solid #2a2a3a;border-radius:12px;padding:40px;width:100%;max-width:380px}
            h2{font-size:18px;margin-bottom:24px;color:#fff;letter-spacing:1px}
            input{width:100%;background:#0a0a0f;border:1px solid #2a2a3a;border-radius:8px;padding:12px 14px;color:#e2e8f0;font-family:inherit;font-size:14px;margin-bottom:14px;outline:none}
            input:focus{border-color:#00ff88}
            button{width:100%;background:#00ff88;border:none;border-radius:8px;padding:12px;color:#fff;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer;letter-spacing:1px}
            button:hover{background:#00ff88}
            .error{color:#ff6b6b;font-size:12px;margin-bottom:14px}
        </style>
    </head>
    <body>
    <div class="box">
        <h2>DB BACKUP MANAGER</h2>
        <?php if (!empty($login_error)) echo "<div class='error'>{$login_error}</div>"; ?>
        <form method="POST">
            <input type="text" name="login_user" placeholder="Username" required autofocus>
            <input type="password" name="login_pass" placeholder="Password" required>
            <button type="submit">LOGIN</button>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}
// -------------------------------------------------------
// END AUTH
// -------------------------------------------------------
// -------------------------------------------------------
// HELPERS
// -------------------------------------------------------
function db_connect() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, null, DB_PORT);
    if ($conn->connect_error) die(json_encode(['error' => $conn->connect_error]));
    return $conn;
}

function load_json($file, $default = []) {
    if (!file_exists($file)) return $default;
    $data = json_decode(file_get_contents($file), true);
    return $data ?? $default;
}

function save_json($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function get_databases() {
    return load_json(DB_LIST_FILE, []);
}

function get_schedules() {
    return load_json(SCHEDULE_FILE, []);
}

function get_backups_for($db) {
    $dir = BACKUP_DIR . $db . '/';
    if (!is_dir($dir)) return [];
    $files = glob($dir . '*.sql.gz');
    if (!$files) return [];
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    return array_map(fn($f) => [
        'file'    => basename($f),
        'path'    => $f,
        'size'    => round(filesize($f) / 1024, 1) . ' KB',
        'date'    => date('Y-m-d H:i:s', filemtime($f)),
        'ts'      => filemtime($f),
    ], $files);
}

function rebuild_crontab($schedules) {
    $marker_start = '# === DBBACKUP START ===';
    $marker_end   = '# === DBBACKUP END ===';
    $crontab = shell_exec('crontab -l 2>/dev/null') ?? '';
    // Strip old block
    $crontab = preg_replace(
        '/' . preg_quote($marker_start) . '.*?' . preg_quote($marker_end) . '/s',
        '',
        $crontab
    );
    $crontab = trim($crontab);
    $lines = [$marker_start];
    foreach ($schedules as $db => $interval) {
        $cron = interval_to_cron($interval);
        if ($cron) {
            $lines[] = "$cron /usr/local/bin/db-backup.sh $db >> /var/log/dbbackup.log 2>&1";
        }
    }
    $lines[] = $marker_end;
    $new_crontab = $crontab . "\n" . implode("\n", $lines) . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'cron');
    file_put_contents($tmp, $new_crontab);
    shell_exec("crontab $tmp");
    unlink($tmp);
}

function interval_to_cron($interval) {
    $map = [
        '2m' => '*/2 * * * *',
        '10m' => '*/10 * * * *',
        '1h'   => '0 * * * *',
        '3h'   => '0 */3 * * *',
        '6h'   => '0 */6 * * *',
        '12h'  => '0 */12 * * *',
        '24h'  => '0 2 * * *',
        'weekly' => '0 2 * * 0',
    ];
    return $map[$interval] ?? null;
}

// -------------------------------------------------------
// AJAX ACTIONS
// -------------------------------------------------------
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'];

    // --- DB LIST ---
    if ($action === 'list_dbs') {
        echo json_encode(['dbs' => get_databases(), 'schedules' => get_schedules()]);
        exit;
    }

    if ($action === 'add_db') {
        $db = trim($_POST['db'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $db)) {
            echo json_encode(['error' => 'Invalid DB name']); exit;
        }
        // Verify DB actually exists on the server
        $conn = db_connect();
        $res = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . $conn->real_escape_string($db) . "'");
        if (!$res || $res->num_rows === 0) {
            $conn->close();
            echo json_encode(['error' => "Database '{$db}' does not exist on this server"]); exit;
        }
        $conn->close();
        $list = get_databases();
        if (!in_array($db, $list)) $list[] = $db;
        save_json(DB_LIST_FILE, $list);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'remove_db') {
        $db = trim($_POST['db'] ?? '');
        $list = get_databases();
        $list = array_values(array_filter($list, fn($d) => $d !== $db));
        save_json(DB_LIST_FILE, $list);
        $schedules = get_schedules();
        unset($schedules[$db]);
        save_json(SCHEDULE_FILE, $schedules);
        rebuild_crontab($schedules);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'set_schedule') {
        $db       = trim($_POST['db'] ?? '');
        $interval = trim($_POST['interval'] ?? '');
        $schedules = get_schedules();
        if ($interval === 'none') {
            unset($schedules[$db]);
        } else {
            $schedules[$db] = $interval;
        }
        save_json(SCHEDULE_FILE, $schedules);
        rebuild_crontab($schedules);
        echo json_encode(['ok' => true]); exit;
    }

    // --- BACKUPS ---
    if ($action === 'list_backups') {
        $db = trim($_POST['db'] ?? '');
        echo json_encode(['backups' => get_backups_for($db)]); exit;
    }

    if ($action === 'backup_now') {
        $db = trim($_POST['db'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $db)) {
            echo json_encode(['error' => 'Invalid DB name']); exit;
        }
        $out = shell_exec("/usr/local/bin/db-backup.sh " . escapeshellarg($db) . " 2>&1");
        echo json_encode(['ok' => true, 'output' => $out]); exit;
    }

    if ($action === 'restore') {
        $db   = trim($_POST['db'] ?? '');
        $file = trim($_POST['file'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $db)) {
            echo json_encode(['error' => 'Invalid DB name']); exit;
        }

        $path = BACKUP_DIR . $db . '/' . basename($file);

        if (!file_exists($path) || !str_ends_with($path, '.sql.gz')) {
            echo json_encode(['error' => 'Backup file not found']); exit;
        }

        $conn = db_connect();

        // Drop and recreate DB
        $conn->query("DROP DATABASE IF EXISTS `$db`");
        $conn->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->close();

        $cmd = sprintf(
            'zcat %s | mysql -h %s -u %s -p%s %s 2>&1',
            escapeshellarg($path),
            escapeshellarg(DB_HOST),
            escapeshellarg(DB_USER),
            escapeshellarg(DB_PASS),
            escapeshellarg($db)
        );

        $output = shell_exec($cmd);
        $success = (strpos($output ?? '', 'ERROR') === false);

        echo json_encode([
            'ok'     => $success,
            'output' => $output ?? 'Done',
            'error'  => $success ? null : $output,
        ]);
        exit;
    }

    if ($action === 'delete_backup') {
        $db   = trim($_POST['db'] ?? '');
        $file = trim($_POST['file'] ?? '');
        $path = BACKUP_DIR . $db . '/' . basename($file);
        if (file_exists($path)) unlink($path);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'search_dbs') {
        $q = trim($_POST['q'] ?? '');
        $conn = db_connect();
        $safe = $conn->real_escape_string($q);
        $res = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE '%{$safe}%' ORDER BY SCHEMA_NAME LIMIT 20");
        $dbs = [];
        while ($row = $res->fetch_assoc()) $dbs[] = $row['SCHEMA_NAME'];
        $conn->close();
        echo json_encode(['dbs' => $dbs]); exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB Backup Manager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Syne:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:       #0a0a0f;
            --surface:  #111118;
            --border:   #1e1e2e;
            --accent:   #00ff88;
            --accent2:  #ff3c6e;
            --accent3:  #3c8eff;
            --text:     #e0e0f0;
            --muted:    #555570;
            --danger:   #ff3c6e;
            --success:  #00ff88;
            --mono:     'JetBrains Mono', monospace;
            --sans:     sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--mono);
            font-size: 13px;
            min-height: 100vh;
            padding: 0;
            overflow-x: hidden;
        }

        /* GRID NOISE */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                    linear-gradient(rgba(0,255,136,.015) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(0,255,136,.015) 1px, transparent 1px);
            background-size: 32px 32px;
            pointer-events: none;
            z-index: 0;
        }

        .app {
            position: relative;
            z-index: 1;
            max-width: 1100px;
            margin: 0 auto;
            padding: 32px 24px;
        }

        /* HEADER */
        header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 40px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        .logo {
            width: 40px; height: 40px;
            background: var(--accent);
            display: flex; align-items: center; justify-content: center;
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
        }

        .logo svg { width: 22px; height: 22px; }

        header h1 {
            font-family: var(--sans);
            font-weight: 800;
            font-size: 22px;
            letter-spacing: -0.5px;
            color: #fff;
        }

        header h1 span { color: var(--accent); }

        .status-dot {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 11px;
        }

        .status-dot::before {
            content: '';
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 8px var(--accent);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        /* LAYOUT */
        .grid-2 {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 20px;
            align-items: start;
        }

        @media (max-width: 768px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

        /* PANEL */
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            overflow: hidden;
        }

        .panel-databases {
            overflow: visible;
        }

        .panel-databases .panel-body {
            overflow: visible;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            background: rgba(255,255,255,.02);
        }

        .panel-title {
            font-family: var(--sans);
            font-weight: 600;
            font-size: 12px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--muted);
        }

        .panel-body { padding: 16px 18px; }

        /* DB LIST */
        .db-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 4px;
            cursor: pointer;
            transition: background .15s, border-color .15s;
            border: 1px solid transparent;
            margin-bottom: 4px;
        }

        .db-item:hover { background: rgba(255,255,255,.04); }

        .db-item.active {
            background: rgba(0,255,136,.06);
            border-color: rgba(0,255,136,.2);
        }

        .db-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--muted);
            flex-shrink: 0;
        }

        .db-item.active .db-dot { background: var(--accent); box-shadow: 0 0 6px var(--accent); }

        .db-name {
            flex: 1;
            font-weight: 600;
            color: var(--text);
            font-size: 13px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .db-schedule {
            font-size: 10px;
            color: var(--muted);
            background: var(--border);
            padding: 2px 6px;
            border-radius: 3px;
        }

        .db-remove {
            opacity: 0;
            color: var(--danger);
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            padding: 2px 4px;
            transition: opacity .15s;
        }

        .db-item:hover .db-remove { opacity: 1; }

        /* ADD DB FORM */
        .add-form {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
            align-items: flex-end;
            position: relative;
        }

        .db-input-wrap {
            position: relative;
            flex: 1;
        }

        .db-suggestions {
            position: absolute;
            left: 0;
            right: 0;
            bottom: calc(100% + 8px);
            display: none;
            width: 100%;
            max-height: 220px;
            overflow-y: auto;
            background: #0d0d14;
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 6px;
            box-shadow: 0 18px 40px rgba(0,0,0,.42);
            padding: 6px;
            z-index: 60;
        }

        .db-suggestion-item {
            display: flex;
            align-items: center;
            min-height: 36px;
            padding: 8px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            color: var(--text);
            line-height: 1.35;
            transition: background .12s ease, color .12s ease;
        }

        .db-suggestion-item:hover {
            background: rgba(0,255,136,.08);
            color: var(--accent);
        }

        /* INPUTS */
        input[type=text], select {
            background: rgba(255,255,255,.04);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text);
            font-family: var(--mono);
            font-size: 12px;
            padding: 8px 12px;
            outline: none;
            transition: border-color .15s;
            width: 100%;
        }

        input[type=text]:focus, select:focus {
            border-color: var(--accent);
            background: rgba(0,255,136,.04);
        }

        select option { background: #111; }

        /* BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 4px;
            border: 1px solid;
            cursor: pointer;
            font-family: var(--mono);
            font-size: 12px;
            font-weight: 600;
            transition: all .15s;
            white-space: nowrap;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #000;
        }
        .btn-primary:hover { background: #00cc6a; border-color: #00cc6a; }

        .btn-ghost {
            background: transparent;
            border-color: var(--border);
            color: var(--muted);
        }
        .btn-ghost:hover { border-color: var(--accent); color: var(--accent); }

        .btn-danger {
            background: transparent;
            border-color: rgba(255,60,110,.3);
            color: var(--danger);
        }
        .btn-danger:hover { background: rgba(255,60,110,.1); }

        .btn-sm { padding: 5px 10px; font-size: 11px; }

        .btn:disabled { opacity: 0.4; pointer-events: none; }

        /* SCHEDULE ROW */
        .schedule-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .schedule-row:last-child { border-bottom: none; }

        .schedule-label {
            flex: 1;
            font-size: 12px;
            color: var(--text);
        }

        /* BACKUPS TABLE */
        .backups-list { margin-top: 8px; }

        .backup-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 4px;
            border: 1px solid transparent;
            margin-bottom: 4px;
            cursor: pointer;
            transition: all .15s;
        }

        .backup-row:hover { background: rgba(255,255,255,.03); border-color: var(--border); }

        .backup-row.selected { background: rgba(60,142,255,.07); border-color: rgba(60,142,255,.25); }

        .backup-date { flex: 1; font-size: 12px; color: var(--text); }
        .backup-file { color: var(--muted); font-size: 10px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px; }
        .backup-size { color: var(--muted); font-size: 11px; }
        .backup-del { color: var(--danger); opacity: 0; cursor: pointer; padding: 2px 4px; font-size: 14px; transition: opacity .15s; }
        .backup-row:hover .backup-del { opacity: 0.7; }
        .backup-del:hover { opacity: 1 !important; }

        /* RESTORE PANEL */
        .restore-box {
            margin-top: 16px;
            padding: 16px;
            border: 1px solid rgba(255,60,110,.2);
            border-radius: 6px;
            background: rgba(255,60,110,.04);
            display: none;
        }

        .restore-box.visible { display: block; }

        .restore-box h4 {
            font-family: var(--sans);
            font-size: 12px;
            font-weight: 600;
            color: var(--danger);
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .restore-warning {
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 14px;
            line-height: 1.6;
        }

        .restore-warning strong { color: var(--danger); }

        /* TABS */
        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 16px;
        }

        .tab {
            padding: 8px 16px;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--muted);
            border: 1px solid transparent;
            border-bottom: none;
            transition: all .15s;
        }

        .tab.active {
            color: var(--accent);
            border-color: var(--border);
            background: var(--surface);
        }

        /* LOG */
        .log {
            background: #050508;
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 12px;
            font-size: 11px;
            color: var(--accent);
            max-height: 120px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
            margin-top: 12px;
            display: none;
        }
        .log.visible { display: block; }

        /* TOAST */
        .toast-wrap {
            position: fixed;
            bottom: 24px;
            right: 24px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 1000;
        }

        .toast {
            padding: 10px 16px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid;
            animation: slideIn .2s ease;
            pointer-events: none;
        }

        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }

        .toast.success { background: rgba(0,255,136,.1); border-color: rgba(0,255,136,.3); color: var(--accent); }
        .toast.error   { background: rgba(255,60,110,.1); border-color: rgba(255,60,110,.3); color: var(--danger); }

        /* EMPTY STATE */
        .empty {
            text-align: center;
            padding: 32px;
            color: var(--muted);
            font-size: 12px;
            line-height: 2;
        }

        .loader {
            display: inline-block;
            width: 12px; height: 12px;
            border: 2px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin .6s linear infinite;
            margin-right: 6px;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .section-title {
            font-family: var(--sans);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 12px;
        }

        .chip {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            background: rgba(0,255,136,.1);
            color: var(--accent);
            border: 1px solid rgba(0,255,136,.2);
        }

        /* RIGHT PANEL SECTIONS */
        .right-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 300px;
            color: var(--muted);
            font-size: 13px;
            text-align: center;
            line-height: 2;
        }

        .action-bar {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .db-big-name {
            font-family: var(--sans);
            font-size: 20px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 4px;
        }

        .db-meta {
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 16px;
        }

    </style>
</head>
<body>
<div class="app">

    <header>
        <div class="logo">
            <svg viewBox="0 0 24 24" fill="#000"><path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 2.18L20 8.5v7L12 19.82 4 15.5v-7l8-4.32z"/></svg>
        </div>
        <h1>Mr <span>SamyJoe</span> Back-up Manager</h1>
        <div class="status-dot">CONNECTED</div>
    </header>

    <div class="grid-2">

        <!-- LEFT: DB LIST -->
        <div>
            <div class="panel panel-databases">
                <div class="panel-header">
                    <span class="panel-title">Databases</span>
                    <span id="db-count" class="chip">0</span>
                </div>
                <div class="panel-body">
                    <div id="db-list"><div class="empty">Loading...</div></div>
                    <div class="add-form">
                        <div class="db-input-wrap">
                            <div id="db-suggestions" class="db-suggestions"></div>
                            <input type="text" id="new-db-input" placeholder="Search database..." autocomplete="off" />
                        </div>
                        <button class="btn btn-primary btn-sm" onclick="addDb()">+ Add</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: DETAIL -->
        <div>
            <div class="panel" id="right-panel">
                <div class="panel-header">
                    <span class="panel-title" id="right-title">Select a Database</span>
                </div>
                <div class="panel-body">
                    <div id="right-content">
                        <div class="right-empty">← Select a database from the list<br><span style="font-size:11px">to manage backups &amp; schedule</span></div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- TOASTS -->
<div class="toast-wrap" id="toasts"></div>

<script>
    let state = {
        dbs: [],
        schedules: {},
        selected: null,
        backups: [],
        selectedBackup: null,
    };

    const INTERVALS = [
        { val: 'none', label: 'Off' },
        { val: '2m', label: 'Every 2 minutes' },
        { val: '10m', label: 'Every 10 minutes' },
        { val: '1h',   label: 'Every 1 hour' },
        { val: '3h',   label: 'Every 3 hours' },
        { val: '6h',   label: 'Every 6 hours' },
        { val: '12h',  label: 'Every 12 hours' },
        { val: '24h',  label: 'Every day (2 AM)' },
        { val: 'weekly', label: 'Weekly (Sunday 2 AM)' },
    ];

    let _selectedFromSuggestion = false;
    let _searchTimer = null;

    // -------------------------------------------------------
    async function api(payload) {
        const fd = new FormData();
        for (const [k, v] of Object.entries(payload)) fd.append(k, v);
        const r = await fetch('back-up.php', { method: 'POST', body: fd });
        return r.json();
    }

    function toast(msg, type = 'success') {
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.textContent = msg;
        document.getElementById('toasts').appendChild(el);
        setTimeout(() => el.remove(), 3000);
    }

    // -------------------------------------------------------
    async function loadDbs() {
        const data = await api({ action: 'list_dbs' });
        state.dbs = data.dbs || [];
        state.schedules = data.schedules || {};
        renderDbList();
    }

    function renderDbList() {
        const el = document.getElementById('db-list');
        document.getElementById('db-count').textContent = state.dbs.length;
        if (!state.dbs.length) {
            el.innerHTML = '<div class="empty">No databases yet.<br>Add one below.</div>';
            return;
        }
        el.innerHTML = state.dbs.map(db => {
            const sched = state.schedules[db] || 'none';
            const schedLabel = sched === 'none' ? '' : INTERVALS.find(i => i.val === sched)?.label || sched;
            return `
        <div class="db-item ${state.selected === db ? 'active' : ''}" onclick="selectDb('${db}')">
            <div class="db-dot"></div>
            <div class="db-name">${db}</div>
            ${schedLabel ? `<div class="db-schedule">${schedLabel}</div>` : ''}
            <div class="db-remove" onclick="removeDb(event,'${db}')">×</div>
        </div>`;
        }).join('');
    }

    document.getElementById('new-db-input').addEventListener('input', function() {
        const q = this.value.trim();
        _selectedFromSuggestion = false;
        clearTimeout(_searchTimer);
        if (!q) { hideSuggestions(); return; }
        _searchTimer = setTimeout(() => fetchSuggestions(q), 250);
    });

    document.getElementById('new-db-input').addEventListener('keydown', e => {
        if (e.key === 'Enter') addDb();
        if (e.key === 'Escape') hideSuggestions();
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('.add-form')) hideSuggestions();
    });

    async function fetchSuggestions(q) {
        const r = await api({ action: 'search_dbs', q });
        const box = document.getElementById('db-suggestions');
        const existing = state.dbs;
        const results = (r.dbs || []).filter(d => !existing.includes(d));
        if (!results.length) { hideSuggestions(); return; }
        box.innerHTML = results.map(d =>
            `<div class="db-suggestion-item" onclick="pickSuggestion('${d}')">${d}</div>`
        ).join('');
        box.style.display = 'block';
    }

    function pickSuggestion(db) {
        document.getElementById('new-db-input').value = db;
        _selectedFromSuggestion = true;
        hideSuggestions();
    }

    function hideSuggestions() {
        document.getElementById('db-suggestions').style.display = 'none';
    }

    async function addDb() {
        const input = document.getElementById('new-db-input');
        const db = input.value.trim();
        if (!db) return;
        const r = await api({ action: 'add_db', db });
        if (r.error) { toast(r.error, 'error'); return; }
        input.value = '';
        _selectedFromSuggestion = false;
        hideSuggestions();
        await loadDbs();
        toast(`${db} added`);
    }

    async function removeDb(e, db) {
        e.stopPropagation();
        if (!confirm(`Remove "${db}" from the list? Backups on disk are NOT deleted.`)) return;
        await api({ action: 'remove_db', db });
        if (state.selected === db) { state.selected = null; renderRight(); }
        await loadDbs();
        toast(`${db} removed`);
    }

    // -------------------------------------------------------
    async function selectDb(db) {
        state.selected = db;
        state.selectedBackup = null;
        renderDbList();
        renderRight('loading');
        const data = await api({ action: 'list_backups', db });
        state.backups = data.backups || [];
        renderRight();
    }

    function renderRight(loading = false) {
        const db = state.selected;
        document.getElementById('right-title').textContent = db || 'Select a Database';
        const content = document.getElementById('right-content');

        if (!db) {
            content.innerHTML = '<div class="right-empty">← Select a database from the list<br><span style="font-size:11px">to manage backups &amp; schedule</span></div>';
            return;
        }

        if (loading) {
            content.innerHTML = '<div class="right-empty"><span class="loader"></span> Loading...</div>';
            return;
        }

        const sched = state.schedules[db] || 'none';
        const backups = state.backups;

        const schedOptions = INTERVALS.map(i =>
            `<option value="${i.val}" ${sched === i.val ? 'selected' : ''}>${i.label}</option>`
        ).join('');

        const backupRows = backups.length
            ? backups.map(b => `
            <div class="backup-row ${state.selectedBackup?.file === b.file ? 'selected' : ''}"
                 onclick="selectBackup(${JSON.stringify(b).replace(/"/g, '&quot;')})">
                <div style="flex:1">
                    <div class="backup-date">${b.date}</div>
                    <div class="backup-file">${b.file}</div>
                </div>
                <div class="backup-size">${b.size}</div>
                <div class="backup-del" onclick="deleteBackup(event,'${b.file}')">🗑</div>
            </div>`).join('')
            : '<div class="empty">No backups found for this database.</div>';

        const restoreSelected = state.selectedBackup
            ? `<div class="restore-box visible">
            <h4>⚠ Confirm Restore</h4>
            <div class="restore-warning">
                You are about to restore <strong>${db}</strong> from:<br>
                <code style="color:var(--accent3)">${state.selectedBackup.file}</code><br><br>
                <strong>This will DROP all tables</strong> and reimport from the backup.<br>
                This action cannot be undone.
            </div>
            <button class="btn btn-danger" onclick="doRestore()">🔁 Restore Now</button>
           </div>`
            : '';

        content.innerHTML = `
        <div class="db-big-name">${db}</div>
        <div class="db-meta">Schedule: <strong style="color:var(--text)">${
            sched === 'none' ? 'Not scheduled' : INTERVALS.find(i=>i.val===sched)?.label
        }</strong></div>

        <div class="section-title">Auto Backup Schedule</div>
        <div class="schedule-row">
            <div class="schedule-label">Run backup</div>
            <select id="sched-select" style="width:200px">${schedOptions}</select>
            <button class="btn btn-ghost btn-sm" onclick="saveSchedule()">Save</button>
        </div>

        <div style="margin-top:20px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between">
            <div class="section-title" style="margin:0">Available Backups</div>
            <button class="btn btn-primary btn-sm" onclick="backupNow()">
                ▶ Backup Now
            </button>
        </div>

        <div class="backups-list">${backupRows}</div>
        ${restoreSelected}
        <div class="log" id="action-log"></div>
    `;
    }

    async function saveSchedule() {
        const interval = document.getElementById('sched-select').value;
        const r = await api({ action: 'set_schedule', db: state.selected, interval });
        if (r.ok) {
            await loadDbs();
            toast('Schedule saved');
            renderRight();
        }
    }

    async function backupNow() {
        const db = state.selected;
        const log = document.getElementById('action-log');
        if (log) { log.classList.add('visible'); log.textContent = 'Running backup...'; }
        const r = await api({ action: 'backup_now', db });
        if (r.ok) {
            toast('Backup completed');
            const data = await api({ action: 'list_backups', db });
            state.backups = data.backups || [];
            renderRight();
            if (r.output) {
                const l = document.getElementById('action-log');
                if (l) { l.classList.add('visible'); l.textContent = r.output || 'Done.'; }
            }
        } else {
            toast(r.error || 'Backup failed', 'error');
        }
    }

    function selectBackup(backup) {
        state.selectedBackup = state.selectedBackup?.file === backup.file ? null : backup;
        renderRight();
    }

    async function deleteBackup(e, file) {
        e.stopPropagation();
        if (!confirm(`Delete backup: ${file}?`)) return;
        await api({ action: 'delete_backup', db: state.selected, file });
        state.selectedBackup = null;
        const data = await api({ action: 'list_backups', db: state.selected });
        state.backups = data.backups || [];
        renderRight();
        toast('Backup deleted');
    }

    async function doRestore() {
        if (!state.selectedBackup) return;
        const db = state.selected;
        const file = state.selectedBackup.file;
        if (!confirm(`FINAL WARNING: Drop all tables in "${db}" and restore from\n${file}?`)) return;

        const log = document.getElementById('action-log');
        if (log) { log.classList.add('visible'); log.textContent = 'Restoring... please wait.'; }

        const r = await api({ action: 'restore', db, file });

        if (r.ok) {
            toast('Restore completed successfully');
            if (log) { log.textContent = r.output || 'Done.'; }
            state.selectedBackup = null;
            renderRight();
        } else {
            toast('Restore failed — check log', 'error');
            if (log) { log.textContent = r.error || r.output || 'Unknown error'; }
        }
    }

    // -------------------------------------------------------
    loadDbs();
</script>
</body>
</html>
