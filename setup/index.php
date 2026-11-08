<?php
/**
 * HALAMAN SETUP HRIS (sekali pakai)
 * ----------------------------------------------------------------------
 * Buka: https://domainanda.com/setup/
 *  1. Buat tabel database
 *  2. Import SELURUH data lama dari Google Spreadsheet (file JSON hasil fungsi exportForHostinger di Apps Script)
 *  3. (Opsional) Buat akun Admin pertama jika tidak import data
 * SETELAH SELESAI: HAPUS folder setup/ dari File Manager Hostinger.
 *
 * Prinsip import: TIDAK ADA DATA YANG DIBUANG DIAM-DIAM.
 * Setiap baris spreadsheet dihitung; baris yang tidak bisa masuk ditampilkan lengkap
 * dengan nomor baris & alasannya, supaya bisa diperbaiki lalu diimport ulang.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api/lib.php';

@set_time_limit(1800);
@ini_set('memory_limit', '1024M');

$messages = [];
$errors = [];
$reportTables = [];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try {
    $cfg = config();
} catch (Throwable $e) {
    $cfg = null;
    $errors[] = $e->getMessage();
}

$keyOk = false;
if ($cfg && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'Ukuran file yang diupload melebihi batas server (post_max_size = ' . ini_get('post_max_size')
            . '). Upload file part satu per satu, atau naikkan batas di hPanel → Advanced → PHP Configuration.';
    } else {
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
}

if ($keyOk) {
    try {
        $step = $_POST['step'] ?? '';
        if ($step === 'install') {
            run_schema();
            $messages[] = 'Tabel database berhasil dibuat / sudah ada.';
        } elseif ($step === 'import') {
            run_schema();
            $parts = read_uploaded_parts();
            $result = import_parts($parts, !empty($_POST['wipe']));
            $messages = array_merge($messages, $result['messages']);
            $errors = array_merge($errors, $result['errors']);
            $reportTables = $result['tables'];
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

/* ================================================================== */

function run_schema(): void
{
    $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        db()->exec($stmt);
    }
}

/** Baca semua file JSON yang diupload, urutkan berdasarkan nomor part. */
function read_uploaded_parts(): array
{
    $f = $_FILES['json'] ?? null;
    if (!$f) throw new RuntimeException('File JSON belum dipilih.');
    $names = (array)$f['name']; $tmps = (array)$f['tmp_name']; $errs = (array)$f['error'];
    $parts = [];
    foreach ($tmps as $i => $tmp) {
        $name = (string)$names[$i];
        if ((int)$errs[$i] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$tmp)) {
            throw new RuntimeException("File $name gagal di-upload (kode error {$errs[$i]}). Kemungkinan file terlalu besar: upload satu per satu "
                . '(upload_max_filesize = ' . ini_get('upload_max_filesize') . ').');
        }
        $json = json_decode((string)file_get_contents((string)$tmp), true);
        if (!is_array($json)) throw new RuntimeException("File $name bukan JSON yang valid / rusak saat download.");
        $json['_file'] = $name;
        if (!isset($json['sheets'])) $json = ['sheets' => $json, '_file' => $name, 'part' => 1, 'totalParts' => 1];
        $parts[] = $json;
    }
    if (!$parts) throw new RuntimeException('File JSON belum dipilih.');
    usort($parts, fn($a, $b) => ((int)($a['part'] ?? 1)) <=> ((int)($b['part'] ?? 1)));
    return $parts;
}

/** Ambil nilai kolom dari baris spreadsheet (tidak peka huruf besar/kecil & spasi). */
function col(array $row, string $key, $default = '')
{
    foreach ($row as $k => $v) {
        if (strtoupper(trim((string)$k)) === $key) return is_string($v) ? trim($v) : $v;
    }
    return $default;
}

