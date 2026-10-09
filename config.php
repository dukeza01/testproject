<?php
/**
 * ระบบแจ้งซ่อม / แจ้งงาน (Generic Request System)
 * ปรับค่าในไฟล์นี้เพื่อนำไปใช้กับงานอื่นได้ เช่น แจ้งซ่อม, ขอใช้ห้อง, แจ้งปัญหา IT, ขอยืมพัสดุ
 */
declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Bangkok');

// ---------- ตั้งค่าระบบ (แก้ตรงนี้เพื่อเปลี่ยนการใช้งาน) ----------
const APP_NAME     = 'ระบบแจ้งซ่อม';
const ORG_NAME     = 'ชื่อหน่วยงาน / โรงเรียน';
const ITEM_LABEL   = 'งานแจ้งซ่อม';          // ชื่อเรียกรายการ
const CODE_PREFIX  = 'RP';                    // รหัสงาน เช่น RP-2569-0001
const UPLOAD_DIR   = __DIR__ . '/uploads';
const DATA_DIR     = __DIR__ . '/data';
const MAX_UPLOAD   = 5 * 1024 * 1024;         // 5MB ต่อไฟล์
// รหัสผ่านผู้ดูแล: ตั้งครั้งแรกผ่านหน้าเว็บ (login.php) แล้วเก็บแบบเข้ารหัสในฐานข้อมูล

const CATEGORIES = [
    'electric'  => 'ไฟฟ้า / แสงสว่าง',
    'plumbing'  => 'ประปา / ห้องน้ำ',
    'building'  => 'อาคาร / ประตู / หน้าต่าง',
    'furniture' => 'โต๊ะ / เก้าอี้ / ครุภัณฑ์',
    'aircon'    => 'เครื่องปรับอากาศ / พัดลม',
    'it'        => 'คอมพิวเตอร์ / เครือข่าย / โปรเจกเตอร์',
    'other'     => 'อื่น ๆ',
];

const PRIORITIES = [
    'low'    => ['ต่ำ',     'gray'],
    'normal' => ['ปกติ',    'blue'],
    'high'   => ['ด่วน',    'orange'],
    'urgent' => ['ด่วนมาก', 'red'],
];

const STATUSES = [
    'pending'     => ['รอรับเรื่อง',   'gray'],
    'accepted'    => ['รับเรื่องแล้ว', 'blue'],
    'in_progress' => ['กำลังดำเนินการ', 'orange'],
    'waiting'     => ['รออะไหล่/งบ',   'purple'],
    'done'        => ['เสร็จสิ้น',      'green'],
    'rejected'    => ['ยกเลิก/ไม่อนุมัติ', 'red'],
];

// ---------- ตั้งค่าอัตโนมัติครั้งแรก (ไม่ต้องติดตั้งเอง) ----------
function fatal(string $title, string $msg): never {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<div style="font-family:sans-serif;max-width:560px;margin:60px auto;padding:24px;border:1px solid #fecaca;border-radius:12px;background:#fef2f2">'
       . '<h2 style="margin-top:0;color:#b91c1c">' . htmlspecialchars($title) . '</h2><p>' . $msg . '</p></div>';
    exit;
}

function bootstrap(): void {
    if (version_compare(PHP_VERSION, '8.0.0', '<'))
        fatal('PHP เวอร์ชันต่ำเกินไป', 'ต้องใช้ PHP 8.0 ขึ้นไป (ปัจจุบัน ' . PHP_VERSION . ')');
    if (!extension_loaded('pdo_sqlite'))
        fatal('ไม่พบ pdo_sqlite', 'กรุณาเปิด extension <code>pdo_sqlite</code> ในแผงควบคุมโฮสติ้ง (cPanel → Select PHP Version)');

    $protect = [
        DATA_DIR   => "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n",
        UPLOAD_DIR => "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
                    . "<FilesMatch \"\\.(php|phtml|phar|pht)$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Deny from all\n  </IfModule>\n</FilesMatch>\n",
    ];
    foreach ($protect as $dir => $ht) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true))
            fatal('สร้างโฟลเดอร์ไม่ได้', 'เว็บเซิร์ฟเวอร์ไม่มีสิทธิ์สร้างโฟลเดอร์ <code>' . basename($dir) . '/</code> กรุณาสร้างเองและตั้งสิทธิ์เป็น 775');
        if (!is_writable($dir))
            fatal('เขียนไฟล์ไม่ได้', 'กรุณาตั้งสิทธิ์โฟลเดอร์ <code>' . basename($dir) . '/</code> เป็น 775 (หรือ 777 บนบางโฮสติ้ง)');
        if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", $ht);
        if (!is_file("$dir/index.html")) @file_put_contents("$dir/index.html", '');
    }
}
bootstrap();

