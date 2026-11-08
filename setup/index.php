<?php
/**
 * HALAMAN SETUP HRIS (sekali pakai)
 * ----------------------------------------------------------------------
 * Buka: https://domainanda.com/setup/
 *  1. Buat tabel database
 *  2. Import data lama dari Google Spreadsheet (file JSON hasil fungsi exportForHostinger di Apps Script)
 *  3. (Opsional) Buat akun Admin pertama jika tidak import data
 * SETELAH SELESAI: HAPUS folder setup/ dari File Manager Hostinger.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api/lib.php';

@set_time_limit(600);
@ini_set('memory_limit', '512M');

$messages = [];
$errors = [];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try {
    $cfg = config();
} catch (Throwable $e) {
    $cfg = null;
    $errors[] = $e->getMessage();
}

$keyOk = false;
if ($cfg && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $given = (string)($_POST['setup_key'] ?? '');
    $real = (string)($cfg['setup_key'] ?? '');
    if ($real === '' || str_starts_with($real, 'GANTI_')) {
        $errors[] = 'setup_key di api/config.php belum diganti. Ganti dulu dengan teks rahasia.';
    } elseif (!hash_equals($real, $given)) {
        $errors[] = 'Setup key salah.';
    } else {
        $keyOk = true;
    }
}

if ($keyOk) {
    try {
        $step = $_POST['step'] ?? '';
        if ($step === 'install') {
            run_schema();
            $messages[] = 'Tabel database berhasil dibuat / sudah ada.';
        } elseif ($step === 'import') {
            run_schema();
            if (empty($_FILES['json']['tmp_name']) || !is_uploaded_file($_FILES['json']['tmp_name'])) {
                throw new RuntimeException('File JSON belum dipilih atau gagal di-upload (cek batas upload PHP di hPanel).');
            }
            $json = json_decode((string)file_get_contents($_FILES['json']['tmp_name']), true);
            if (!is_array($json)) throw new RuntimeException('File bukan JSON yang valid.');
            $sheets = $json['sheets'] ?? $json;
            $report = import_sheets($sheets, !empty($_POST['wipe']));
            foreach ($report as $line) $messages[] = $line;
        } elseif ($step === 'admin') {
            run_schema();
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $name = trim((string)($_POST['name'] ?? ''));
            $pass = (string)($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($pass) < 8) {
                throw new RuntimeException('Isi nama, email valid, dan password minimal 8 karakter.');
            }
            if (q_val('SELECT 1 FROM users WHERE LOWER(email) = ?', [$email])) throw new RuntimeException('Email sudah terdaftar.');
            $id = next_seq_id('users', 'emp_id', 'EMP');
            q("INSERT INTO users (emp_id, full_name, email, password_hash, role, position, department, join_date)
               VALUES (?, ?, ?, ?, 'ADMIN', 'HR Manager', 'Human Resource', CURDATE())",
                [$id, $name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            $messages[] = "Akun admin $id ($email) berhasil dibuat.";
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$status = null;
if ($cfg) {
    try {
        $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $status = ['tables' => $tables];
        if (in_array('users', $tables, true)) {
            foreach (BACKUP_TABLES as $t) $status['count'][$t] = in_array($t, $tables, true) ? (int)q_val("SELECT COUNT(*) FROM `$t`") : '-';
        }
    } catch (Throwable $e) {
        $errors[] = 'Tidak bisa terhubung ke database: ' . $e->getMessage();
    }
}

/* ------------------------------------------------------------------ */

function run_schema(): void
{
    $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        db()->exec($stmt);
    }
}

/** Ambil nilai kolom dari baris spreadsheet (tidak peka huruf besar/kecil & spasi). */
function col(array $row, string $key, $default = '')
{
    foreach ($row as $k => $v) {
        if (strtoupper(trim((string)$k)) === $key) return is_string($v) ? trim($v) : $v;
    }
    return $default;
}

