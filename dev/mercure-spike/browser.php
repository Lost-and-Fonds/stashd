<?php

declare(strict_types=1);

require __DIR__ . '/fixture.php';

$config = spike_config();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$password = $_POST['password'] ?? '';
if (! is_string($password) || ! hash_equals($config['password'], $password)) {
    http_response_code(401);
    exit('<!doctype html><html lang="en"><title>Restricted Mercure fixture</title><form method="post"><label>Test password <input type="password" name="password" required></label><button>Connect</button></form></html>');
}
if (($_SERVER['HTTPS'] ?? '') !== 'on') {
    http_response_code(400);
    exit('HTTPS required');
}
setcookie('mercureAuthorization', spike_token($config, 'subscribe'), [
    'expires' => time() + 300,
    'path' => '/.well-known/mercure',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en"><meta charset="utf-8"><title>Synthetic Mercure spike</title>
<h1>Synthetic Mercure spike</h1>
<p>Private test topic only. Publish from a separate CLI process. Reload to renew the five minute cookie.</p>
<p id="status">Connecting</p><button id="disconnect">Disconnect</button> <button id="connect">Reconnect</button>
<pre id="events"></pre>
<script>
let source;
window.received = [];
window.opens = 0;
window.failures = 0;
function connect() {
    source?.close();
    source = new EventSource('/.well-known/mercure?topic=https%3A%2F%2Fcore.test%2Fspike%2Fsynthetic');
    source.onopen = () => { window.opens++; document.querySelector('#status').textContent = 'Connected'; };
    source.onerror = () => { window.failures++; document.querySelector('#status').textContent = 'Reconnecting'; };
    source.onmessage = event => {
        window.received.push({id: event.lastEventId, data: JSON.parse(event.data), at: Date.now()});
        document.querySelector('#events').textContent += event.data + '\n';
    };
}
document.querySelector('#disconnect').onclick = () => { source.close(); document.querySelector('#status').textContent = 'Disconnected'; };
document.querySelector('#connect').onclick = connect;
connect();
</script></html>
