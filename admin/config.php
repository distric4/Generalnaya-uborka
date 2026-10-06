<?php
/* Общие настройки админки и API заявок */
define('SITE_ROOT', dirname(__DIR__));
define('DATA_DIR', SITE_ROOT . '/data');
define('ADMIN_USER', 'admin');
define('NOTIFY_EMAIL', '38uborka@gmail.com');   // куда слать уведомления о заявках
define('SITE_NAME', 'Генеральная уборка');
define('SITE_DOMAIN', 'uborka138.ru');

if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
