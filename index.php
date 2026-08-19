<?php
require_once __DIR__ . '/config.php';

$config = get_config();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'login') {
        $pwd = $_POST['pwd'] ?? '';
        if (password_verify($pwd, $config['password_hash'])) {
            $_SESSION['dev_auth'] = true;
            header('Location: /');
            exit;
        }
        $msg = 'Invalid password.';
    }

    if (is_authenticated()) {
        if ($action === 'save') {
            $rawTarget = trim($_POST['target_url'] ?? '');
            $config['target_url'] = $rawTarget;
            $config['forwarding_enabled'] = isset($_POST['forwarding_enabled']);
            $config['secret_key'] = trim($_POST['secret_key'] ?? '');
            save_config($config);
            $msg = 'Settings saved!';
        } elseif ($action === 'clear') {
            clear_logs();
            $msg = 'Logs cleared.';
        } elseif ($action === 'test') {
            $target = trim($config['target_url'] ?? '');
            $testPath = $_POST['test_path'] ?? '/api/webhooks/adyen/reports';
            if (!$target) {
                $msg = 'Error: Enter Ngrok URL first.';
            } else {
                $parsed = parse_url($target);
                $baseHost = (!empty($parsed['scheme']) && !empty($parsed['host'])) ? ($parsed['scheme'] . '://' . $parsed['host'] . (!empty($parsed['port']) ? ':' . $parsed['port'] : '')) : rtrim($target, '/');
                $testUrl = $baseHost . $testPath;

                $ch = curl_init($testUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['test' => true, 'event' => 'PING', 'time' => date('c')]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Forwarded-By: dev.lakru.one-TestPing']);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $res = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                unset($ch);
                $msg = $err ? "Ping Failed: $err" : "Ping to $testUrl: HTTP $code ($res)";
            }
        } elseif ($action === 'replay') {
            $dest = trim($_POST['dest'] ?? '');
            $payload = $_POST['payload'] ?? '';
            if (!$dest) {
                $msg = 'Error: No destination configured.';
            } else {
                $ch = curl_init($dest);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 6);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Forwarded-By: dev.lakru.one-Replay']);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $res = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                unset($ch);
                $msg = $err ? "Replay Failed: $err" : "Replayed to $dest: HTTP $code";
            }
        }
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

$logs = is_authenticated() ? get_logs() : [];

function parse_adyen_summary(string $payloadStr): ?array {
    if (empty($payloadStr)) return null;
    $data = json_decode($payloadStr, true);
    if (!is_array($data)) return null;

    $info = [];
    if (!empty($data['notificationItems'][0]['NotificationRequestItem'])) {
        $item = $data['notificationItems'][0]['NotificationRequestItem'];
        $info['type'] = 'adyen_event';
        $info['eventCode'] = $item['eventCode'] ?? 'UNKNOWN';
        $info['success'] = isset($item['success']) ? ($item['success'] === 'true' || $item['success'] === true) : null;
        $info['merchantReference'] = $item['merchantReference'] ?? '';
        $info['pspReference'] = $item['pspReference'] ?? '';
        $info['paymentMethod'] = $item['paymentMethod'] ?? ($item['additionalData']['paymentMethodVariant'] ?? '');
        $info['cardSummary'] = $item['additionalData']['cardSummary'] ?? '';
        if (isset($item['amount']['value']) && isset($item['amount']['currency'])) {
            $amountVal = $item['amount']['value'] / 100;
            $info['amount'] = $item['amount']['currency'] . ' ' . number_format($amountVal, 2);
        }
    } elseif (!empty($data['type'])) {
        $info['type'] = 'report';
        $info['eventCode'] = $data['type'];
        $info['merchantReference'] = $data['data']['fileName'] ?? $data['data']['id'] ?? '';
        $info['success'] = true;
    }
    return $info;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>dev.lakru.one | Webhook Relay</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0c1017; color: #e6edf3; min-height: 100vh; padding: 1.5rem 1rem; }
        .wrap { max-width: 960px; margin: 0 auto; }
        .card { background: #161b22; border: 1px solid #30363d; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .flex { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
        h1 { font-size: 1.15rem; font-weight: 700; color: #58a6ff; display: flex; align-items: center; gap: 0.5rem; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        input[type="text"], input[type="password"], input[type="url"] {
            width: 100%; background: #0d1117; border: 1px solid #30363d; border-radius: 6px; padding: 0.55rem 0.75rem; color: #c9d1d9; font-size: 0.9rem; margin-top: 0.35rem; outline: none; transition: border-color 0.2s;
        }
        input:focus { border-color: #58a6ff; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.5rem 0.9rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: pointer; border: 1px solid #30363d; background: #21262d; color: #c9d1d9; text-decoration: none; transition: all 0.15s; }
        .btn:hover { background: #30363d; color: #fff; }
        .btn-blue { background: #1f6feb; color: #fff; border-color: #1f6feb; }
        .btn-blue:hover { background: #388bfd; }
        .btn-sm { padding: 0.25rem 0.6rem; font-size: 0.78rem; }
        .btn-red { color: #f85149; border-color: rgba(248,81,73,0.3); background: rgba(248,81,73,0.1); }
        .btn-red:hover { background: rgba(248,81,73,0.25); color: #ff7b72; }
        .badge { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.55rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-green { background: rgba(46,160,67,0.15); color: #3fb950; border: 1px solid rgba(46,160,67,0.3); }
        .badge-red { background: rgba(248,81,73,0.15); color: #f85149; border: 1px solid rgba(248,81,73,0.3); }
        .badge-gray { background: #21262d; color: #8b949e; border: 1px solid #30363d; }
        .badge-blue { background: rgba(56,189,248,0.15); color: #38bdf8; border: 1px solid rgba(56,189,248,0.3); }
        .badge-purple { background: rgba(163,113,247,0.15); color: #d2a8ff; border: 1px solid rgba(163,113,247,0.3); }
        .badge-amber { background: rgba(210,153,34,0.15); color: #f0883e; border: 1px solid rgba(210,153,34,0.3); }
        .hint { font-size: 0.78rem; color: #8b949e; margin-top: 0.3rem; }
        .alert { background: #1f6feb22; border: 1px solid #1f6feb55; color: #58a6ff; padding: 0.5rem 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.85rem; }
        .url-box { background: #0d1117; border: 1px solid #30363d; border-radius: 8px; padding: 0.75rem 1rem; margin-top: 0.5rem; }
        
        /* Webhook item card */
        .webhook-card { background: #0d1117; border: 1px solid #30363d; border-radius: 8px; margin-top: 0.85rem; overflow: hidden; }
        .webhook-header { padding: 0.75rem 1rem; background: #161b22; border-bottom: 1px solid #30363d; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
        .webhook-chips { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; padding: 0.5rem 1rem; background: rgba(22,27,34,0.6); border-bottom: 1px solid #21262d; font-size: 0.8rem; }
        .chip { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.15rem 0.5rem; border-radius: 4px; background: #21262d; border: 1px solid #30363d; font-size: 0.75rem; color: #c9d1d9; }
        .chip-label { color: #8b949e; }
        .chip-val { font-weight: 600; color: #58a6ff; }
        .webhook-body { padding: 0.75rem 1rem; }
        
        /* JSON Viewer */
        .json-container { position: relative; margin-top: 0.4rem; }
        .json-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem; }
        .json-box { background: #05080c; border: 1px solid #30363d; border-radius: 6px; padding: 0.75rem 1rem; font-size: 0.8rem; line-height: 1.45; overflow-x: auto; max-height: 320px; white-space: pre-wrap; word-break: break-word; color: #c9d1d9; }
        
        /* Syntax highlighting colors */
        .jk { color: #79c0ff; font-weight: 600; } /* Key */
        .js { color: #7ee787; }                   /* String */
        .jn { color: #ffa657; }                   /* Number */
        .jb { color: #d2a8ff; font-weight: 600; } /* Boolean */
        .jl { color: #ff7b72; font-weight: 600; } /* Null */
        
        .pulse-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: #3fb950; animation: pulse 1.8s infinite; }
        @keyframes pulse { 0% { opacity: 0.4; } 50% { opacity: 1; } 100% { opacity: 0.4; } }
    </style>
</head>
<body>
<div class="wrap">
<?php if (!is_authenticated()): ?>
    <!-- LOGIN -->
    <div style="max-width: 360px; margin: 4rem auto;" class="card">
        <h1 style="margin-bottom: 0.5rem;">⚡ dev.lakru.one</h1>
        <p class="hint" style="margin-bottom: 1rem;">Webhook Relay & Forwarder</p>
        <?php if ($msg): ?><div class="alert"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="login">
            <label class="hint" style="font-weight: 600; color: #c9d1d9;">Password</label>
            <input type="password" name="pwd" placeholder="Enter password" autofocus required>
            <button type="submit" class="btn btn-blue" style="width: 100%; margin-top: 1rem;">Unlock</button>
        </form>
        <div class="hint" style="margin-top: 1rem; text-align: center;">Initial: <code class="mono" style="color:#58a6ff;">LakruDev@2026</code></div>
    </div>
<?php else: ?>
    <!-- DASHBOARD -->
    <div class="flex" style="margin-bottom: 1rem;">
        <h1>⚡ dev.lakru.one <span style="font-size: 0.75rem; font-weight: normal; color: #8b949e;">Multi-Webhook Relay</span></h1>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.78rem; color: #8b949e; cursor: pointer; user-select: none;">
                <input type="checkbox" id="autoRefreshToggle" style="accent-color: #1f6feb;"> Auto-refresh (10s)
            </label>
            <a href="/?logout=1" class="btn btn-sm">Logout</a>
        </div>
    </div>

    <?php if ($msg): ?><div class="alert"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <!-- ENDPOINT INFO -->
    <div class="card">
        <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.4rem;">📡 Adyen Webhook Endpoints (Configure in Adyen)</div>
        <div class="hint" style="margin-bottom: 0.75rem;">Set these endpoints in Adyen Customer Area. They automatically relay to your active Ngrok tunnel:</div>

        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
            <div class="url-box flex">
                <div>
                    <span class="badge badge-blue">Reports Webhook</span>
                    <div class="mono" style="font-size: 0.9rem; color: #58a6ff; font-weight: 600; margin-top: 0.3rem;">https://dev.lakru.one/api/webhooks/adyen/reports</div>
                    <div class="hint">&rarr; Relays to <code>(NGROK)/api/webhooks/adyen/reports</code></div>
                </div>
                <button class="btn btn-sm" onclick="copyText('https://dev.lakru.one/api/webhooks/adyen/reports', this)">📋 Copy</button>
            </div>

            <div class="url-box flex">
                <div>
                    <span class="badge badge-blue">Central Webhook</span>
                    <div class="mono" style="font-size: 0.9rem; color: #58a6ff; font-weight: 600; margin-top: 0.3rem;">https://dev.lakru.one/api/webhooks/adyen/central</div>
                    <div class="hint">&rarr; Relays to <code>(NGROK)/api/webhooks/adyen/central</code></div>
                </div>
                <button class="btn btn-sm" onclick="copyText('https://dev.lakru.one/api/webhooks/adyen/central', this)">📋 Copy</button>
            </div>
        </div>
    </div>

    <!-- CONFIGURATION -->
    <div class="card">
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <div style="margin-bottom: 1rem;">
                <label style="font-size: 0.88rem; font-weight: 600;">Active Ngrok URL</label>
                <input type="url" name="target_url" class="mono" placeholder="https://xxxx-xx-xx.ngrok-free.app" value="<?= htmlspecialchars($config['target_url'] ?? '') ?>">
                <div class="hint">Enter your Ngrok host URL (e.g. <code>https://d276-112-134-152-76.ngrok-free.app</code>).</div>
            </div>

            <div class="flex" style="margin-bottom: 1rem;">
                <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; cursor: pointer;">
                    <input type="checkbox" name="forwarding_enabled" value="1" <?= !empty($config['forwarding_enabled']) ? 'checked' : '' ?> style="accent-color: #1f6feb;">
                    <span>Enable Forwarding to Ngrok</span>
                </label>
                <div>
                    <?php if (!empty($config['target_url']) && !empty($config['forwarding_enabled'])): ?>
                        <span class="badge badge-green"><span class="pulse-dot"></span> Forwarding Active</span>
                    <?php else: ?>
                        <span class="badge badge-gray">● Forwarding Paused</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex">
                <button type="submit" class="btn btn-blue">💾 Save Ngrok URL</button>
                <div style="display: flex; gap: 0.4rem;">
                    <button type="submit" formaction="/" name="action" value="test" class="btn btn-sm">🧪 Test /reports</button>
                    <button type="submit" formaction="/" name="action" value="test" onclick="this.form.test_path.value='/api/webhooks/adyen/central'" class="btn btn-sm">🧪 Test /central</button>
                    <input type="hidden" name="test_path" value="/api/webhooks/adyen/reports">
                </div>
            </div>
        </form>
    </div>

    <!-- RECENT WEBHOOKS -->
    <div class="card">
        <div class="flex" style="margin-bottom: 0.5rem;">
            <div>
                <div style="font-weight: 600; font-size: 0.95rem;">Recent Webhooks (Max <?= MAX_LOGS ?>)</div>
                <div class="hint">Auto-cleared after 3 minutes &bull; Keeping latest <?= MAX_LOGS ?> events</div>
            </div>
            <?php if (count($logs) > 0): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn btn-sm btn-red" onclick="return confirm('Clear logs?')">Clear All</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($logs)): ?>
            <div class="hint" style="text-align: center; padding: 2.5rem 0; background: #0d1117; border-radius: 8px; border: 1px dashed #30363d; margin-top: 0.75rem;">
                No active webhooks. Incoming webhooks will appear here and auto-clear after 3 minutes.
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <?php foreach ($logs as $idx => $l): 
                    $summary = parse_adyen_summary($l['payload'] ?? '');
                    $ts = $l['timestamp'] ?? (isset($l['date'], $l['time']) ? strtotime($l['date'] . ' ' . date('Y') . ' ' . $l['time']) : time());
                    $ageSec = max(0, time() - $ts);
                    $remainingSec = max(0, MAX_LOG_AGE_SECONDS - $ageSec);
                ?>
                    <div class="webhook-card" id="webhook-<?= htmlspecialchars($l['id'] ?? $idx) ?>">
                        <!-- Header -->
                        <div class="webhook-header">
                            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                <span class="badge badge-blue mono"><?= htmlspecialchars($l['path'] ?? '/webhook') ?></span>
                                <?php if ($l['code'] >= 200 && $l['code'] < 300): ?>
                                    <span class="badge badge-green">HTTP <?= $l['code'] ?></span>
                                <?php elseif ($l['code'] > 0): ?>
                                    <span class="badge badge-red">HTTP <?= $l['code'] ?></span>
                                <?php else: ?>
                                    <span class="badge badge-gray"><?= htmlspecialchars($l['status'] ?? 'N/A') ?></span>
                                <?php endif; ?>
                                <span class="mono hint" style="font-size: 0.75rem;"><?= $l['ms'] > 0 ? $l['ms'].'ms' : '' ?></span>
                            </div>

                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <span class="hint mono" style="font-size: 0.75rem;" title="<?= htmlspecialchars($l['date'] . ' ' . $l['time']) ?>">
                                    <?= htmlspecialchars($l['time']) ?> (expires in <?= gmdate("i\m s\s", $remainingSec) ?>)
                                </span>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="replay">
                                    <input type="hidden" name="dest" value="<?= htmlspecialchars($l['dest'] ?? '') ?>">
                                    <input type="hidden" name="payload" value="<?= htmlspecialchars($l['payload'] ?? '') ?>">
                                    <button type="submit" class="btn btn-sm" title="Re-send payload to Ngrok">🔁 Replay</button>
                                </form>
                            </div>
                        </div>

                        <!-- Summary Chips (if recognized webhook) -->
                        <?php if ($summary): ?>
                            <div class="webhook-chips">
                                <?php if (!empty($summary['eventCode'])): ?>
                                    <span class="badge badge-purple"><?= htmlspecialchars($summary['eventCode']) ?></span>
                                <?php endif; ?>
                                <?php if (isset($summary['success'])): ?>
                                    <?php if ($summary['success']): ?>
                                        <span class="badge badge-green">✓ Success</span>
                                    <?php else: ?>
                                        <span class="badge badge-red">✗ Failed / Refused</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($summary['amount'])): ?>
                                    <span class="chip"><span class="chip-label">Amount:</span> <span class="chip-val" style="color:#7ee787;"><?= htmlspecialchars($summary['amount']) ?></span></span>
                                <?php endif; ?>
                                <?php if (!empty($summary['merchantReference'])): ?>
                                    <span class="chip"><span class="chip-label">Ref:</span> <span class="chip-val"><?= htmlspecialchars($summary['merchantReference']) ?></span></span>
                                <?php endif; ?>
                                <?php if (!empty($summary['cardSummary'])): ?>
                                    <span class="chip"><span class="chip-label">Card:</span> <span class="chip-val"><?= htmlspecialchars(strtoupper($summary['paymentMethod'] ?? '')) ?> •••• <?= htmlspecialchars($summary['cardSummary']) ?></span></span>
                                <?php endif; ?>
                                <?php if (!empty($summary['pspReference'])): ?>
                                    <span class="chip"><span class="chip-label">PSP:</span> <code class="mono" style="font-size:0.72rem; color:#8b949e;"><?= htmlspecialchars($summary['pspReference']) ?></code></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Body & JSON Viewer -->
                        <div class="webhook-body">
                            <details open>
                                <summary class="hint" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; user-select: none;">
                                    <span>Dest: <code class="mono" style="color:#58a6ff;"><?= htmlspecialchars($l['dest'] ?? 'N/A') ?></code> (<?= strlen($l['payload'] ?? '') ?> bytes)</span>
                                    <span style="font-size:0.72rem; color:#58a6ff;">Toggle View</span>
                                </summary>
                                <div class="json-container">
                                    <div class="json-toolbar">
                                        <span class="hint mono" style="font-size: 0.72rem;">Formatted JSON Payload</span>
                                        <button class="btn btn-sm" onclick="copyJson(this)" data-payload="<?= htmlspecialchars($l['payload'] ?? '') ?>">📋 Copy JSON</button>
                                    </div>
                                    <pre class="json-box mono json-viewer"><?= htmlspecialchars($l['payload'] ?: '(Empty Payload)') ?></pre>
                                </div>
                            </details>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<script>
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerText;
        btn.innerText = '✓ Copied!';
        btn.style.borderColor = '#3fb950';
        setTimeout(() => { btn.innerText = orig; btn.style.borderColor = ''; }, 1500);
    });
}

function copyJson(btn) {
    const raw = btn.getAttribute('data-payload') || '';
    try {
        const parsed = JSON.parse(raw);
        const pretty = JSON.stringify(parsed, null, 2);
        copyText(pretty, btn);
    } catch(e) {
        copyText(raw, btn);
    }
}

// Pretty-print and highlight JSON
function highlightJson(jsonStr) {
    if (!jsonStr || jsonStr === '(Empty Payload)') return '<span style="color:#8b949e;">(Empty Payload)</span>';
    let formatted = jsonStr;
    try {
        const parsed = typeof jsonStr === 'string' ? JSON.parse(jsonStr) : jsonStr;
        formatted = JSON.stringify(parsed, null, 2);
    } catch(e) {
        // Not valid JSON, return escaped
        return escapeHtml(jsonStr);
    }

    formatted = escapeHtml(formatted);

    return formatted.replace(
        /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
        function (match) {
            let cls = 'jn'; // number
            if (/^"/.test(match)) {
                if (/:$/.test(match)) {
                    cls = 'jk'; // key
                } else {
                    cls = 'js'; // string
                }
            } else if (/true|false/.test(match)) {
                cls = 'jb'; // boolean
            } else if (/null/.test(match)) {
                cls = 'jl'; // null
            }
            return '<span class="' + cls + '">' + match + '</span>';
        }
    );
}

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Render all JSON viewers on load
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.json-viewer').forEach(el => {
        const raw = el.innerText.trim();
        el.innerHTML = highlightJson(raw);
    });

    // Auto-refresh handling
    const refreshToggle = document.getElementById('autoRefreshToggle');
    if (refreshToggle) {
        const savedPref = localStorage.getItem('auto_refresh') === 'true';
        refreshToggle.checked = savedPref;
        
        let intervalId = null;
        function setupTimer(enable) {
            if (intervalId) clearInterval(intervalId);
            if (enable) {
                intervalId = setInterval(() => {
                    window.location.reload();
                }, 10000);
            }
        }
        
        setupTimer(refreshToggle.checked);
        
        refreshToggle.addEventListener('change', () => {
            localStorage.setItem('auto_refresh', refreshToggle.checked);
            setupTimer(refreshToggle.checked);
        });
    }
});
</script>
</body>
</html>
