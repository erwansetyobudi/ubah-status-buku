<?php
/*
 * File: index.php
 * Created on Fri Oct 09 2026
 * Last Updated: Fri Oct 09 2026 9:25:23 AM
 * Author: Erwan Setyo Budi
 * Email: erwans818@gmail.com
 * License: The GNU General Public License, Version 3 (GPL-3.0) - Copyright (C) 2026 Erwan Setyo Budi. This program is free software.
 */

defined('INDEX_AUTH') OR die('Direct access not allowed!');
require_once SB . 'admin/default/session.inc.php';
require_once SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
require_once SIMBIO . 'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require_once SIMBIO . 'simbio_GUI/paging/simbio_paging.inc.php';
require_once SIMBIO . 'simbio_DB/datagrid/simbio_dbgrid.inc.php';

function usb_h($x)
{
    return htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
}

function usb_route($a = [])
{
    $q = $_GET;
    foreach ($a as $k => $v) {
        if ($v === null)
            unset($q[$k]);
        else
            $q[$k] = $v;
    }
    return $_SERVER['PHP_SELF'] . '?' . http_build_query($q);
}

function usb_csv($x)
{
    $x = (string) $x;
    return preg_match('/^[=+@\-\t\r]/u', $x) ? "'" . $x : $x;
}

// Buat tabel audit secara otomatis. InnoDB menjaga perubahan item dan log tetap atomik.
$dbs->query('CREATE TABLE IF NOT EXISTS plugin_item_status_log (
 log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 item_id INT NOT NULL, item_code VARCHAR(20) NOT NULL,
 biblio_id INT NULL, title TEXT NULL, publish_year VARCHAR(20) NULL,
 old_status_id CHAR(3) NULL, new_status_id CHAR(3) NULL,
 uid INT NOT NULL, changed_at DATETIME NOT NULL,
 KEY idx_changed_at(changed_at), KEY idx_item_code(item_code), KEY idx_uid(uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
// Upgrade skema log lama: status Tersedia dicatat sebagai NULL.
$col = $dbs->query("SHOW COLUMNS FROM plugin_item_status_log LIKE 'new_status_id'");
if ($col && ($c = $col->fetch_assoc()) && strtoupper($c['Null']) === 'NO') {
    $dbs->query('ALTER TABLE plugin_item_status_log MODIFY new_status_id CHAR(3) NULL');
}
$exists = $dbs->query("SHOW TABLES LIKE 'plugin_item_status_log'");
$ready = $exists && $exists->num_rows > 0;
$statuses = [];
$sr = $dbs->query('SELECT item_status_id,item_status_name FROM mst_item_status ORDER BY item_status_name');
if ($sr)
    while ($r = $sr->fetch_assoc())
        $statuses[] = $r;
$notice = '';
$kind = 'success';
$uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
$mode = isset($_GET['view']) ? (string) $_GET['view'] : 'scan';
$selected = isset($_POST['status_id']) ? (string) $_POST['status_id'] : (isset($_GET['status_id']) ? (string) $_GET['status_id'] : '');
$statuses = array_merge([['item_status_id' => '__AVAILABLE__', 'item_status_name' => 'Tersedia']], $statuses);
$validStatuses = array_column($statuses, 'item_status_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan_item'])) {
    $code = trim((string) ($_POST['item_code'] ?? ''));
    if (!$ready || !$uid) {
        $notice = 'Tabel log belum tersedia atau sesi pustakawan tidak terbaca.';
        $kind = 'danger';
    } elseif (!in_array($selected, $validStatuses, true)) {
        $notice = 'Silakan pilih status tujuan terlebih dahulu.';
        $kind = 'warning';
    } elseif ($code === '') {
        $notice = 'Kode barcode tidak boleh kosong.';
        $kind = 'warning';
    } else {
        $dbs->begin_transaction();
        try {
            $stmt = $dbs->prepare('SELECT i.item_id,i.item_code,i.biblio_id,i.item_status_id,b.title,b.publish_year FROM item i LEFT JOIN biblio b ON b.biblio_id=i.biblio_id WHERE i.item_code=? LIMIT 1 FOR UPDATE');
            if (!$stmt)
                throw new Exception($dbs->error);
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                $dbs->rollback();
                $notice = 'Barcode tidak ditemukan: ' . $code;
                $kind = 'danger';
            } elseif (($row['item_status_id'] === null || $row['item_status_id'] === '') && $selected === '__AVAILABLE__' || (string) $row['item_status_id'] === $selected) {
                $dbs->rollback();
                $notice = 'Status eksemplar ' . $code . ' sudah sama dengan status yang dipilih.';
                $kind = 'warning';
            } else {
                $stmt = $dbs->prepare('UPDATE item SET item_status_id=?, last_update=NOW(), uid=? WHERE item_id=?');
                if (!$stmt)
                    throw new Exception($dbs->error);
                $iid = (int) $row['item_id'];
                $newStatus = $selected === '__AVAILABLE__' ? null : $selected;
                $stmt->bind_param('sii', $newStatus, $uid, $iid);
                if (!$stmt->execute())
                    throw new Exception($stmt->error);
                $stmt->close();
                $stmt = $dbs->prepare('INSERT INTO plugin_item_status_log(item_id,item_code,biblio_id,title,publish_year,old_status_id,new_status_id,uid,changed_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
                if (!$stmt)
                    throw new Exception($dbs->error);
                $bid = (int) $row['biblio_id'];
                $title = (string) $row['title'];
                $year = (string) $row['publish_year'];
                $old = $row['item_status_id'] === null ? null : (string) $row['item_status_id'];
                $stmt->bind_param('isissssi', $iid, $code, $bid, $title, $year, $old, $newStatus, $uid);
                if (!$stmt->execute())
                    throw new Exception($stmt->error);
                $stmt->close();
                $dbs->commit();
                $notice = 'Berhasil: ' . $code . ' — ' . ($title ?: 'Tanpa Judul') . ' telah berubah status.';
            }
        } catch (Throwable $e) {
            $dbs->rollback();
            $notice = 'Gagal mengubah status. Periksa struktur database dan izin akses. ' . $e->getMessage();
            $kind = 'danger';
        }
    }
}

