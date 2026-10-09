<?php
require __DIR__ . '/config.php';

if (isset($_GET['logout'])) {
    unset($_SESSION['is_admin']);
    flash('ออกจากระบบแล้ว');
    header('Location: index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (hash_equals(ADMIN_PASSWORD, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        flash('เข้าสู่ระบบผู้ดูแลแล้ว');
        header('Location: index.php'); exit;
    }
    $error = 'รหัสผ่านไม่ถูกต้อง';
}
page_header('เข้าสู่ระบบผู้ดูแล', 'login.php');
?>
<div class="narrow">
  <div class="page-head"><div><h1>ผู้ดูแลระบบ</h1><p class="muted">สำหรับเจ้าหน้าที่ที่อัปเดตสถานะงาน</p></div></div>
  <form class="card form" method="post">
    <?= csrf_field() ?>
    <label class="field"><span>รหัสผ่าน</span><input type="password" name="password" required autofocus></label>
    <?php if ($error): ?><div class="field-error"><?= e($error) ?></div><?php endif; ?>
    <button class="btn btn-primary btn-block">เข้าสู่ระบบ</button>
  </form>
</div>
<?php page_footer();