function str_col(array $row, string $key, string $default = ''): string
{
    $v = col($row, $key, $default);
    return $v === null ? $default : trim((string)$v);
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

/** Foto base64 -> file di uploads/. URL (Google Drive dll.) dibiarkan apa adanya. */
function import_photo($value, string $sub, array &$warn, string $ctx): string
{
    $v = trim((string)$value);
    if ($v === '' || $v === '-') return '-';
    if (str_starts_with($v, 'data:')) {
        try {
            return save_data_url($v, $sub, IMAGE_MIMES, 10 * 1048576);
        } catch (Throwable $e) {
            $warn[] = "$ctx: foto tidak bisa disimpan (" . $e->getMessage() . '). Data lain tetap diimport.';
            return '-';
        }
    }
    if (strlen($v) > 500) {
        $warn[] = "$ctx: isi kolom foto bukan gambar/URL yang dikenali, dikosongkan.";
        return '-';
    }
    return $v;
}

final class SheetReport
{
    public int $source = 0;
    public int $imported = 0;
    public int $merged = 0;
    public int $existing = 0;
    /** @var string[] */
    public array $skipped = [];
    /** @var string[] */
    public array $warnings = [];
}

function rowref(array $r, string $sheet): string
{
    $n = $r['_ROW'] ?? null;
    return $sheet . ($n ? " baris $n" : '');
}

/**
 * ID dari spreadsheet dipertahankan. Jika ID yang sama muncul 2x di spreadsheet (bisa terjadi di versi lama
 * karena ID dibuat dari jumlah baris / angka acak), baris kedua tetap diimport dengan ID baru.
 * Jika ID sudah ada di database dari import sebelumnya (tanpa centang "kosongkan"), baris dianggap sudah ada.
 */
function resolve_id(string $table, string $col, string $id, array &$seen, callable $newId): ?string
{
    $id = trim($id);
    if ($id !== '' && strlen($id) <= 20 && !isset($seen[$id])) {
        $seen[$id] = true;
        if (q_val("SELECT 1 FROM $table WHERE $col = ?", [$id])) return null; // sudah ada
        return $id;
    }
    $nid = $newId();
    $seen[$nid] = true;
    return $nid;
}

function import_parts(array $parts, bool $wipe): array
{
    $messages = []; $errors = [];
    $hasPart1 = false;
    $exportIds = [];
    $total = 0;
    foreach ($parts as $p) {
        if ((int)($p['part'] ?? 1) === 1) $hasPart1 = true;
        if (!empty($p['exportId'])) $exportIds[$p['exportId']] = true;
        $total = max($total, (int)($p['totalParts'] ?? 1));
    }
    if (count($exportIds) > 1) {
        throw new RuntimeException('File yang diupload berasal dari export yang berbeda. Upload hanya file dari SATU folder export yang sama.');
    }
    if ($wipe && !$hasPart1) {
        throw new RuntimeException('Opsi "Kosongkan data" hanya boleh dicentang saat mengupload part 1. Untuk part berikutnya hilangkan centangnya (agar part 1 tidak terhapus).');
    }
    if ($total > 1) {
        $got = array_map(fn($p) => (int)($p['part'] ?? 1), $parts);
        $messages[] = 'File diterima: part ' . implode(', ', $got) . ' dari total ' . $total . ' part.';
        if (count(array_unique($got)) < $total) {
            $errors[] = 'Belum semua part diupload. Upload part yang tersisa (tanpa centang "Kosongkan data") agar seluruh data absensi masuk.';
        }
    }

    $pdo = db();
    if ($wipe) {
        foreach (['sessions', 'attendance', 'requests', 'payslips', 'agendas', 'duties', 'users'] as $t) $pdo->exec("DELETE FROM `$t`");
        $messages[] = 'Data lama di database Hostinger dikosongkan sebelum import.';
    }

    $rep = [];
    foreach (['SETTINGS', 'USERS', 'ATTENDANCE', 'REQUESTS', 'PAYSLIPS', 'AGENDAS', 'DUTIES'] as $s) $rep[$s] = new SheetReport();

    $expected = $parts[0]['counts'] ?? null;
    foreach ($parts as $p) {
        $sheets = $p['sheets'] ?? [];
        import_settings($sheets['SETTINGS'] ?? [], $rep['SETTINGS']);
        import_users($sheets['USERS'] ?? [], $rep['USERS']);
        import_attendance($sheets['ATTENDANCE'] ?? [], $rep['ATTENDANCE']);
        import_requests($sheets['REQUESTS'] ?? [], $rep['REQUESTS']);
        import_payslips($sheets['PAYSLIPS'] ?? [], $rep['PAYSLIPS']);
        import_agendas($sheets['AGENDAS'] ?? [], $rep['AGENDAS']);
        import_duties($sheets['DUTIES'] ?? [], $rep['DUTIES']);
    }

    $tables = [];
    foreach ($rep as $sheet => $r) {
        $accounted = $r->imported + $r->merged + $r->existing + count($r->skipped);
        $tables[] = [
            'sheet' => $sheet,
            'expected' => $expected[$sheet] ?? null,
            'source' => $r->source,
            'imported' => $r->imported,
            'merged' => $r->merged,
            'existing' => $r->existing,
            'skipped' => $r->skipped,
            'warnings' => $r->warnings,
            'ok' => $accounted === $r->source,
            // Sheet selain ATTENDANCE hanya ada di part 1
            'notInUpload' => !$hasPart1 && $sheet !== 'ATTENDANCE',
        ];
        if (count($r->skipped)) $errors[] = "$sheet: " . count($r->skipped) . ' baris TIDAK masuk — lihat detail di tabel laporan di bawah.';
    }
    $sumSkipped = array_sum(array_map(fn($t) => count($t['skipped']), $tables));
    if ($sumSkipped === 0 && !$errors) {
        $messages[] = 'SEMUA baris dari spreadsheet berhasil masuk ke database Hostinger.';
    }
    return ['messages' => $messages, 'errors' => $errors, 'tables' => $tables];
}

function import_settings(array $rows, SheetReport $rep): void
{
    foreach ($rows as $r) {
        $rep->source++;
        $k = str_col($r, 'KEY');
        if ($k === '') { $rep->skipped[] = rowref($r, 'SETTINGS') . ': KEY kosong'; continue; }
        $v = str_col($r, 'VALUE');
        if (in_array($k, ['OFFICE_LAT', 'OFFICE_LNG'], true)) {
            $c = parse_coord($v);
            if ($c === null) { $rep->skipped[] = rowref($r, 'SETTINGS') . ": nilai $k tidak valid ('$v')"; continue; }
            $v = (string)$c;
        }
        q('INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = IF(VALUES(description) = "", description, VALUES(description))',
            [$k, mb_substr($v, 0, 500), mb_substr(str_col($r, 'DESCRIPTION'), 0, 255)]);
        $rep->imported++;
    }
}

function import_users(array $rows, SheetReport $rep): void
{
    foreach ($rows as $r) {
        $rep->source++;
        $ref = rowref($r, 'USERS');
        $id = strtoupper(str_col($r, 'EMP_ID'));
        $name = str_col($r, 'FULL_NAME');
        if ($id === '') { $rep->skipped[] = "$ref: EMP_ID kosong (nama: " . ($name ?: '-') . ')'; continue; }
        if (strlen($id) > 20) { $rep->skipped[] = "$ref: EMP_ID terlalu panjang ($id)"; continue; }

        $email = strtolower(str_col($r, 'EMAIL'));
        $emailOwner = $email === '' ? null : q_val('SELECT emp_id FROM users WHERE LOWER(email) = ?', [$email]);
        if ($email === '' || ($emailOwner && $emailOwner !== $id)) {
            // Akun tetap diimport (tidak dibuang) dengan email sementara; admin perbaiki di menu Edit Karyawan.
            $placeholder = strtolower($id) . '@ganti-email.local';
            $rep->warnings[] = "$ref ($id $name): email " . ($email === '' ? 'kosong' : "'$email' sudah dipakai $emailOwner")
                . " → diimport dengan email sementara $placeholder. Karyawan ini bisa login pakai ID $id, lalu perbaiki emailnya di menu Edit Karyawan.";
            $email = $placeholder;
        }
        $pass = str_col($r, 'PASSWORD');
        if ($pass === '') {
            $pass = 'hris' . random_int(100000, 999999);
            $rep->warnings[] = "$ref ($id $name): password kosong → dibuatkan password sementara: $pass";
        }
        $exists = (bool)q_val('SELECT 1 FROM users WHERE emp_id = ?', [$id]);
        [$mode, $exempt] = legacy_flags($id, $name, str_col($r, 'POSITION'), str_col($r, 'DEPARTMENT'));
        $photo = import_photo(col($r, 'PROFILE_PIC'), 'profile', $rep->warnings, "$ref ($id)");
        q('REPLACE INTO users (emp_id, full_name, email, password_hash, role, position, department, join_date, leave_balance,
             saved_holidays, contract_doc_url, bpjs_doc_url, basic_salary, allowance, profile_pic, bpjs_tk_amount, has_bpjs_kes,
             bpjs_kes_amount, kasbon_amount, encashed_holidays, work_mode, bonus_exempt)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $name !== '' ? $name : $id, $email, password_hash($pass, PASSWORD_DEFAULT),
            strtoupper(str_col($r, 'ROLE')) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
            str_col($r, 'POSITION'), str_col($r, 'DEPARTMENT'), parse_date_any(col($r, 'JOIN_DATE')),
            parse_money(col($r, 'LEAVE_BALANCE', '12')), parse_money(col($r, 'SAVED_HOLIDAYS', '0')),
            mb_substr(str_col($r, 'CONTRACT_DOC_URL') ?: '-', 0, 500), mb_substr(str_col($r, 'BPJS_DOC_URL') ?: '-', 0, 500),
            parse_money(col($r, 'BASIC_SALARY')), parse_money(col($r, 'ALLOWANCE')), $photo,
            parse_money(col($r, 'BPJS_TK_AMOUNT')), to_bool(col($r, 'HAS_BPJS_KES')) ? 1 : 0, parse_money(col($r, 'BPJS_KES_AMOUNT')),
            parse_money(col($r, 'KASBON_AMOUNT')), parse_money(col($r, 'ENCASHED_HOLIDAYS')), $mode, $exempt,
        ]);
        if ($exists) $rep->existing++; else $rep->imported++;
    }
}

