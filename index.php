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
                curl_close($ch);
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
                curl_close($ch);
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
        .card { background: #161b22; border: 1px solid #30363d; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; }
        .flex { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
        h1 { font-size: 1.15rem; font-weight: 700; color: #58a6ff; display: flex; align-items: center; gap: 0.5rem; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        input[type="text"], input[type="password"], input[type="url"] {
            width: 100%; background: #0d1117; border: 1px solid #30363d; border-radius: 6px; padding: 0.55rem 0.75rem; color: #c9d1d9; font-size: 0.9rem; margin-top: 0.35rem; outline: none;
        }
        input:focus { border-color: #58a6ff; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.5rem 0.9rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: pointer; border: 1px solid #30363d; background: #21262d; color: #c9d1d9; text-decoration: none; }
        .btn:hover { background: #30363d; }
        .btn-blue { background: #1f6feb; color: #fff; border-color: #1f6feb; }
        .btn-blue:hover { background: #388bfd; }
        .btn-sm { padding: 0.25rem 0.6rem; font-size: 0.78rem; }
        .btn-red { color: #f85149; border-color: rgba(248,81,73,0.3); background: rgba(248,81,73,0.1); }
        .badge { padding: 0.2rem 0.5rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-green { background: rgba(46,160,67,0.15); color: #3fb950; border: 1px solid rgba(46,160,67,0.3); }
        .badge-red { background: rgba(248,81,73,0.15); color: #f85149; border: 1px solid rgba(248,81,73,0.3); }
        .badge-gray { background: #21262d; color: #8b949e; }
        .badge-blue { background: rgba(56,189,248,0.15); color: #38bdf8; border: 1px solid rgba(56,189,248,0.3); }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 0.5rem; }
        th { text-align: left; padding: 0.6rem; color: #8b949e; border-bottom: 1px solid #30363d; font-size: 0.78rem; }
        td { padding: 0.65rem 0.6rem; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .hint { font-size: 0.78rem; color: #8b949e; margin-top: 0.3rem; }
        .alert { background: #1f6feb22; border: 1px solid #1f6feb55; color: #58a6ff; padding: 0.5rem 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.85rem; }
        .url-box { background: #0d1117; border: 1px solid #30363d; border-radius: 8px; padding: 0.75rem 1rem; margin-top: 0.5rem; }
        details pre { background: #0d1117; border: 1px solid #30363d; padding: 0.5rem; border-radius: 6px; font-size: 0.78rem; color: #79c0ff; overflow-x: auto; max-height: 200px; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all; }
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
        <div style="display: flex; gap: 0.5rem;">
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
                <button class="btn btn-sm" onclick="navigator.clipboard.writeText('https://dev.lakru.one/api/webhooks/adyen/reports'); alert('Copied Reports Webhook URL!');">📋 Copy</button>
            </div>

            <div class="url-box flex">
                <div>
                    <span class="badge badge-blue">Central Webhook</span>
                    <div class="mono" style="font-size: 0.9rem; color: #58a6ff; font-weight: 600; margin-top: 0.3rem;">https://dev.lakru.one/api/webhooks/adyen/central</div>
                    <div class="hint">&rarr; Relays to <code>(NGROK)/api/webhooks/adyen/central</code></div>
                </div>
                <button class="btn btn-sm" onclick="navigator.clipboard.writeText('https://dev.lakru.one/api/webhooks/adyen/central'); alert('Copied Central Webhook URL!');">📋 Copy</button>
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
                        <span class="badge badge-green">● Forwarding Active</span>
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
            <div style="font-weight: 600; font-size: 0.95rem;">Recent Webhooks (Last <?= MAX_LOGS ?>)</div>
            <?php if (count($logs) > 0): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn btn-sm btn-red" onclick="return confirm('Clear logs?')">Clear</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($logs)): ?>
            <div class="hint" style="text-align: center; padding: 2rem 0;">No webhooks received yet.</div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Incoming Path</th>
                            <th>Relay Status</th>
                            <th>Latency</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td class="mono" style="font-size: 0.8rem;"><?= htmlspecialchars($l['date'] . ' ' . $l['time']) ?></td>
                                <td><span class="badge badge-blue mono"><?= htmlspecialchars($l['path'] ?? '/webhook') ?></span></td>
                                <td>
                                    <?php if ($l['code'] >= 200 && $l['code'] < 300): ?>
                                        <span class="badge badge-green">HTTP <?= $l['code'] ?></span>
                                    <?php elseif ($l['code'] > 0): ?>
                                        <span class="badge badge-red">HTTP <?= $l['code'] ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-gray"><?= htmlspecialchars($l['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono hint"><?= $l['ms'] > 0 ? $l['ms'].'ms' : '-' ?></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="replay">
                                        <input type="hidden" name="dest" value="<?= htmlspecialchars($l['dest'] ?? '') ?>">
                                        <input type="hidden" name="payload" value="<?= htmlspecialchars($l['payload'] ?? '') ?>">
                                        <button type="submit" class="btn btn-sm" title="Re-send payload to Ngrok">🔁 Replay</button>
                                    </form>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="5" style="padding: 0.2rem 0.6rem 0.8rem; border-bottom: 1px solid #30363d;">
                                    <details>
                                        <summary class="hint" style="cursor: pointer;">
                                            Dest: <code class="mono" style="color:#58a6ff;"><?= htmlspecialchars($l['dest'] ?? 'N/A') ?></code> (<?= strlen($l['payload'] ?? '') ?> bytes)
                                        </summary>
                                        <pre><?= htmlspecialchars($l['payload'] ?: '(Empty Payload)') ?></pre>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>
</body>
</html>
