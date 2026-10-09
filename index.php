<?php
require __DIR__ . '/config.php';
$pdo = db();

// ตัวกรอง
$q        = trim($_GET['q'] ?? '');
$status   = $_GET['status'] ?? '';
$category = $_GET['category'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 15;

$where = []; $params = [];
if ($q !== '') {
    $where[] = '(code LIKE ? OR title LIKE ? OR location LIKE ? OR reporter_name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if (isset(STATUSES[$status]))     { $where[] = 'status = ?';   $params[] = $status; }
if (isset(CATEGORIES[$category])) { $where[] = 'category = ?'; $params[] = $category; }
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM requests $sqlWhere"); $st->execute($params); $total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("SELECT * FROM requests $sqlWhere
    ORDER BY CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END,
             CASE WHEN status IN ('done','rejected') THEN 1 ELSE 0 END, id DESC
    LIMIT $perPage OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll();

// สถิติ
$stats = array_fill_keys(array_keys(STATUSES), 0);
foreach ($pdo->query('SELECT status, COUNT(*) c FROM requests GROUP BY status') as $r) $stats[$r['status']] = (int)$r['c'];
$all = array_sum($stats);
$open = $stats['pending'] + $stats['accepted'] + $stats['in_progress'] + $stats['waiting'];

function qs(array $over): string { return '?' . http_build_query(array_merge($_GET, $over)); }

page_header('รายการแจ้งซ่อม', 'index.php');
?>
<div class="page-head">
  <div>
    <h1>รายการ<?= e(ITEM_LABEL) ?></h1>
    <p class="muted">ติดตามและจัดการงานทั้งหมดในที่เดียว</p>
  </div>
  <a href="repair_new.php" class="btn btn-primary">+ แจ้งซ่อมใหม่</a>
</div>

<div class="stats">
  <div class="stat"><small>ทั้งหมด</small><strong><?= $all ?></strong></div>
  <div class="stat stat-gray"><small>รอรับเรื่อง</small><strong><?= $stats['pending'] ?></strong></div>
  <div class="stat stat-orange"><small>กำลังดำเนินการ</small><strong><?= $open - $stats['pending'] ?></strong></div>
  <div class="stat stat-green"><small>เสร็จสิ้น</small><strong><?= $stats['done'] ?></strong></div>
</div>

<form class="filters card" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหา รหัส / หัวข้อ / สถานที่ / ผู้แจ้ง">
  <select name="status">
    <option value="">ทุกสถานะ</option>
    <?php foreach (STATUSES as $k => [$l]): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <select name="category">
    <option value="">ทุกประเภท</option>
    <?php foreach (CATEGORIES as $k => $l): ?><option value="<?= $k ?>" <?= $category === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <button class="btn">กรอง</button>
  <?php if ($q || $status || $category): ?><a class="btn btn-ghost" href="index.php">ล้าง</a><?php endif; ?>
</form>

<div class="card table-card">
  <?php if (!$rows): ?>
    <div class="empty">ยังไม่มีรายการ <a href="repair_new.php">แจ้งซ่อมรายการแรก</a></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th>รหัส</th><th>หัวข้อ</th><th>ประเภท</th><th>สถานที่</th><th>ความเร่งด่วน</th><th>สถานะ</th><th>วันที่แจ้ง</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr onclick="location.href='repair_view.php?id=<?= (int)$r['id'] ?>'">
        <td data-label="รหัส"><a href="repair_view.php?id=<?= (int)$r['id'] ?>" class="code"><?= e($r['code']) ?></a></td>
        <td data-label="หัวข้อ"><strong><?= e($r['title']) ?></strong><div class="muted small"><?= e($r['reporter_name']) ?></div></td>
        <td data-label="ประเภท"><?= e(CATEGORIES[$r['category']] ?? $r['category']) ?></td>
        <td data-label="สถานที่"><?= e($r['location']) ?></td>
        <td data-label="ความเร่งด่วน"><?= badge(PRIORITIES, $r['priority']) ?></td>
        <td data-label="สถานะ"><?= badge(STATUSES, $r['status']) ?></td>
        <td data-label="วันที่แจ้ง" class="small"><?= thai_date($r['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<nav class="pager">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <a href="<?= e(qs(['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
  <?php endfor; ?>
</nav>
<?php endif; ?>
<?php page_footer();