function import_attendance(array $rows, SheetReport $rep): void
{
    static $seen = [];
    foreach ($rows as $r) {
        $rep->source++;
        $ref = rowref($r, 'ATTENDANCE');
        $date = parse_date_any(col($r, 'DATE'));
        $emp = strtoupper(str_col($r, 'EMP_ID'));
        $name = str_col($r, 'FULL_NAME');
        if (!$date) { $rep->skipped[] = "$ref ($emp $name): tanggal tidak terbaca ('" . str_col($r, 'DATE') . "')"; continue; }
        if ($emp === '') { $rep->skipped[] = "$ref ($name, $date): EMP_ID kosong"; continue; }

        $checkin = parse_time_any(col($r, 'CHECKIN_TIME'));
        $checkout = parse_time_any(col($r, 'CHECKOUT_TIME'));
        $existing = q_one('SELECT * FROM attendance WHERE emp_id = ? AND date = ?', [$emp, $date]);
        if ($existing) {
            // 1 karyawan hanya 1 baris per hari. Data dobel digabung: jam masuk paling awal, jam keluar paling akhir.
            $same = $existing['checkin_time'] === $checkin && $existing['checkout_time'] === $checkout;
            if ($same) { $rep->existing++; continue; }
            $newIn = $existing['checkin_time'] && $checkin ? min($existing['checkin_time'], $checkin) : ($existing['checkin_time'] ?: $checkin);
            $newOut = $existing['checkout_time'] && $checkout ? max($existing['checkout_time'], $checkout) : ($existing['checkout_time'] ?: $checkout);
            $outPhoto = $existing['checkout_photo'];
            if ($newOut !== $existing['checkout_time'] && ($outPhoto === '-' || $outPhoto === '')) {
                $outPhoto = import_photo(col($r, 'CHECKOUT_PHOTO'), 'attendance', $rep->warnings, $ref);
            }
            q('UPDATE attendance SET checkin_time = ?, checkout_time = ?, checkout_photo = ? WHERE att_id = ?',
                [$newIn, $newOut, $outPhoto, $existing['att_id']]);
            $rep->merged++;
            $rep->warnings[] = "$ref ($emp $name, " . fmt_date($date) . "): absensi dobel di hari yang sama → digabung (masuk " . fmt_time($newIn) . ', keluar ' . fmt_time($newOut) . ').';
            continue;
        }
        $id = str_col($r, 'ATT_ID');
        if ($id === '' || strlen($id) > 20 || isset($seen[$id]) || q_val('SELECT 1 FROM attendance WHERE att_id = ?', [$id])) {
            $id = random_id('attendance', 'att_id', 'ATT');
        }
        $seen[$id] = true;
        q('INSERT INTO attendance (att_id, date, emp_id, full_name, checkin_time, checkout_time, latitude, longitude, distance_meters,
             checkin_photo, checkout_photo, status, location_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $date, $emp, $name, $checkin, $checkout,
            parse_coord(col($r, 'LATITUDE')), parse_coord(col($r, 'LONGITUDE')), parse_coord(col($r, 'DISTANCE_METERS')),
            import_photo(col($r, 'CHECKIN_PHOTO'), 'attendance', $rep->warnings, $ref),
            import_photo(col($r, 'CHECKOUT_PHOTO'), 'attendance', $rep->warnings, $ref),
            strtoupper(str_col($r, 'STATUS')) === 'TERLAMBAT' ? 'TERLAMBAT' : 'TEPAT WAKTU',
            mb_substr(str_col($r, 'LOCATION_NAME') ?: 'PT Artha Safety Indonesia - Kantor Pusat HQ', 0, 255),
        ]);
        $rep->imported++;
    }
}

