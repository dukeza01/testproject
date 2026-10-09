<?php
require __DIR__ . '/config.php';
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM requests WHERE id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r) { http_response_code(404); page_header('ไม่พบข้อมูล'); echo '<div class="card empty">ไม่พบรายการนี้</div>'; page_footer(); exit; }

// ผู้ดูแล: อัปเดตสถานะ / ลบ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        $at = $pdo->prepare('SELECT filename FROM attachments WHERE request_id = ?'); $at->execute([$id]);
        foreach ($at->fetchAll() as $a) @unlink(UPLOAD_DIR . '/' . basename($a['filename']));
        $pdo->prepare('DELETE FROM requests WHERE id = ?')->execute([$id]);
        flash('ลบรายการ ' . $r['code'] . ' แล้ว');
        header('Location: index.php'); exit;
    }
    $new = $_POST['status'] ?? '';
    if (isset(STATUSES[$new])) {
        $assignee = trim($_POST['assignee'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $pdo->prepare('UPDATE requests SET status = ?, assignee = ?, updated_at = ? WHERE id = ?')
            ->execute([$new, $assignee ?: null, now(), $id]);
        $pdo->prepare('INSERT INTO logs (request_id, status, note, actor, created_at) VALUES (?,?,?,?,?)')
            ->execute([$id, $new, $note, $assignee ?: 'ผู้ดูแลระบบ', now()]);
        flash('อัปเดตสถานะเรียบร้อย');
    }
    header('Location: repair_view.php?id=' . $id); exit;
}

$att = $pdo->prepare('SELECT * FROM attachments WHERE request_id = ?'); $att->execute([$id]); $photos = $att->fetchAll();
$lg  = $pdo->prepare('SELECT * FROM logs WHERE request_id = ? ORDER BY id DESC'); $lg->execute([$id]); $logs = $lg->fetchAll();

page_header($r['code']);
?>
<div class="page-head">
  <div>
    <a href="index.php" class="back">← กลับไปรายการ</a>
    <h1><?= e($r['title']) ?></h1>
    <p class="muted"><span class="code"><?= e($r['code']) ?></span> · แจ้งเมื่อ <?= thai_date($r['created_at']) ?></p>
  </div>
  <div class="head-badges"><?= badge(PRIORITIES, $r['priority']) ?> <?= badge(STATUSES, $r['status']) ?></div>
</div>

<?php $keys = ['pending', 'accepted', 'in_progress', 'done']; $cur = array_search($r['status'], $keys, true); ?>
<?php if ($r['status'] !== 'rejected'): ?>
<ol class="steps card">
  <?php foreach ($keys as $i => $k): ?>
    <li class="<?= ($cur !== false && $i <= $cur) || ($r['status'] === 'waiting' && $i <= 2) ? 'on' : '' ?>"><?= e(STATUSES[$k][0]) ?></li>
  <?php endforeach; ?>
</ol>
<?php endif; ?>

<div class="detail-grid">
  <div class="card">
    <h2>รายละเอียด</h2>
    <dl class="dl">
      <dt>ประเภท</dt><dd><?= e(CATEGORIES[$r['category']] ?? $r['category']) ?></dd>
      <dt>สถานที่</dt><dd><?= e($r['location']) ?></dd>
      <dt>ผู้แจ้ง</dt><dd><?= e($r['reporter_name']) ?><?= $r['reporter_contact'] ? ' · ' . e($r['reporter_contact']) : '' ?></dd>
      <dt>ผู้รับผิดชอบ</dt><dd><?= e($r['assignee'] ?: '—') ?></dd>
      <dt>อัปเดตล่าสุด</dt><dd><?= thai_date($r['updated_at']) ?></dd>
    </dl>
    <?php if ($r['description']): ?>
      <h3>อาการ / รายละเอียด</h3>
      <p class="pre"><?= e($r['description']) ?></p>
    <?php endif; ?>
    <?php if ($photos): ?>
      <h3>รูปภาพ</h3>
      <div class="gallery">
        <?php foreach ($photos as $p): ?>
          <a href="uploads/<?= e($p['filename']) ?>" target="_blank"><img src="uploads/<?= e($p['filename']) ?>" alt="<?= e($p['original_name']) ?>"></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <?php if (is_admin()): ?>
    <form method="post" class="card form">
      <?= csrf_field() ?>
      <h2>อัปเดตสถานะ</h2>
      <label class="field"><span>สถานะ</span>
        <select name="status">
          <?php foreach (STATUSES as $k => [$l]): ?><option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span>ผู้รับผิดชอบ / ช่าง</span>
        <input type="text" name="assignee" value="<?= e($r['assignee']) ?>">
      </label>
      <label class="field"><span>บันทึก</span>
        <textarea name="note" rows="3" placeholder="เช่น เปลี่ยนหลอดไฟใหม่แล้ว"></textarea>
      </label>
      <button class="btn btn-primary btn-block">บันทึก</button>
    </form>
    <form method="post" onsubmit="return confirm('ยืนยันลบรายการนี้?')" class="danger-zone">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <button class="btn btn-danger btn-block">ลบรายการ</button>
    </form>
    <?php endif; ?>

    <div class="card">
      <h2>ประวัติการดำเนินงาน</h2>
      <ul class="timeline">
        <?php foreach ($logs as $l): ?>
          <li>
            <div><?= badge(STATUSES, $l['status']) ?> <span class="muted small"><?= thai_date($l['created_at']) ?></span></div>
            <?php if ($l['note']): ?><div><?= e($l['note']) ?></div><?php endif; ?>
            <div class="muted small">โดย <?= e($l['actor']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<?php page_footer();
