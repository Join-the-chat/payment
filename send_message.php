<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

// 1) Configure your Telegram bot token and target chat ID
// - Create a bot using @BotFather and copy the bot token.
// - Get your chat ID by sending a message to your bot, then visit:
//   https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates
//   and read the "chat":{"id":...} value from the JSON.
$BOT_TOKEN = 'REPLACE_WITH_YOUR_BOT_TOKEN';
$CHAT_ID   = 'REPLACE_WITH_YOUR_CHAT_ID';

// 2) Optional: prefix in the message title
$TITLE_PREFIX = 'Website Form';

// Do not send sensitive payment fields


function isPost(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function sanitizeText(?string $v): string {
    $v = $v ?? '';
    $v = trim($v);
    $v = strip_tags($v);
    return $v;
}

function escapeHtml(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function wantsJson(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return stripos($accept, 'application/json') !== false || strtolower($xhr) === 'xmlhttprequest';
}

function respond(bool $ok, string $message, array $extra = []): void {
    if (wantsJson()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        echo "<!doctype html><meta charset='utf-8'><title>Telegram Submission</title>";
        echo "<p>" . escapeHtml($message) . "</p>";
    }
    exit;
}

function sendTelegram(string $token, string|int $chatId, string $text, array $params = []): array {
    $endpoint = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = array_merge([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $params);

    // Prefer cURL; fallback to file_get_contents
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'error' => $err ?: 'cURL error'];
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload),
                'timeout' => 10,
            ],
        ]);
        $resp = @file_get_contents($endpoint, false, $ctx);
        if ($resp === false) {
            return ['ok' => false, 'error' => 'HTTP request failed'];
        }
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Invalid response from Telegram'];
    }
    return ['ok' => (bool)($json['ok'] ?? false), 'response' => $json, 'error' => $json['description'] ?? null];
}

function chunkAndSend(string $token, string|int $chatId, string $message): array {
    // Telegram sendMessage max is ~4096 chars; chunk conservatively at 3500
    $maxLen = 3500;
    $lines = preg_split('/\r\n|\r|\n/', $message);
    $chunks = [];
    $buffer = '';

    foreach ($lines as $line) {
        $candidate = ($buffer === '' ? $line : $buffer . "\n" . $line);
        if (strlen($candidate) > $maxLen) {
            $chunks[] = $buffer;
            $buffer = $line;
        } else {
            $buffer = $candidate;
        }
    }
    if ($buffer !== '') {
        $chunks[] = $buffer;
    }
    $lastError = null;
    foreach ($chunks as $part) {
        $res = sendTelegram($token, $chatId, $part);
        if (!$res['ok']) {
            $lastError = $res['error'] ?? 'Unknown error';
            break;
        }
    }
    return ['ok' => $lastError === null, 'error' => $lastError];
}

if (!isPost()) {
    respond(false, 'Invalid request method.');
}

if ($BOT_TOKEN === 'REPLACE_WITH_YOUR_BOT_TOKEN' || $CHAT_ID === 'REPLACE_WITH_YOUR_CHAT_ID') {
    respond(false, 'Configure $BOT_TOKEN and $CHAT_ID in send_telegram.php.');
}

// Collect sanitized fields, excluding blocked
$fields = [];
$sensitiveOmitted = false;
foreach ($_POST as $key => $value) {
    $key = (string)$key;
    if (in_array($key, $BLOCK_FIELDS, true)) {
        $sensitiveOmitted = true;
        continue;
    }
    $safeKey = preg_replace('/[^a-z0-9_\- ]/i', '', $key);
    $safeVal = sanitizeText(is_array($value) ? implode(', ', $value) : (string)$value);
    $fields[$safeKey] = $safeVal;
}

// Build message (HTML escaped)
$title = escapeHtml(trim($TITLE_PREFIX . ' - New Submission'));
$meta  = [
    'Time'       => gmdate('Y-m-d H:i:s') . ' UTC',
    'IP'         => $_SERVER['REMOTE_ADDR'] ?? '',
    'User-Agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'Referrer'   => $_SERVER['HTTP_REFERER'] ?? '',
];

// Compose text
$lines = [];
$lines[] = "<b>{$title}</b>";
$lines[] = '';
$lines[] = '<b>Meta</b>';
foreach ($meta as $k => $v) {
    $lines[] = '<b>' . escapeHtml($k) . ':</b> ' . escapeHtml($v);
}
$lines[] = '';
$lines[] = '<b>Fields</b>';
if ($sensitiveOmitted) {
    $lines[] = '(Note: Sensitive payment fields were omitted.)';
}
if (empty($fields)) {
    $lines[] = '(No fields submitted.)';
} else {
    foreach ($fields as $k => $v) {
        $lines[] = '<b>' . escapeHtml($k) . ':</b> ' . escapeHtml($v);
    }
}
$message = implode("\n", $lines);

// Send to Telegram
$result = chunkAndSend($BOT_TOKEN, $CHAT_ID, $message);

if ($result['ok']) {
    respond(true, 'Sent to Telegram.');
} else {
    respond(false, 'Failed to send to Telegram.', ['error' => $result['error']]);
}