function import_requests(array $rows, SheetReport $rep): void
{
    $seen = [];
    foreach ($rows as $r) {
        $rep->source++;
        $id = resolve_id('requests', 'req_id', str_col($r, 'REQ_ID'), $seen, fn() => next_seq_id('requests', 'req_id', 'REQ'));
        if ($id === null) { $rep->existing++; continue; }
        $start = parse_date_any(col($r, 'START_DATE'));
        $end = parse_date_any(col($r, 'END_DATE')) ?? $start;
        if (!$start) $rep->warnings[] = rowref($r, 'REQUESTS') . " ($id): tanggal mulai tidak terbaca ('" . str_col($r, 'START_DATE') . "'), tetap diimport tanpa tanggal.";
        q('INSERT INTO requests (req_id, submit_date, emp_id, full_name, type, start_date, end_date, reason, doc_attachment, status, approved_by, admin_note)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, parse_date_any(col($r, 'SUBMIT_DATE')), strtoupper(str_col($r, 'EMP_ID')), str_col($r, 'FULL_NAME'),
            mb_substr(str_col($r, 'TYPE'), 0, 100), $start, $end, str_col($r, 'REASON'),
            mb_substr(str_col($r, 'DOC_ATTACHMENT') ?: '-', 0, 500), mb_substr(strtoupper(str_col($r, 'STATUS')) ?: 'PENDING', 0, 20),
            mb_substr(str_col($r, 'APPROVED_BY') ?: '-', 0, 150), str_col($r, 'ADMIN_NOTE') ?: '-',
        ]);
        $rep->imported++;
    }
}

