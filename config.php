<?php
// Minimal & Ultra-Lightweight Configuration

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DATA_DIR', __DIR__ . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.json');
define('LOG_FILE', DATA_DIR . '/webhooks.json');
define('MAX_LOGS', 20); // Keep max 20 entries for minimal RAM & disk usage
define('DEFAULT_PASSWORD', 'LakruDev@2026');

function get_config() {
    if (!file_exists(CONFIG_FILE)) {
        if (!is_dir(DATA_DIR)) {
            @mkdir(DATA_DIR, 0775, true);
        }
        $cfg = [
            'password_hash' => password_hash(DEFAULT_PASSWORD, PASSWORD_DEFAULT),
            'target_url' => '',
            'forwarding_enabled' => true,
            'secret_key' => '',
            'updated_at' => date('Y-m-d H:i:s')
        ];
        @file_put_contents(CONFIG_FILE, json_encode($cfg));
        return $cfg;
    }
    $cfg = json_decode(@file_get_contents(CONFIG_FILE), true);
    return is_array($cfg) ? $cfg : [];
}

function save_config($cfg) {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $cfg['updated_at'] = date('Y-m-d H:i:s');
    return @file_put_contents(CONFIG_FILE, json_encode($cfg));
}

function is_authenticated() {
    return !empty($_SESSION['dev_auth']) && $_SESSION['dev_auth'] === true;
}

function get_logs() {
    if (!file_exists(LOG_FILE)) return [];
    $logs = json_decode(@file_get_contents(LOG_FILE), true);
    return is_array($logs) ? $logs : [];
}

function add_log($entry) {
    $logs = get_logs();
    array_unshift($logs, $entry);
    if (count($logs) > MAX_LOGS) {
        $logs = array_slice($logs, 0, MAX_LOGS);
    }
    @file_put_contents(LOG_FILE, json_encode($logs));
}

function clear_logs() {
    if (file_exists(LOG_FILE)) {
        @file_put_contents(LOG_FILE, json_encode([]));
    }
}