$start = isset($_GET['start']) ? trim((string) $_GET['start']) : '';
$end = isset($_GET['end']) ? trim((string) $_GET['end']) : '';
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filterStatus = isset($_GET['filter_status']) ? trim((string) $_GET['filter_status']) : '';
$where = ['1=1'];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start))
    $where[] = "l.changed_at >= '" . $dbs->escape_string($start) . " 00:00:00'";
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))
    $where[] = "l.changed_at <= '" . $dbs->escape_string($end) . " 23:59:59'";
if ($search !== '') {
    $v = $dbs->escape_string($search);
    $where[] = "(l.item_code LIKE '%$v%' OR l.title LIKE '%$v%')";
}
if ($filterStatus !== '' && in_array($filterStatus, $validStatuses, true))
    $where[] = $filterStatus === '__AVAILABLE__' ? 'l.new_status_id IS NULL' : "l.new_status_id='" . $dbs->escape_string($filterStatus) . "'";
$wc = implode(' AND ', $where);
$from = 'plugin_item_status_log l LEFT JOIN mst_item_status os ON os.item_status_id=l.old_status_id LEFT JOIN mst_item_status ns ON ns.item_status_id=l.new_status_id LEFT JOIN user u ON u.user_id=l.uid';
$cols = "l.changed_at,l.item_code,l.title,l.publish_year,COALESCE(os.item_status_name,IF(l.old_status_id IS NULL OR l.old_status_id='', 'Tersedia',l.old_status_id)) old_name,COALESCE(ns.item_status_name,IF(l.new_status_id IS NULL OR l.new_status_id='', 'Tersedia',l.new_status_id)) new_name,COALESCE(u.realname,u.username,CAST(l.uid AS CHAR)) actor,l.uid";
if ($ready && $mode === 'export') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan_perubahan_status_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\u{FEFF}");
    fputcsv($out, ['Waktu', 'Kode Eksemplar', 'Judul', 'Tahun', 'Status Lama', 'Status Baru', 'Pustakawan', 'UID']);
    $rs = $dbs->query("SELECT $cols FROM $from WHERE $wc ORDER BY l.changed_at DESC,l.log_id DESC");
    if ($rs)
        while ($r = $rs->fetch_assoc())
            fputcsv($out, array_map('usb_csv', array_values($r)));
    fclose($out);
    exit;
}
?>
<style>
.usb-wrap{padding:10px 15px}.usb-title{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.usb-panel{border:1px solid #dce2e8;border-radius:8px;padding:18px;margin:15px 0;background:#fff}.usb-fields{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.usb-fields label{display:block;font-weight:600;margin-bottom:5px}.usb-fields select{min-width:240px}.usb-fields input[type=text]{min-width:270px}.usb-table{width:100%;border-collapse:collapse}.usb-table th,.usb-table td{padding:10px;border-bottom:1px solid #ddd;vertical-align:top}.usb-table th{background:#f5f7fa}.usb-pages{display:flex;justify-content:center;gap:12px;margin:18px}.usb-note{font-size:13px;color:#596579}
</style>
<div class="usb-wrap">
<div class="usb-title"><h2>Ubah Status Buku</h2><div><a class="btn btn-default" href="<?= usb_h(usb_route(['view' => 'scan'])) ?>">Scan Barcode</a> <a class="btn btn-default" href="<?= usb_h(usb_route(['view' => 'report'])) ?>">Log &amp; Laporan</a></div></div>
<?php if (!$ready): ?><div class="alert alert-danger">Tabel audit belum dapat dibuat. Periksa izin CREATE TABLE database.</div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="alert alert-<?= usb_h($kind) ?>"><?= usb_h($notice) ?></div><?php endif; ?>
<?php if ($mode !== 'report'): ?>
<div class="usb-panel"><h3>Scan Barcode untuk Mengubah Status</h3><p class="usb-note">Pilih status tujuan, lalu scan barcode satu per satu. Scanner harus mengirim tombol Enter setelah barcode terbaca.</p>
<form method="post" action="<?= usb_h(usb_route(['view' => 'scan'])) ?>" id="usb-scan-form" onsubmit="event.preventDefault();var f=this;if(f.dataset.busy==='1')return false;f.dataset.busy='1';var b=f.querySelector('button[type=submit]');if(b)b.disabled=true;var x=new XMLHttpRequest();x.open('POST',f.action,true);x.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');x.setRequestHeader('X-Requested-With','XMLHttpRequest');x.onload=function(){f.dataset.busy='0';if(b)b.disabled=false;if(x.status!==200){alert('Gagal menyimpan status (HTTP '+x.status+')');return;}var d=document.createElement('div');d.innerHTML=x.responseText;var n=d.querySelector('.usb-wrap'),c=document.querySelector('.usb-wrap');if(!n||!c){alert('Respons server tidak valid');return;}var st=f.querySelector('[name=status_id]').value;c.innerHTML=n.innerHTML;var ns=c.querySelector('[name=status_id]');if(ns)ns.value=st;var bc=c.querySelector('[name=item_code]');if(bc)bc.focus();};x.onerror=function(){f.dataset.busy='0';if(b)b.disabled=false;alert('Koneksi gagal');};x.send(new URLSearchParams(new FormData(f)).toString());return false;">
<input type="hidden" name="mod" value="<?= usb_h($_GET['mod'] ?? 'bibliography') ?>"><input type="hidden" name="id" value="<?= usb_h($_GET['id'] ?? '') ?>"><input type="hidden" name="scan_item" value="1">
<div class="usb-fields"><div><label>Status Tujuan</label><select class="form-control" name="status_id" id="usb-status" required><option value="">— Pilih Status —</option><?php foreach ($statuses as $st): ?><option value="<?= usb_h($st['item_status_id']) ?>" <?= $selected === $st['item_status_id'] ? 'selected' : '' ?>><?= usb_h($st['item_status_name']) ?><?= $st['item_status_id'] === '__AVAILABLE__' ? ' (NULL)' : ' (' . usb_h($st['item_status_id']) . ')' ?></option><?php endforeach; ?></select></div>
<div><label>Barcode / Kode Eksemplar</label><input class="form-control" name="item_code" id="usb-barcode" type="text" autocomplete="off" autofocus required placeholder="Scan barcode di sini"></div><button class="btn btn-success" type="submit">Ubah Status</button></div></form></div>
<h3>Perubahan Status Terbaru</h3>
<?php else: ?>
<div class="usb-panel"><h3>Filter Laporan</h3><form method="get" action="<?= usb_h($_SERVER['PHP_SELF']) ?>">
<?php foreach ($_GET as $k => $v):
        if (!in_array($k, ['start', 'end', 'q', 'filter_status', 'page'], true) && !is_array($v)): ?><input type="hidden" name="<?= usb_h($k) ?>" value="<?= usb_h($v) ?>"><?php endif;
    endforeach; ?><input type="hidden" name="view" value="report">
<div class="usb-fields"><div><label>Dari Tanggal</label><input class="form-control" type="date" name="start" value="<?= usb_h($start) ?>"></div><div><label>Sampai Tanggal</label><input class="form-control" type="date" name="end" value="<?= usb_h($end) ?>"></div><div><label>Judul / Kode</label><input class="form-control" type="text" name="q" value="<?= usb_h($search) ?>"></div><div><label>Status Baru</label><select class="form-control" name="filter_status"><option value="">Semua Status</option><?php foreach ($statuses as $st): ?><option value="<?= usb_h($st['item_status_id']) ?>" <?= $filterStatus === $st['item_status_id'] ? 'selected' : '' ?>><?= usb_h($st['item_status_name']) ?></option><?php endforeach; ?></select></div><button class="btn btn-primary" type="submit">Tampilkan</button></div></form>
<p style="margin-top:12px"><a class="btn btn-success notAJAX" href="<?= usb_h(usb_route(['view' => 'export', 'page' => null])) ?>" target="_blank">Ekspor CSV</a></p></div>
<?php endif; ?>
<?php
$page = max(1, (int) ($_GET['page'] ?? 1));
$size = 20;
$offset = ($page - 1) * $size;
$total = 0;
if ($ready) {
    $count = $dbs->query("SELECT COUNT(*) n FROM $from WHERE $wc");
    if ($count)
        $total = (int) $count->fetch_assoc()['n'];
}
$pages = max(1, (int) ceil($total / $size));
if ($page > $pages)
    $page = $pages;
$offset = ($page - 1) * $size;
$rows = $ready ? $dbs->query("SELECT $cols FROM $from WHERE $wc ORDER BY l.changed_at DESC,l.log_id DESC LIMIT $offset,$size") : false;
?>
<div class="table-responsive"><table class="usb-table table table-striped"><thead><tr><th>Waktu</th><th>Judul Buku</th><th>No. Eksemplar</th><th>Tahun</th><th>Status Lama</th><th>Status Baru</th><th>Diubah Oleh</th></tr></thead><tbody>
<?php if ($rows && $rows->num_rows):
    while ($r = $rows->fetch_assoc()): ?><tr><td><?= usb_h($r['changed_at']) ?></td><td><?= usb_h($r['title']) ?></td><td><?= usb_h($r['item_code']) ?></td><td><?= usb_h($r['publish_year']) ?></td><td><?= usb_h($r['old_name']) ?></td><td><strong><?= usb_h($r['new_name']) ?></strong></td><td><?= usb_h($r['actor']) ?> <small>(UID <?= usb_h($r['uid']) ?>)</small></td></tr><?php endwhile;
else: ?><tr><td colspan="7" class="text-center">Belum ada riwayat perubahan status.</td></tr><?php endif; ?></tbody></table></div>
<div class="usb-pages"><?php if ($page > 1): ?><a class="btn btn-default" href="<?= usb_h(usb_route(['page' => $page - 1])) ?>">‹ Sebelumnya</a><?php endif; ?><span>Halaman <?= $page ?> dari <?= $pages ?> (<?= $total ?> log)</span><?php if ($page < $pages): ?><a class="btn btn-default" href="<?= usb_h(usb_route(['page' => $page + 1])) ?>">Berikutnya ›</a><?php endif; ?></div>
</div>
<script>
(function(){var f=document.getElementById('usb-scan-form');if(f){var b=f.querySelector('[name=item_code]');if(b)b.focus();}})();
</script>
