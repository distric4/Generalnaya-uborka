<?php
/* Админка «Генеральная уборка» — заявки. Вход: один владелец. */
require __DIR__ . '/config.php';
session_start();

$PASS_FILE  = DATA_DIR . '/admin.json';
$LEADS_FILE = DATA_DIR . '/leads.json';

function jload($f, $def = []) {
    if (!is_file($f)) return $def;
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : $def;
}
function jsave($f, $data) {
    $fp = fopen($f, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return true;
}
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function check_csrf() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('CSRF'); }
}
function redirect($q = '') { header('Location: index.php' . ($q ? '?' . $q : '')); exit; }

$pass = jload($PASS_FILE, []);
$has_password = !empty($pass['hash']);
$logged = !empty($_SESSION['admin_ok']);
$msg = '';

/* ---- Первичная установка пароля ---- */
if (!$has_password) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
        $p1 = (string)($_POST['pass1'] ?? ''); $p2 = (string)($_POST['pass2'] ?? '');
        if (strlen($p1) < 8)      $msg = 'Пароль должен быть не короче 8 символов.';
        elseif ($p1 !== $p2)      $msg = 'Пароли не совпадают.';
        else {
            jsave($PASS_FILE, ['hash' => password_hash($p1, PASSWORD_DEFAULT), 'created' => date('c')]);
            $_SESSION['admin_ok'] = true;
            redirect();
        }
    }
    render_setup($msg); exit;
}

/* ---- Вход ---- */
if (!$logged) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $u = (string)($_POST['user'] ?? ''); $p = (string)($_POST['pass'] ?? '');
        if ($u === ADMIN_USER && password_verify($p, $pass['hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            redirect();
        } else { $msg = 'Неверный логин или пароль.'; }
    }
    render_login($msg); exit;
}

/* ---- Действия (только залогиненный) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    check_csrf();
    $leads = jload($LEADS_FILE, []);
    $id = $_POST['id'] ?? '';
    if ($_POST['action'] === 'logout') { session_destroy(); redirect(); }
    if ($_POST['action'] === 'done' || $_POST['action'] === 'reopen') {
        foreach ($leads as &$l) if (($l['id'] ?? '') === $id) $l['status'] = $_POST['action'] === 'done' ? 'done' : 'new';
        unset($l); jsave($LEADS_FILE, $leads);
    }
    if ($_POST['action'] === 'delete') {
        $leads = array_values(array_filter($leads, fn($l) => ($l['id'] ?? '') !== $id));
        jsave($LEADS_FILE, $leads);
    }
    redirect(isset($_GET['f']) ? 'f=' . urlencode($_GET['f']) : '');
}

/* ---- Дашборд: список заявок ---- */
$leads = jload($LEADS_FILE, []);
$leads = array_reverse($leads); // новые сверху
$filter = $_GET['f'] ?? 'new';
$countNew = count(array_filter($leads, fn($l) => ($l['status'] ?? 'new') === 'new'));
$view = array_filter($leads, function ($l) use ($filter) {
    $s = $l['status'] ?? 'new';
    return $filter === 'all' ? true : ($filter === 'done' ? $s === 'done' : $s === 'new');
});
render_dashboard($view, $filter, $countNew, count($leads));