/**
 * Pengaturan khusus dari kode lama (yang dulu ditulis langsung di Code.gs):
 *  - Irfan (EMP-0008)  : REMOTE + bebas bonus
 *  - Ricardo (EMP-0012), Erna (EMP-0013) : bebas bonus (direksi)
 *  - Riana (EMP-0003)  : SUDAH TIDAK remote -> karyawan kantor biasa (wajib absen GPS, dapat bonus tepat waktu)
 *  - Jabatan/departemen yang mengandung kata 'remote' / 'wfh' -> REMOTE (cek hasilnya setelah import!)
 * Setelah import, semua ini bisa diubah admin lewat menu Data Karyawan -> Edit.
 */
function legacy_flags(string $empId, string $name, string $position = '', string $dept = ''): array
{
    $id = strtoupper($empId);
    $n = strtolower($name);
    $pd = strtolower($position . ' ' . $dept);
    $remote = $id === 'EMP-0008' || preg_match('/\birfan\b/', $n) || str_contains($pd, 'remote') || str_contains($pd, 'wfh');
    $exempt = $remote || in_array($id, ['EMP-0012', 'EMP-0013'], true)
        || str_contains($n, 'ricardo') || str_contains($n, 'simbolon') || preg_match('/\berna\b/', $n);
    return [$remote ? 'REMOTE' : 'OFFICE', $exempt ? 1 : 0];
}

function import_photo($value, string $sub): string
{
    $v = trim((string)$value);
    if ($v === '' || $v === '-') return '-';
    if (str_starts_with($v, 'data:')) {
        try {
            return save_data_url($v, $sub, IMAGE_MIMES, 5 * 1048576);
        } catch (Throwable $e) {
            return '-';
        }
    }
    return mb_substr($v, 0, 500);
}

