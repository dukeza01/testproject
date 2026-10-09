<?php
require __DIR__ . '/config.php';

if (isset($_GET['logout'])) {
    unset($_SESSION['is_admin']);
    flash('ออกจากระบบแล้ว');
    header('Location: index.php'); exit;
}

$hash = setting('admin_password_hash');
$firstRun = $hash === null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pw = (string)($_POST['password'] ?? '');

    if ($firstRun) {
        // ตั้งรหัสผ่านผู้ดูแลครั้งแรก
        if (mb_strlen($pw) < 8)                        $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
        elseif ($pw !== ($_POST['password2'] ?? ''))   $error = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
        else {
            setting('admin_password_hash', password_hash($pw, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            flash('ตั้งรหัสผ่านผู้ดูแลเรียบร้อย ระบบพร้อมใช้งานแล้ว');
            header('Location: index.php'); exit;
        }
    } else {
        // หน่วงเวลาเล็กน้อยกันการเดารหัส
        usleep(300000);
        if (password_verify($pw, $hash)) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            flash('เข้าสู่ระบบผู้ดูแลแล้ว');
            header('Location: index.php'); exit;
        }
        $error = 'รหัสผ่านไม่ถูกต้อง';
    }
}
page_header('เข้าสู่ระบบผู้ดูแล', 'login.php');
?>
<div class="narrow">
  <div class="page-head"><div>
    <h1><?= $firstRun ? 'ตั้งรหัสผ่านผู้ดูแล' : 'ผู้ดูแลระบบ' ?></h1>
    <p class="muted"><?= $firstRun
        ? 'ใช้งานครั้งแรก กรุณาตั้งรหัสผ่านสำหรับเจ้าหน้าที่ที่จะอัปเดตสถานะงาน'
        : 'สำหรับเจ้าหน้าที่ที่อัปเดตสถานะงาน' ?></p>
  </div></div>
  <form class="card form" method="post">
    <?= csrf_field() ?>
    <label class="field"><span><?= $firstRun ? 'รหัสผ่านใหม่ (อย่างน้อย 8 ตัว)' : 'รหัสผ่าน' ?></span>
      <input type="password" name="password" required autofocus <?= $firstRun ? 'minlength="8"' : '' ?>></label>
    <?php if ($firstRun): ?>
      <label class="field"><span>ยืนยันรหัสผ่าน</span><input type="password" name="password2" required minlength="8"></label>
    <?php endif; ?>
    <?php if ($error): ?><div class="field-error"><?= e($error) ?></div><?php endif; ?>
    <button class="btn btn-primary btn-block"><?= $firstRun ? 'บันทึกและเข้าสู่ระบบ' : 'เข้าสู่ระบบ' ?></button>
  </form>
</div>
<?php page_footer();
