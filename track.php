<?php
require __DIR__ . '/config.php';
$code = strtoupper(trim($_GET['code'] ?? ''));
$notFound = false;
if ($code !== '') {
    $st = db()->prepare('SELECT id FROM requests WHERE code = ?');
    $st->execute([$code]);
    if ($id = $st->fetchColumn()) { header('Location: repair_view.php?id=' . (int)$id); exit; }
    $notFound = true;
}
page_header('ติดตามสถานะ', 'track.php');
?>
<div class="narrow">
  <div class="page-head"><div><h1>ติดตามสถานะ</h1><p class="muted">กรอกรหัสงานที่ได้รับหลังแจ้งซ่อม</p></div></div>
  <form class="card form" method="get">
    <label class="field"><span>รหัสงาน</span>
      <input type="text" name="code" value="<?= e($code) ?>" placeholder="<?= CODE_PREFIX ?>-<?= date('Y') + 543 ?>-0001" required autofocus>
    </label>
    <?php if ($notFound): ?><div class="field-error">ไม่พบรหัสงานนี้</div><?php endif; ?>
    <button class="btn btn-primary btn-block">ค้นหา</button>
  </form>
</div>
<?php page_footer();