function import_payslips(array $rows, SheetReport $rep): void
{
    $seen = [];
    $numCols = ['BASIC_SALARY', 'ALLOWANCE', 'ON_TIME_BONUS', 'ENCASHED_HOLIDAYS', 'ENCASHED_HOLIDAY_PAY', 'LATE_COUNT',
        'EXCUSED_LATE_COUNT', 'LATE_DEDUCTION', 'BPJS_TK_AMOUNT', 'BPJS_KES_AMOUNT', 'KASBON_AMOUNT', 'DEDUCTIONS', 'NET_SALARY'];
    foreach ($rows as $r) {
        $rep->source++;
        $id = resolve_id('payslips', 'pay_id', str_col($r, 'PAY_ID'), $seen, fn() => random_id('payslips', 'pay_id', 'PAY'));
        if ($id === null) { $rep->existing++; continue; }
        $payDate = str_col($r, 'PAYMENT_DATE');
        $pd = parse_date_any($payDate);
        $vals = [$id, mb_substr(str_col($r, 'PERIOD'), 0, 30), strtoupper(str_col($r, 'EMP_ID')), str_col($r, 'FULL_NAME'),
            str_col($r, 'POSITION'), str_col($r, 'DEPARTMENT')];
        foreach ($numCols as $c) $vals[] = parse_money(col($r, $c, '0'));
        $vals[] = to_bool(col($r, 'HAS_BPJS_KES')) ? 1 : 0;
        array_push($vals, $pd ? fmt_date($pd) : mb_substr($payDate, 0, 30), mb_substr(str_col($r, 'STATUS') ?: 'TERBIT', 0, 20),
            str_col($r, 'FINANCE_NAME'), str_col($r, 'LEADER_NAME'));
        q('INSERT INTO payslips (pay_id, period, emp_id, full_name, position, department, basic_salary, allowance, on_time_bonus,
             encashed_holidays, encashed_holiday_pay, late_count, excused_late_count, late_deduction, bpjs_tk_amount, bpjs_kes_amount,
             kasbon_amount, deductions, net_salary, has_bpjs_kes, payment_date, status, finance_name, leader_name)
           VALUES (' . implode(',', array_fill(0, 24, '?')) . ')', $vals);
        $rep->imported++;
    }
}

