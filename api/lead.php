<?php
/* Приём заявок с форм сайта -> data/leads.json + письмо на почту */
require __DIR__ . '/../admin/config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok' => false, 'error' => 'method'], 405);

$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ctype, 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = [];
} else {
    $data = $_POST;
}

function fld($d, $k) { return trim((string)($d[$k] ?? '')); }

/* ханипот: боты заполняют скрытое поле company */
if (fld($data, 'company') !== '') out(['ok' => true]);

$name    = mb_substr(fld($data, 'name'), 0, 100);
$phone   = mb_substr(fld($data, 'phone'), 0, 40);
$email   = mb_substr(fld($data, 'email'), 0, 120);
$source  = mb_substr(fld($data, 'source'), 0, 90);
$page    = mb_substr(fld($data, 'page'), 0, 120);
$message = mb_substr(fld($data, 'message'), 0, 1000);

/* минимальная валидация: телефон должен содержать хотя бы 5 цифр */
if (preg_match_all('/\d/', $phone) < 5) out(['ok' => false, 'error' => 'phone'], 422);

$lead = [
    'id'      => bin2hex(random_bytes(6)),
    'ts'      => date('c'),
    'name'    => $name,
    'phone'   => $phone,
    'email'   => $email,
    'source'  => $source !== '' ? $source : 'Заявка',
    'page'    => $page,
    'message' => $message,
    'status'  => 'new',
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua'      => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
];

/* дозапись в JSON с блокировкой файла */
$file = DATA_DIR . '/leads.json';
$fp = @fopen($file, 'c+');
if ($fp) {
    flock($fp, LOCK_EX);
    $cur = stream_get_contents($fp);
    $arr = json_decode($cur, true);
    if (!is_array($arr)) $arr = [];
    $arr[] = $lead;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
} else {
    out(['ok' => false, 'error' => 'store'], 500);
}

/* уведомление на почту (не критично, ошибки глушим) */
if (defined('NOTIFY_EMAIL') && NOTIFY_EMAIL) {
    $subj = '=?UTF-8?B?' . base64_encode('Новая заявка с сайта — ' . $lead['source']) . '?=';
    $body = "Новая заявка с сайта " . SITE_DOMAIN . "\n\n"
          . "Имя: {$name}\n"
          . "Телефон: {$phone}\n"
          . ($email ? "Email: {$email}\n" : '')
          . "Форма: {$lead['source']}\n"
          . ($page ? "Страница: {$page}\n" : '')
          . ($message ? "Сообщение: {$message}\n" : '')
          . "Время: " . date('d.m.Y H:i') . "\n\n"
          . "Смотреть в админке: https://" . SITE_DOMAIN . "/admin/";
    $headers = "From: site@" . SITE_DOMAIN . "\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "X-Mailer: PHP";
    @mail(NOTIFY_EMAIL, $subj, $body, $headers);
}

out(['ok' => true]);