/* ================= ШАБЛОНЫ ================= */
function head($title) {
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">';
    echo '<title>' . e($title) . ' — Админка</title><style>' . admin_css() . '</style></head><body>';
}
function render_setup($msg) {
    head('Установка пароля');
    echo '<div class="auth"><div class="card"><h1>Первый вход</h1><p class="sub">Задайте пароль администратора. Логин: <b>' . e(ADMIN_USER) . '</b></p>';
    if ($msg) echo '<div class="err">' . e($msg) . '</div>';
    echo '<form method="post"><input type="hidden" name="setup" value="1">
        <label>Новый пароль</label><input type="password" name="pass1" required autofocus>
        <label>Повторите пароль</label><input type="password" name="pass2" required>
        <button class="btn" type="submit">Сохранить и войти</button></form></div></div></body></html>';
}
function render_login($msg) {
    head('Вход');
    echo '<div class="auth"><div class="card"><h1>Вход в админку</h1><p class="sub">' . e(SITE_NAME) . '</p>';
    if ($msg) echo '<div class="err">' . e($msg) . '</div>';
    echo '<form method="post"><input type="hidden" name="login" value="1">
        <label>Логин</label><input type="text" name="user" value="' . e(ADMIN_USER) . '" autocomplete="username">
        <label>Пароль</label><input type="password" name="pass" required autocomplete="current-password" autofocus>
        <button class="btn" type="submit">Войти</button></form></div></div></body></html>';
}
function render_dashboard($view, $filter, $countNew, $total) {
    $c = csrf();
    head('Заявки');
    echo '<header class="top"><div class="wrap"><b>' . e(SITE_NAME) . '</b> · Админка
        <form method="post" style="display:inline;margin-left:auto"><input type="hidden" name="csrf" value="' . e($c) . '">
        <button class="link" name="action" value="logout">Выйти</button></form></div></header>';
    echo '<main class="wrap">';
    echo '<div class="tabs">';
    foreach (['new' => 'Новые', 'done' => 'Обработанные', 'all' => 'Все'] as $k => $t) {
        $badge = $k === 'new' && $countNew ? ' <span class="badge">' . $countNew . '</span>' : '';
        echo '<a class="tab' . ($filter === $k ? ' on' : '') . '" href="?f=' . $k . '">' . $t . $badge . '</a>';
    }
    echo '<span class="total">Всего: ' . $total . '</span></div>';

    if (!$view) {
        echo '<div class="empty">Заявок пока нет.</div>';
    } else {
        echo '<div class="leads">';
        foreach ($view as $l) {
            $new = ($l['status'] ?? 'new') === 'new';
            echo '<div class="lead' . ($new ? ' is-new' : '') . '">';
            echo '<div class="l-main"><div class="l-name">' . e($l['name'] ?: '—') . '</div>';
            echo '<a class="l-phone" href="tel:' . e(preg_replace('/[^\d+]/', '', $l['phone'])) . '">' . e($l['phone']) . '</a>';
            if (!empty($l['email'])) echo '<span class="l-email">' . e($l['email']) . '</span>';
            echo '</div>';
            echo '<div class="l-meta"><span class="chip">' . e($l['source']) . '</span>';
            if (!empty($l['page'])) echo '<span class="l-page">' . e($l['page']) . '</span>';
            echo '<span class="l-time">' . e(fmt_ts($l['ts'] ?? '')) . '</span></div>';
            if (!empty($l['message'])) echo '<div class="l-msg">' . e($l['message']) . '</div>';
            echo '<div class="l-act"><form method="post">
                <input type="hidden" name="csrf" value="' . e($c) . '"><input type="hidden" name="id" value="' . e($l['id']) . '">';
            if ($new) echo '<button class="btn sm" name="action" value="done">✓ Обработана</button>';
            else      echo '<button class="btn sm ghost" name="action" value="reopen">↩ В новые</button>';
            echo '<button class="btn sm danger" name="action" value="delete" onclick="return confirm(\'Удалить заявку?\')">Удалить</button>';
            echo '</form></div>';
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</main></body></html>';
}
function fmt_ts($iso) {
    if (!$iso) return '';
    try { $d = new DateTime($iso); return $d->format('d.m.Y H:i'); } catch (Exception $e) { return $iso; }
}
function admin_css() {
    return '*{box-sizing:border-box;margin:0;padding:0}body{font-family:system-ui,Segoe UI,Roboto,sans-serif;background:#F5F7F9;color:#131B23;font-size:15px}
    .wrap{max-width:860px;margin:0 auto;padding:0 16px}
    .top{background:#fff;border-bottom:1px solid #E4E8EC;padding:14px 0;position:sticky;top:0;z-index:5}
    .top .wrap{display:flex;align-items:center;gap:8px}
    .link{background:none;border:none;color:#0089B0;cursor:pointer;font-size:14px;font-family:inherit}
    main.wrap{padding-top:22px;padding-bottom:60px}
    .tabs{display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap}
    .tab{padding:8px 16px;border-radius:999px;background:#fff;border:1px solid #E4E8EC;color:#414F58;text-decoration:none;font-weight:600;font-size:14px}
    .tab.on{background:#131B23;color:#fff;border-color:#131B23}
    .badge{background:#7CCD00;color:#fff;border-radius:999px;padding:1px 7px;font-size:12px;margin-left:4px}
    .total{margin-left:auto;color:#6B7680;font-size:13px}
    .empty{background:#fff;border:1px solid #E4E8EC;border-radius:14px;padding:40px;text-align:center;color:#6B7680}
    .leads{display:flex;flex-direction:column;gap:12px}
    .lead{background:#fff;border:1px solid #E4E8EC;border-radius:14px;padding:16px 18px;box-shadow:0 2px 10px rgba(19,27,35,.04)}
    .lead.is-new{border-left:4px solid #7CCD00}
    .l-main{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap}
    .l-name{font-weight:700;font-size:16px}
    .l-phone{font-weight:700;color:#0089B0;text-decoration:none;font-size:16px}
    .l-email{color:#6B7680;font-size:14px}
    .l-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px}
    .chip{background:#EAF6FA;color:#0089B0;border-radius:999px;padding:3px 11px;font-size:12.5px;font-weight:600}
    .l-page{color:#98A2AA;font-size:12.5px}
    .l-time{color:#98A2AA;font-size:12.5px;margin-left:auto}
    .l-msg{margin-top:10px;padding:10px 12px;background:#F5F7F9;border-radius:10px;font-size:14px;color:#414F58}
    .l-act{display:flex;gap:8px;margin-top:14px}
    .l-act form{display:flex;gap:8px}
    .btn{background:#03AAD3;color:#fff;border:none;border-radius:10px;padding:12px 18px;font-weight:700;cursor:pointer;font-family:inherit;font-size:15px}
    .btn.sm{padding:8px 14px;font-size:13.5px;border-radius:8px}
    .btn.ghost{background:#fff;border:1.5px solid #E4E8EC;color:#414F58}
    .btn.danger{background:#fff;border:1.5px solid #f0c9c9;color:#c0392b}
    .auth{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:#fff;border:1px solid #E4E8EC;border-radius:18px;padding:34px;width:100%;max-width:380px;box-shadow:0 8px 40px rgba(19,27,35,.08)}
    .card h1{font-size:22px;margin-bottom:6px}.card .sub{color:#6B7680;font-size:14px;margin-bottom:20px}
    .card label{display:block;font-size:13px;color:#6B7680;margin:14px 0 6px}
    .card input{width:100%;padding:13px 15px;border:1.5px solid #E4E8EC;border-radius:10px;font-size:15px;font-family:inherit}
    .card .btn{width:100%;margin-top:22px}
    .err{background:#fdecea;border:1px solid #f5c6cb;color:#c0392b;border-radius:10px;padding:10px 12px;font-size:14px;margin-bottom:6px}';
}