// ---------- ฐานข้อมูล ----------
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DATA_DIR . '/app.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE,
            title TEXT NOT NULL,
            category TEXT NOT NULL,
            location TEXT NOT NULL,
            priority TEXT NOT NULL DEFAULT 'normal',
            description TEXT,
            reporter_name TEXT NOT NULL,
            reporter_contact TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            assignee TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
            filename TEXT NOT NULL,
            original_name TEXT,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
            status TEXT NOT NULL,
            note TEXT,
            actor TEXT,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
    ");
    return $pdo;
}

function setting(string $key, ?string $value = null): ?string {
    if ($value !== null) {
        db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
        return $value;
    }
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

// ---------- ตัวช่วย ----------
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }
function is_admin(): bool { return !empty($_SESSION['is_admin']); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400); exit('CSRF token ไม่ถูกต้อง กรุณาโหลดหน้าใหม่');
    }
}

function flash(?string $msg = null, string $type = 'success'): ?array {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

function badge(array $map, string $key): string {
    [$label, $color] = $map[$key] ?? [$key, 'gray'];
    return '<span class="badge badge-' . $color . '">' . e($label) . '</span>';
}

function thai_date(string $dt, bool $time = true): string {
    $m = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $t = strtotime($dt);
    $s = date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' ' . (date('Y', $t) + 543);
    return $time ? $s . ' ' . date('H:i', $t) . ' น.' : $s;
}

function generate_code(int $id): string {
    return sprintf('%s-%d-%04d', CODE_PREFIX, (int)date('Y') + 543, $id);
}

// ---------- Layout ----------
function page_header(string $title, string $active = ''): void {
    $nav = [
        'index.php'      => ['list', 'รายการทั้งหมด'],
        'repair_new.php' => ['plus', 'แจ้งซ่อมใหม่'],
        'track.php'      => ['search', 'ติดตามสถานะ'],
    ];
    ?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#2563eb">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="index.php">
      <span class="brand-mark">🛠</span>
      <span><strong><?= e(APP_NAME) ?></strong><small><?= e(ORG_NAME) ?></small></span>
    </a>
    <nav class="nav">
      <?php foreach ($nav as $href => [$icon, $label]): ?>
        <a href="<?= $href ?>" class="<?= $active === $href ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (is_admin()): ?>
        <a href="login.php?logout=1" class="nav-admin">ออกจากระบบผู้ดูแล</a>
      <?php else: ?>
        <a href="login.php" class="nav-admin <?= $active === 'login.php' ? 'active' : '' ?>">ผู้ดูแล</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap">
<?php if ($active !== 'login.php' && setting('admin_password_hash') === null): ?>
  <div class="alert alert-warn">ระบบเพิ่งติดตั้ง ยังไม่ได้ตั้งรหัสผ่านผู้ดูแล → <a href="login.php">ตั้งรหัสผ่านตอนนี้</a></div>
<?php endif; ?>
<?php if ($f = flash()): ?>
  <div class="alert alert-<?= e($f[1]) ?>"><?= e($f[0]) ?></div>
<?php endif;
}

function page_footer(): void { ?>
</main>
<footer class="footer wrap">© <?= date('Y') + 543 ?> <?= e(ORG_NAME) ?></footer>
</body>
</html>
<?php }