function import_sheets(array $s, bool $wipe): array
{
    $report = [];
    $pdo = db();
    if ($wipe) {
        foreach (['sessions', 'attendance', 'requests', 'payslips', 'agendas', 'duties', 'users'] as $t) $pdo->exec("DELETE FROM `$t`");
        $report[] = 'Data lama di database dikosongkan.';
    }

    // SETTINGS
    $n = 0;
    foreach (($s['SETTINGS'] ?? []) as $r) {
        $k = (string)col($r, 'KEY');
        if ($k === '') continue;
        $v = (string)col($r, 'VALUE');
        if (in_array($k, ['OFFICE_LAT', 'OFFICE_LNG'], true)) { $c = parse_coord($v); if ($c === null) continue; $v = (string)$c; }
        q('INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v, (string)col($r, 'DESCRIPTION')]);
        $n++;
    }
    $report[] = "SETTINGS: $n pengaturan diimport.";

    // USERS
    $n = 0; $skip = 0; $emails = [];
    foreach (($s['USERS'] ?? []) as $r) {
        $id = strtoupper((string)col($r, 'EMP_ID'));
        $email = strtolower((string)col($r, 'EMAIL'));
        $name = (string)col($r, 'FULL_NAME');
        if ($id === '' || $email === '' || isset($emails[$email])) { $skip++; continue; }
        $emails[$email] = true;
        $pass = (string)col($r, 'PASSWORD');
        if ($pass === '') $pass = bin2hex(random_bytes(6));
        [$mode, $exempt] = legacy_flags($id, $name, (string)col($r, 'POSITION'), (string)col($r, 'DEPARTMENT'));
        q('REPLACE INTO users (emp_id, full_name, email, password_hash, role, position, department, join_date, leave_balance,
             saved_holidays, contract_doc_url, bpjs_doc_url, basic_salary, allowance, profile_pic, bpjs_tk_amount, has_bpjs_kes,
             bpjs_kes_amount, kasbon_amount, encashed_holidays, work_mode, bonus_exempt)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $name, $email, password_hash($pass, PASSWORD_DEFAULT),
            strtoupper((string)col($r, 'ROLE')) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
            (string)col($r, 'POSITION'), (string)col($r, 'DEPARTMENT'), parse_date_any(col($r, 'JOIN_DATE')),
            (int)parse_money(col($r, 'LEAVE_BALANCE', '12')), (int)parse_money(col($r, 'SAVED_HOLIDAYS', '0')),
            (string)col($r, 'CONTRACT_DOC_URL') ?: '-', (string)col($r, 'BPJS_DOC_URL') ?: '-',
            parse_money(col($r, 'BASIC_SALARY')), parse_money(col($r, 'ALLOWANCE')), import_photo(col($r, 'PROFILE_PIC'), 'profile'),
            parse_money(col($r, 'BPJS_TK_AMOUNT')), to_bool(col($r, 'HAS_BPJS_KES')) ? 1 : 0, parse_money(col($r, 'BPJS_KES_AMOUNT')),
            parse_money(col($r, 'KASBON_AMOUNT')), (int)parse_money(col($r, 'ENCASHED_HOLIDAYS')), $mode, $exempt,
        ]);
        $n++;
    }
    $report[] = "USERS: $n akun karyawan diimport (password lama tetap bisa dipakai, sekarang tersimpan ter-enkripsi)." . ($skip ? " $skip baris dilewati (ID/email kosong atau email dobel)." : '');

    // ATTENDANCE
    $n = 0; $skip = 0;
    foreach (($s['ATTENDANCE'] ?? []) as $r) {
        $date = parse_date_any(col($r, 'DATE'));
        $emp = strtoupper((string)col($r, 'EMP_ID'));
        if (!$date || $emp === '') { $skip++; continue; }
        if (q_val('SELECT 1 FROM attendance WHERE emp_id = ? AND date = ?', [$emp, $date])) { $skip++; continue; }
        $id = (string)col($r, 'ATT_ID');
        if ($id === '' || strlen($id) > 20 || q_val('SELECT 1 FROM attendance WHERE att_id = ?', [$id])) $id = random_id('attendance', 'att_id', 'ATT');
        q('INSERT INTO attendance (att_id, date, emp_id, full_name, checkin_time, checkout_time, latitude, longitude, distance_meters,
             checkin_photo, checkout_photo, status, location_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $date, $emp, (string)col($r, 'FULL_NAME'), parse_time_any(col($r, 'CHECKIN_TIME')), parse_time_any(col($r, 'CHECKOUT_TIME')),
            parse_coord(col($r, 'LATITUDE')), parse_coord(col($r, 'LONGITUDE')), parse_coord(col($r, 'DISTANCE_METERS')),
            import_photo(col($r, 'CHECKIN_PHOTO'), 'attendance'), import_photo(col($r, 'CHECKOUT_PHOTO'), 'attendance'),
            strtoupper((string)col($r, 'STATUS')) === 'TERLAMBAT' ? 'TERLAMBAT' : 'TEPAT WAKTU',
            (string)col($r, 'LOCATION_NAME') ?: 'PT Artha Safety Indonesia - Kantor Pusat HQ',
        ]);
        $n++;
    }
    $report[] = "ATTENDANCE: $n data absensi diimport." . ($skip ? " $skip baris dilewati (tanggal tidak terbaca / dobel di hari yang sama)." : '');

    // REQUESTS
    $n = 0;
    foreach (($s['REQUESTS'] ?? []) as $r) {
        $id = (string)col($r, 'REQ_ID');
        if ($id === '' || q_val('SELECT 1 FROM requests WHERE req_id = ?', [$id])) $id = next_seq_id('requests', 'req_id', 'REQ');
        $start = parse_date_any(col($r, 'START_DATE'));
        q('INSERT INTO requests (req_id, submit_date, emp_id, full_name, type, start_date, end_date, reason, doc_attachment, status, approved_by, admin_note)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, parse_date_any(col($r, 'SUBMIT_DATE')), strtoupper((string)col($r, 'EMP_ID')), (string)col($r, 'FULL_NAME'),
            (string)col($r, 'TYPE'), $start, parse_date_any(col($r, 'END_DATE')) ?? $start, (string)col($r, 'REASON'),
            (string)col($r, 'DOC_ATTACHMENT') ?: '-', strtoupper((string)col($r, 'STATUS')) ?: 'PENDING',
            (string)col($r, 'APPROVED_BY') ?: '-', (string)col($r, 'ADMIN_NOTE') ?: '-',
        ]);
        $n++;
    }
    $report[] = "REQUESTS: $n pengajuan diimport.";

    // PAYSLIPS
    $n = 0;
    $numCols = ['BASIC_SALARY', 'ALLOWANCE', 'ON_TIME_BONUS', 'ENCASHED_HOLIDAYS', 'ENCASHED_HOLIDAY_PAY', 'LATE_COUNT',
        'EXCUSED_LATE_COUNT', 'LATE_DEDUCTION', 'BPJS_TK_AMOUNT', 'BPJS_KES_AMOUNT', 'KASBON_AMOUNT', 'DEDUCTIONS', 'NET_SALARY'];
    foreach (($s['PAYSLIPS'] ?? []) as $r) {
        $id = (string)col($r, 'PAY_ID');
        if ($id === '' || q_val('SELECT 1 FROM payslips WHERE pay_id = ?', [$id])) $id = random_id('payslips', 'pay_id', 'PAY');
        $vals = [$id, (string)col($r, 'PERIOD'), strtoupper((string)col($r, 'EMP_ID')), (string)col($r, 'FULL_NAME'),
            (string)col($r, 'POSITION'), (string)col($r, 'DEPARTMENT')];
        foreach ($numCols as $c) $vals[] = parse_money(col($r, $c, '0'));
        $vals[] = to_bool(col($r, 'HAS_BPJS_KES')) ? 1 : 0;
        array_push($vals, (string)col($r, 'PAYMENT_DATE'), (string)col($r, 'STATUS') ?: 'TERBIT',
            (string)col($r, 'FINANCE_NAME'), (string)col($r, 'LEADER_NAME'));
        q('INSERT INTO payslips (pay_id, period, emp_id, full_name, position, department, basic_salary, allowance, on_time_bonus,
             encashed_holidays, encashed_holiday_pay, late_count, excused_late_count, late_deduction, bpjs_tk_amount, bpjs_kes_amount,
             kasbon_amount, deductions, net_salary, has_bpjs_kes, payment_date, status, finance_name, leader_name)
           VALUES (' . implode(',', array_fill(0, 24, '?')) . ')', $vals);
        $n++;
    }
    $report[] = "PAYSLIPS: $n slip gaji diimport.";

    // AGENDAS
    $n = 0;
    foreach (($s['AGENDAS'] ?? []) as $r) {
        $id = (string)col($r, 'AGENDA_ID');
        if ($id === '' || q_val('SELECT 1 FROM agendas WHERE agenda_id = ?', [$id])) $id = next_seq_id('agendas', 'agenda_id', 'AGD');
        $created = parse_date_any(col($r, 'CREATED_AT'));
        $createdTime = parse_time_any(trim((string)preg_replace('/^\S+\s*/', '', (string)col($r, 'CREATED_AT')))) ?? '00:00:00';
        q('INSERT INTO agendas (agenda_id, date, title, description, category, created_by, created_by_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, parse_date_any(col($r, 'DATE')), (string)col($r, 'TITLE'), (string)col($r, 'DESCRIPTION'), (string)col($r, 'CATEGORY'),
            (string)col($r, 'CREATED_BY'), strtoupper((string)col($r, 'CREATED_BY_ID')), $created ? "$created $createdTime" : date('Y-m-d H:i:s'),
        ]);
        $n++;
    }
    $report[] = "AGENDAS: $n agenda diimport.";

    // DUTIES
    $n = 0; $skip = 0;
    foreach (($s['DUTIES'] ?? []) as $r) {
        $lat = parse_coord(col($r, 'LATITUDE'));
        $lng = parse_coord(col($r, 'LONGITUDE'));
        $start = parse_date_any(col($r, 'START_DATE'));
        $end = parse_date_any(col($r, 'END_DATE')) ?? $start;
        if ($lat === null || $lng === null || !$start) { $skip++; continue; }
        $id = (string)col($r, 'DUTY_ID');
        if ($id === '' || q_val('SELECT 1 FROM duties WHERE duty_id = ?', [$id])) $id = next_seq_id('duties', 'duty_id', 'DUTY');
        q('INSERT INTO duties (duty_id, emp_id, full_name, location_name, latitude, longitude, radius_meters, start_date, end_date, reason, created_by)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, strtoupper((string)col($r, 'EMP_ID')), (string)col($r, 'FULL_NAME'), (string)col($r, 'LOCATION_NAME'), $lat, $lng,
            max(10, (int)parse_money(col($r, 'RADIUS_METERS', '50'))), $start, $end, (string)col($r, 'REASON'), (string)col($r, 'CREATED_BY'),
        ]);
        $n++;
    }
    $report[] = "DUTIES: $n tugas dinas diimport." . ($skip ? " $skip baris dilewati (koordinat/tanggal tidak terbaca)." : '');

    return $report;
}
?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Setup HRIS</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #f1f5f9; color: #0f172a; margin: 0; padding: 16px; }
    .wrap { max-width: 720px; margin: 0 auto; }
    .card { background: #fff; border-radius: 16px; padding: 20px; margin-bottom: 16px; border: 1px solid #e2e8f0; }
    h1 { font-size: 20px; } h2 { font-size: 16px; margin-top: 0; }
    label { display: block; font-size: 13px; font-weight: 600; margin: 10px 0 4px; }
    input[type=text], input[type=email], input[type=password] { width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 10px; }
    button { margin-top: 12px; background: #059669; color: #fff; border: 0; padding: 10px 18px; border-radius: 10px; font-weight: 700; cursor: pointer; }
    .ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px 14px; border-radius: 10px; margin-bottom: 8px; font-size: 14px; }
    .err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 10px 14px; border-radius: 10px; margin-bottom: 8px; font-size: 14px; }
    .warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 10px 14px; border-radius: 10px; font-size: 13px; }
    table { border-collapse: collapse; font-size: 13px; } td { padding: 3px 12px 3px 0; }
    small { color: #64748b; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>Setup HRIS PT Artha Safety Indonesia</h1>
  <?php foreach ($messages as $m): ?><div class="ok">✅ <?= h($m) ?></div><?php endforeach; ?>
  <?php foreach ($errors as $m): ?><div class="err">⚠️ <?= h($m) ?></div><?php endforeach; ?>

  <div class="card">
    <h2>Status Database</h2>
    <?php if ($status && !empty($status['count'])): ?>
      <table><?php foreach ($status['count'] as $t => $c): ?><tr><td><?= h($t) ?></td><td><b><?= h($c) ?></b> baris</td></tr><?php endforeach; ?></table>
    <?php elseif ($status): ?>
      <p>Terhubung ke database, tabel belum dibuat.</p>
    <?php else: ?>
      <p>Belum terhubung. Periksa api/config.php.</p>
    <?php endif; ?>
  </div>

  <?php if ($cfg): ?>
  <div class="card">
    <h2>1. Buat Tabel Database</h2>
    <form method="post">
      <input type="hidden" name="step" value="install">
      <label>Setup key (dari api/config.php)</label>
      <input type="password" name="setup_key" required>
      <button type="submit">Buat Tabel</button>
    </form>
  </div>

  <div class="card">
    <h2>2. Import Data dari Google Spreadsheet</h2>
    <p><small>Di Apps Script jalankan fungsi <b>exportForHostinger</b>, lalu download file JSON dari Google Drive dan upload di sini.</small></p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="step" value="import">
      <label>Setup key</label>
      <input type="password" name="setup_key" required>
      <label>File JSON hasil export</label>
      <input type="file" name="json" accept=".json,application/json" required>
      <label><input type="checkbox" name="wipe" value="1" checked> Kosongkan data di database sebelum import (disarankan saat pertama kali)</label>
      <button type="submit">Import Data</button>
    </form>
  </div>

  <div class="card">
    <h2>3. (Opsional) Buat Akun Admin Baru</h2>
    <p><small>Hanya perlu jika Anda tidak import data lama.</small></p>
    <form method="post">
      <input type="hidden" name="step" value="admin">
      <label>Setup key</label>
      <input type="password" name="setup_key" required>
      <label>Nama lengkap</label>
      <input type="text" name="name" required>
      <label>Email</label>
      <input type="email" name="email" required>
      <label>Password (min. 8 karakter)</label>
      <input type="password" name="password" minlength="8" required>
      <button type="submit">Buat Admin</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="warn">Setelah setup selesai dan Anda berhasil login, <b>hapus folder <code>setup/</code></b> lewat hPanel → File Manager.</div>
</div>
</body>
</html>
