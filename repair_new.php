<?php
require __DIR__ . '/config.php';

$errors = [];
$old = ['title' => '', 'category' => '', 'location' => '', 'priority' => 'normal',
        'description' => '', 'reporter_name' => '', 'reporter_contact' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($old as $k => $_) $old[$k] = trim((string)($_POST[$k] ?? ''));

    if ($old['title'] === '')                         $errors['title'] = 'กรุณาระบุหัวข้อ';
    if (!isset(CATEGORIES[$old['category']]))         $errors['category'] = 'กรุณาเลือกประเภท';
    if ($old['location'] === '')                      $errors['location'] = 'กรุณาระบุสถานที่';
    if (!isset(PRIORITIES[$old['priority']]))         $errors['priority'] = 'ระดับความเร่งด่วนไม่ถูกต้อง';
    if ($old['reporter_name'] === '')                 $errors['reporter_name'] = 'กรุณาระบุชื่อผู้แจ้ง';

    // ตรวจไฟล์แนบ (รูปภาพเท่านั้น)
    $files = [];
    if (!empty($_FILES['photos']['name'][0])) {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
            if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) { $errors['photos'] = 'อัปโหลดไฟล์ไม่สำเร็จ'; break; }
            if ($_FILES['photos']['size'][$i] > MAX_UPLOAD)       { $errors['photos'] = 'ไฟล์ใหญ่เกิน 5MB'; break; }
            $mime = $finfo->file($tmp);
            if (!isset($allowed[$mime]))                           { $errors['photos'] = 'รองรับเฉพาะไฟล์รูปภาพ'; break; }
            $files[] = [$tmp, $allowed[$mime], $_FILES['photos']['name'][$i]];
        }
        if (count($files) > 5) $errors['photos'] = 'แนบได้ไม่เกิน 5 รูป';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO requests (title, category, location, priority, description,
            reporter_name, reporter_contact, status, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$old['title'], $old['category'], $old['location'], $old['priority'], $old['description'],
            $old['reporter_name'], $old['reporter_contact'], 'pending', now(), now()]);
        $id = (int)$pdo->lastInsertId();
        $code = generate_code($id);
        $pdo->prepare('UPDATE requests SET code = ? WHERE id = ?')->execute([$code, $id]);
        $pdo->prepare('INSERT INTO logs (request_id, status, note, actor, created_at) VALUES (?,?,?,?,?)')
            ->execute([$id, 'pending', 'แจ้งเรื่องเข้าระบบ', $old['reporter_name'], now()]);

        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
        $att = $pdo->prepare('INSERT INTO attachments (request_id, filename, original_name, created_at) VALUES (?,?,?,?)');
        foreach ($files as [$tmp, $ext, $orig]) {
            $name = $code . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (move_uploaded_file($tmp, UPLOAD_DIR . '/' . $name)) {
                $att->execute([$id, $name, $orig, now()]);
            }
        }
        $pdo->commit();

        flash("บันทึกเรียบร้อย รหัสงานของคุณคือ {$code} (ใช้สำหรับติดตามสถานะ)");
        header('Location: repair_view.php?id=' . $id);
        exit;
    }
}

function err(array $errors, string $k): string {
    return isset($errors[$k]) ? '<div class="field-error">' . e($errors[$k]) . '</div>' : '';
}

page_header('แจ้งซ่อมใหม่', 'repair_new.php');
?>
<div class="page-head">
  <div>
    <h1>แจ้งซ่อมใหม่</h1>
    <p class="muted">กรอกรายละเอียดให้ครบถ้วน เจ้าหน้าที่จะรับเรื่องและอัปเดตสถานะให้ทราบ</p>
  </div>
</div>