function import_agendas(array $rows, SheetReport $rep): void
{
    $seen = [];
    foreach ($rows as $r) {
        $rep->source++;
        $id = resolve_id('agendas', 'agenda_id', str_col($r, 'AGENDA_ID'), $seen, fn() => next_seq_id('agendas', 'agenda_id', 'AGD'));
        if ($id === null) { $rep->existing++; continue; }
        $createdRaw = str_col($r, 'CREATED_AT');
        $created = parse_date_any($createdRaw);
        $createdTime = parse_time_any(trim((string)preg_replace('/^\S+\s*/', '', $createdRaw))) ?? '00:00:00';
        q('INSERT INTO agendas (agenda_id, date, title, description, category, created_by, created_by_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, parse_date_any(col($r, 'DATE')), mb_substr(str_col($r, 'TITLE'), 0, 255), str_col($r, 'DESCRIPTION'),
            mb_substr(str_col($r, 'CATEGORY'), 0, 100), str_col($r, 'CREATED_BY'), strtoupper(str_col($r, 'CREATED_BY_ID')),
            $created ? "$created $createdTime" : date('Y-m-d H:i:s'),
        ]);
        $rep->imported++;
    }
}

function import_duties(array $rows, SheetReport $rep): void
{
    $seen = [];
    foreach ($rows as $r) {
        $rep->source++;
        $ref = rowref($r, 'DUTIES');
        [$lat, $lng] = parse_latlng(col($r, 'LATITUDE'), col($r, 'LONGITUDE'));
        if ($lat !== null && $lng !== null && parse_coord(col($r, 'LONGITUDE')) === null) {
            $rep->warnings[] = "$ref (" . str_col($r, 'FULL_NAME') . "): latitude & longitude tertulis di satu kolom → dipisahkan otomatis ($lat, $lng).";
        }
        $start = parse_date_any(col($r, 'START_DATE'));
        $end = parse_date_any(col($r, 'END_DATE')) ?? $start;
        if ($lat === null || $lng === null) { $rep->skipped[] = "$ref (" . str_col($r, 'FULL_NAME') . '): koordinat tidak terbaca (' . str_col($r, 'LATITUDE') . ', ' . str_col($r, 'LONGITUDE') . ')'; continue; }
        if (!$start) { $rep->skipped[] = "$ref (" . str_col($r, 'FULL_NAME') . "): tanggal mulai tidak terbaca ('" . str_col($r, 'START_DATE') . "')"; continue; }
        $id = resolve_id('duties', 'duty_id', str_col($r, 'DUTY_ID'), $seen, fn() => next_seq_id('duties', 'duty_id', 'DUTY'));
        if ($id === null) { $rep->existing++; continue; }
        q('INSERT INTO duties (duty_id, emp_id, full_name, location_name, latitude, longitude, radius_meters, start_date, end_date, reason, created_by)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, strtoupper(str_col($r, 'EMP_ID')), str_col($r, 'FULL_NAME'), mb_substr(str_col($r, 'LOCATION_NAME'), 0, 255), $lat, $lng,
            max(10, parse_money(col($r, 'RADIUS_METERS', '50'))), $start, $end, str_col($r, 'REASON'), str_col($r, 'CREATED_BY'),
        ]);
        $rep->imported++;
    }
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
    .wrap { max-width: 860px; margin: 0 auto; }
    .card { background: #fff; border-radius: 16px; padding: 20px; margin-bottom: 16px; border: 1px solid #e2e8f0; overflow-x: auto; }
    h1 { font-size: 20px; } h2 { font-size: 16px; margin-top: 0; }
    label { display: block; font-size: 13px; font-weight: 600; margin: 10px 0 4px; }
    input[type=text], input[type=email], input[type=password] { width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 10px; }
    button { margin-top: 12px; background: #059669; color: #fff; border: 0; padding: 10px 18px; border-radius: 10px; font-weight: 700; cursor: pointer; }
    .ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px 14px; border-radius: 10px; margin-bottom: 8px; font-size: 14px; }
    .err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 10px 14px; border-radius: 10px; margin-bottom: 8px; font-size: 14px; }
    .warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 10px 14px; border-radius: 10px; font-size: 13px; }
    table { border-collapse: collapse; font-size: 13px; width: 100%; }
    th, td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
    th { background: #f8fafc; }
    ul.small { margin: 4px 0 0; padding-left: 18px; font-size: 12px; }
    small { color: #64748b; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>Setup HRIS PT Artha Safety Indonesia</h1>
  <?php foreach ($messages as $m): ?><div class="ok">✅ <?= h($m) ?></div><?php endforeach; ?>
  <?php foreach ($errors as $m): ?><div class="err">⚠️ <?= h($m) ?></div><?php endforeach; ?>

  <?php if ($reportTables): ?>
  <div class="card">
    <h2>Laporan Import (cek bahwa tidak ada data yang hilang)</h2>
    <table>
      <tr><th>Sheet</th><th>Baris di spreadsheet</th><th>Masuk baru</th><th>Digabung / sudah ada</th><th>Tidak masuk</th><th></th></tr>
      <?php foreach ($reportTables as $t): ?>
      <tr>
        <td><b><?= h($t['sheet']) ?></b></td>
        <td><?= h($t['expected'] ?? $t['source']) ?><?= ($t['expected'] !== null && (int)$t['expected'] !== $t['source']) ? ' <small>(file yang diupload berisi ' . h($t['source']) . ')</small>' : '' ?></td>
        <td><?= h($t['imported']) ?></td>
        <td><?= h($t['merged'] + $t['existing']) ?></td>
        <td><?= count($t['skipped']) ? '<b style="color:#b91c1c">' . count($t['skipped']) . '</b>' : '0' ?></td>
        <td><?= $t['notInUpload'] ? '<small>ada di part 1</small>' : (($t['ok'] && !count($t['skipped']) && ($t['expected'] === null || (int)$t['expected'] === $t['source'])) ? '✅' : '⚠️') ?></td>
      </tr>
      <?php if ($t['skipped'] || $t['warnings']): ?>
      <tr><td colspan="6">
        <?php if ($t['skipped']): ?><b style="color:#b91c1c">Tidak masuk:</b><ul class="small"><?php foreach ($t['skipped'] as $s): ?><li><?= h($s) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($t['warnings']): ?><b style="color:#92400e">Catatan:</b><ul class="small"><?php foreach (array_slice($t['warnings'], 0, 200) as $s): ?><li><?= h($s) ?></li><?php endforeach; ?>
          <?php if (count($t['warnings']) > 200): ?><li>... dan <?= count($t['warnings']) - 200 ?> catatan lain</li><?php endif; ?></ul><?php endif; ?>
      </td></tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </table>
    <p><small>Baris yang "tidak masuk" bisa diperbaiki langsung di Google Spreadsheet, lalu jalankan export lagi dan import ulang (dengan centang "Kosongkan data").</small></p>
  </div>
  <?php endif; ?>

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
    <h2>2. Import Seluruh Data dari Google Spreadsheet</h2>
    <p><small>Di Apps Script jalankan fungsi <b>exportForHostinger</b>. Di Google Drive akan muncul folder <b>HRIS-export-...</b>
      berisi 1 file atau lebih (<i>part1, part2, ...</i>). Download <b>semua</b> file di folder itu, lalu pilih semuanya sekaligus di bawah.</small></p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="step" value="import">
      <label>Setup key</label>
      <input type="password" name="setup_key" required>
      <label>File JSON hasil export (boleh pilih beberapa file sekaligus)</label>
      <input type="file" name="json[]" accept=".json,application/json" multiple required>
      <label><input type="checkbox" name="wipe" value="1" checked> Kosongkan data di database Hostinger dulu (centang saat upload part 1 / import pertama kali)</label>
      <button type="submit">Import Data</button>
    </form>
    <p><small>Jika file terlalu besar untuk diupload sekaligus: upload <b>part1</b> dulu (dengan centang), lalu part berikutnya satu per satu <b>tanpa</b> centang.</small></p>
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

  <div class="warn">Setelah setup selesai dan Anda berhasil login, <b>hapus folder <code>setup/</code></b> lewat hPanel → File Manager,
    dan hapus folder <b>HRIS-export-...</b> di Google Drive (berisi password karyawan).</div>
</div>
</body>
</html>
