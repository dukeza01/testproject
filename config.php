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
const MAX_UPLOAD   = 5 * 1024 * 1024;         // 5MB ต่อไฟล์
const ADMIN_PASSWORD = 'admin1234';           // รหัสผู้ดูแล (เปลี่ยนก่อนใช้งานจริง)

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

// ---------- ฐานข้อมูล ----------
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/app.sqlite');
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
    ");
    return $pdo;
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