<form method="post" enctype="multipart/form-data" class="card form" novalidate>
  <?= csrf_field() ?>

  <section class="form-section">
    <h2>รายละเอียดปัญหา</h2>
    <div class="grid-2">
      <label class="field span-2">
        <span>หัวข้อ / อาการเสีย <b class="req">*</b></span>
        <input type="text" name="title" maxlength="150" value="<?= e($old['title']) ?>" placeholder="เช่น หลอดไฟห้องเรียนไม่ติด, ก๊อกน้ำรั่ว" required>
        <?= err($errors, 'title') ?>
      </label>

      <label class="field">
        <span>ประเภทงาน <b class="req">*</b></span>
        <select name="category" required>
          <option value="">— เลือกประเภท —</option>
          <?php foreach (CATEGORIES as $k => $v): ?>
            <option value="<?= $k ?>" <?= $old['category'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
        <?= err($errors, 'category') ?>
      </label>

      <label class="field">
        <span>สถานที่ / อาคาร / ห้อง <b class="req">*</b></span>
        <input type="text" name="location" maxlength="150" value="<?= e($old['location']) ?>" placeholder="เช่น อาคาร 2 ชั้น 3 ห้อง 231" required>
        <?= err($errors, 'location') ?>
      </label>

      <fieldset class="field span-2">
        <span>ความเร่งด่วน</span>
        <div class="seg">
          <?php foreach (PRIORITIES as $k => [$label, $color]): ?>
            <label class="seg-item seg-<?= $color ?>">
              <input type="radio" name="priority" value="<?= $k ?>" <?= $old['priority'] === $k ? 'checked' : '' ?>>
              <span><?= e($label) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?= err($errors, 'priority') ?>
      </fieldset>

      <label class="field span-2">
        <span>รายละเอียดเพิ่มเติม</span>
        <textarea name="description" rows="4" placeholder="อธิบายอาการ เวลาที่พบปัญหา หรือข้อมูลที่ช่วยให้ช่างทำงานได้เร็วขึ้น"><?= e($old['description']) ?></textarea>
      </label>

      <div class="field span-2">
        <span>รูปภาพประกอบ (ไม่เกิน 5 รูป, รูปละ 5MB)</span>
        <label class="dropzone" id="dropzone">
          <input type="file" name="photos[]" id="photos" accept="image/*" multiple>
          <div class="dz-text">📷 แตะเพื่อถ่ายรูป / เลือกรูป หรือลากไฟล์มาวาง</div>
        </label>
        <div class="previews" id="previews"></div>
        <?= err($errors, 'photos') ?>
      </div>
    </div>
  </section>

  <section class="form-section">
    <h2>ข้อมูลผู้แจ้ง</h2>
    <div class="grid-2">
      <label class="field">
        <span>ชื่อ-สกุล <b class="req">*</b></span>
        <input type="text" name="reporter_name" maxlength="100" value="<?= e($old['reporter_name']) ?>" required>
        <?= err($errors, 'reporter_name') ?>
      </label>
      <label class="field">
        <span>เบอร์โทร / อีเมล / หน่วยงาน</span>
        <input type="text" name="reporter_contact" maxlength="100" value="<?= e($old['reporter_contact']) ?>">
      </label>
    </div>
  </section>

  <div class="form-actions">
    <a href="index.php" class="btn btn-ghost">ยกเลิก</a>
    <button type="submit" class="btn btn-primary">ส่งเรื่องแจ้งซ่อม</button>
  </div>
</form>

<script>
// แสดงตัวอย่างรูปก่อนอัปโหลด
const input = document.getElementById('photos');
const box = document.getElementById('previews');
const dz = document.getElementById('dropzone');
function render() {
  box.innerHTML = '';
  [...input.files].slice(0, 5).forEach(f => {
    const img = document.createElement('img');
    img.src = URL.createObjectURL(f);
    img.onload = () => URL.revokeObjectURL(img.src);
    box.appendChild(img);
  });
}
input.addEventListener('change', render);
['dragover', 'dragenter'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('over'); }));
['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, () => dz.classList.remove('over')));
dz.addEventListener('drop', e => { e.preventDefault(); input.files = e.dataTransfer.files; render(); });
</script>
<?php page_footer();
