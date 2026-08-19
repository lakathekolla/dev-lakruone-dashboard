<?php
// Minimal & Ultra-Lightweight Configuration

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DATA_DIR', __DIR__ . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.json');
define('LOG_FILE', DATA_DIR . '/webhooks.json');
define('MAX_LOGS', 4); // Keep maximum 4 entries
define('MAX_LOG_AGE_SECONDS', 180); // Auto-clear entries older than 3 minutes (180s)
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

function save_config(array $cfg) {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $cfg['updated_at'] = date('Y-m-d H:i:s');
    return @file_put_contents(CONFIG_FILE, json_encode($cfg));
}

function is_authenticated(): bool {
    return !empty($_SESSION['dev_auth']) && $_SESSION['dev_auth'] === true;
}

function prune_logs_array(array $logs): array {
    $now = time();
    $cutoff = $now - MAX_LOG_AGE_SECONDS;
    
    $validLogs = [];
    foreach ($logs as $l) {
        $ts = null;
        if (!empty($l['timestamp'])) {
            $ts = (int)$l['timestamp'];
        } elseif (!empty($l['date']) && !empty($l['time'])) {
            $parsed = @strtotime($l['date'] . ' ' . date('Y') . ' ' . $l['time']);
            if ($parsed !== false) {
                $ts = $parsed;
            }
        }
        
        // If timestamp cannot be determined or is older than 3 minutes, discard
        if ($ts !== null && $ts < $cutoff) {
            continue;
        }
        
        $validLogs[] = $l;
    }
    
    if (count($validLogs) > MAX_LOGS) {
        $validLogs = array_slice($validLogs, 0, MAX_LOGS);
    }
    
    return $validLogs;
}

function get_logs(): array {
    if (!file_exists(LOG_FILE)) return [];
    $raw = @file_get_contents(LOG_FILE);
    $logs = json_decode($raw, true);
    if (!is_array($logs)) return [];
    
    $pruned = prune_logs_array($logs);
    // If items were pruned or count changed, sync back to disk
    if (count($pruned) !== count($logs)) {
        @file_put_contents(LOG_FILE, json_encode($pruned));
    }
    return $pruned;
}

function add_log(array $entry): void {
    if (!isset($entry['timestamp'])) {
        $entry['timestamp'] = time();
    }
    $logs = get_logs();
    array_unshift($logs, $entry);
    $logs = prune_logs_array($logs);
    @file_put_contents(LOG_FILE, json_encode($logs));
}

function clear_logs() {
    if (file_exists(LOG_FILE)) {
        @file_put_contents(LOG_FILE, json_encode([]));
    }
}
