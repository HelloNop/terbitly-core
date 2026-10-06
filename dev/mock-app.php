<?php

/**
 * A stand-in for the Publisher App, only for trying the plugin locally. Not part of the plugin.
 *
 *   php -S 127.0.0.1:8899 plugins/generic/publisherApp/dev/mock-app.php
 *
 * Plugin settings in OJS: address http://127.0.0.1:8899, token = DEV_TOKEN below.
 * Received submissions are kept in dev/received.json. Open
 * http://127.0.0.1:8899/dev/pay/{ojs id} to mark the submission fee as paid and see the tab change.
 */

const DEV_TOKEN = 'dev01.b364b1fa6cea116e453fcf6cee5445fafb7f0ffb';
const STORE = __DIR__ . '/received.json';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
$store = is_file(STORE) ? json_decode(file_get_contents(STORE), true) : [];

function reply(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if (preg_match('#^/dev/pay/(\d+)$#', $path, $m) && isset($store[$m[1]])) {
    $store[$m[1]]['fee_paid'] = true;
    file_put_contents(STORE, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    reply(200, ['marked_paid' => (int) $m[1]]);
}

// The signature check the real app will do: right key, fresh timestamp, matching HMAC.
[$keyId, $secret] = explode('.', DEV_TOKEN, 2);
$timestamp = $_SERVER['HTTP_X_PUBLISHER_TIMESTAMP'] ?? '';
$expected = hash_hmac('sha256', $timestamp . "\n" . $method . "\n" . $path . "\n" . $body, $secret);
if (($_SERVER['HTTP_X_PUBLISHER_KEY'] ?? '') !== $keyId
    || abs(time() - (int) $timestamp) > 300
    || ! hash_equals($expected, $_SERVER['HTTP_X_PUBLISHER_SIGNATURE'] ?? '')) {
    error_log("rejected {$method} {$path}: bad signature");
    reply(401, ['message' => 'Invalid signature.']);
}

if ($method === 'POST' && $path === '/api/ojs/submissions') {
    $data = json_decode($body, true);
    $id = (string) $data['ojs_submission_id'];
    $store[$id] = ['received_at' => date('c'), 'fee_paid' => false, 'payload' => $data];
    file_put_contents(STORE, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    error_log("received submission {$id}, referral " . ($data['referral_code'] ?? 'none'));
    reply(201, ['number' => 'JAS-' . $id]);
}

if ($method === 'GET' && preg_match('#^/api/ojs/submissions/(\d+)$#', $path, $m)) {
    if (! isset($store[$m[1]])) {
        reply(200, ['found' => false]);
    }
    $paid = $store[$m[1]]['fee_paid'];

    reply(200, [
        'found' => true,
        'number' => 'JAS-' . $m[1],
        'invoices' => [[
            'label' => 'Submission fee',
            'number' => $paid ? 'INV/2026/0042' : null,
            'amount' => 'Rp150.000',
            'status' => $paid ? 'Paid' : 'Unpaid',
            'pay_url' => 'http://127.0.0.1:8899/pay/demo-token',
        ]],
        'documents' => $paid ? [['label' => 'Receipt', 'number' => 'RCP/2026/0042', 'url' => 'http://127.0.0.1:8899/receipt/demo.pdf']] : [],
    ]);
}

reply(404, ['message' => 'Not found.']);
