<?php
/**
 * HRIS PT Artha Safety Indonesia - fungsi bantu bersama (database, tanggal, upload, sesi).
 * File ini tidak diakses langsung dari browser (diblokir oleh api/.htaccess).
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

const MONTH_NAMES_ID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const LATE_PENALTY = 50000;
const ON_TIME_BONUS = 50000;
const ENCASHED_HOLIDAY_RATE = 50000;

class ApiError extends Exception
{
    public int $httpCode;
    public function __construct(string $message, int $httpCode = 200)
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
    }
}

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            throw new ApiError('File api/config.php belum dibuat. Salin api/config.sample.php menjadi api/config.php lalu isi data database.', 500);
        }
        $cfg = require $file;
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config();
        $dsn = 'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/* ------------------------------------------------------------------ */
/* Tanggal, waktu & angka                                               */
/* ------------------------------------------------------------------ */

/** Ubah berbagai format tanggal (yyyy-mm-dd, dd/mm/yyyy, d-m-yyyy, dd Mon yyyy) menjadi 'Y-m-d'. */
function parse_date_any($value): ?string
{
    if ($value === null) return null;
    $s = trim((string)$value);
    if ($s === '' || $s === '-') return null;
    if (preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $s, $m)) {
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/', $s, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
    } else {
        $ts = strtotime($s);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    }
    if (!checkdate($mo, $d, $y)) return null;
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** 'Y-m-d' -> 'dd/mm/yyyy' (format yang dipakai tampilan HRIS). */
function fmt_date(?string $ymd): string
{
    if (!$ymd) return '';
    $p = explode('-', substr($ymd, 0, 10));
    if (count($p) !== 3) return (string)$ymd;
    return $p[2] . '/' . $p[1] . '/' . $p[0];
}

/** Ubah '8:05', '08.05', '08:05:33', '8:05 PM' -> 'HH:MM:SS'. */
function parse_time_any($value): ?string
{
    if ($value === null) return null;
    $s = strtolower(trim((string)$value));
    if ($s === '' || $s === '-') return null;
    $pm = str_contains($s, 'pm');
    $am = str_contains($s, 'am');
    $s = trim(str_replace(['am', 'pm'], '', $s));
    $p = preg_split('/[:.]/', $s);
    if (count($p) < 2) return null;
    $h = (int)$p[0]; $m = (int)$p[1]; $sec = isset($p[2]) ? (int)$p[2] : 0;
    if ($pm && $h < 12) $h += 12;
    if ($am && $h === 12) $h = 0;
    if ($h > 23 || $m > 59 || $sec > 59) return null;
    return sprintf('%02d:%02d:%02d', $h, $m, $sec);
}

function time_to_minutes(?string $t): int
{
    if (!$t) return 0;
    $p = explode(':', $t);
    return ((int)$p[0]) * 60 + (int)($p[1] ?? 0);
}

function fmt_time(?string $t): string
{
    return $t ? substr($t, 0, 5) : '-';
}

/** Koordinat: terima '-6.175392' maupun format Indonesia '-6,175392'. */
function parse_coord($value): ?float
{
    if ($value === null) return null;
    $s = trim((string)$value);
    if ($s === '') return null;
    if (str_contains($s, ',') && !str_contains($s, '.')) $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return null;
    return (float)$s;
}

/**
 * Pisahkan koordinat yang di-copas dari Google Maps ke satu kolom, misal LATITUDE = "-6.3532325, 106.8593898"
 * dan LONGITUDE kosong. Mengembalikan [lat, lng] (float|null).
 */
function parse_latlng($lat, $lng): array
{
    $la = parse_coord($lat);
    $lo = parse_coord($lng);
    if (($la === null || $lo === null) && preg_match('/(-?\d{1,3}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)/', trim((string)$lat) . ' ' . trim((string)$lng), $m)) {
        return [(float)$m[1], (float)$m[2]];
    }
    return [$la, $lo];
}

/** Nominal uang: 'Rp 5.000.000', '5,000,000', '5000000' -> 5000000. */
function parse_money($value): int
{
    if ($value === null) return 0;
    if (is_int($value)) return $value;
    if (is_float($value)) return (int)round($value);
    $s = trim((string)$value);
    if ($s === '') return 0;
    $neg = str_starts_with($s, '-');
    // buang bagian desimal ",00" / ".00" di ujung
    $s = preg_replace('/[.,]\d{1,2}$/', '', $s);
    $digits = preg_replace('/\D/', '', $s);
    if ($digits === '') return 0;
    return $neg ? -(int)$digits : (int)$digits;
}

function to_bool($v): bool
{
    if (is_bool($v)) return $v;
    $s = strtoupper(trim((string)$v));
    return in_array($s, ['TRUE', '1', 'YA', 'YES', 'ON'], true);
}

function distance_meters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($R * $c, 1);
}

function current_period(): string
{
    return MONTH_NAMES_ID[(int)date('n') - 1] . ' ' . date('Y');
}

/* ------------------------------------------------------------------ */
/* ID                                                                    */
/* ------------------------------------------------------------------ */

/** ID berurutan: EMP-0001, REQ-0001, AGD-0001, DUTY-0001 (berdasarkan nomor terbesar yang ada). */
function next_seq_id(string $table, string $col, string $prefix): string
{
    $max = 0;
    foreach (q_all("SELECT $col AS id FROM $table WHERE $col LIKE ?", [$prefix . '-%']) as $r) {
        $n = (int)preg_replace('/\D/', '', substr($r['id'], strlen($prefix) + 1));
        if ($n > $max) $max = $n;
    }
    return $prefix . '-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/** ID acak unik untuk tabel yang ramai (ATT, PAY). */
function random_id(string $table, string $col, string $prefix): string
{
    for ($i = 0; $i < 10; $i++) {
        $id = $prefix . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        if (!q_val("SELECT 1 FROM $table WHERE $col = ?", [$id])) return $id;
    }
    throw new ApiError('Gagal membuat ID unik, coba lagi.');
}

/* ------------------------------------------------------------------ */
/* Upload file (foto absensi, foto profil, lampiran pengajuan)          */
/* ------------------------------------------------------------------ */

function uploads_dir(): string
{
    return dirname(__DIR__) . '/uploads';
}

/**
 * Simpan data URL base64 ke folder uploads/<sub>/YYYY/MM/ dan kembalikan path relatif
 * (misal 'uploads/attendance/2026/11/ab12....jpg').
 */
function save_data_url(string $dataUrl, string $sub, array $allowedMimes, int $maxBytes): string
{
    if (!preg_match('/^data:([\w.+\/-]+);base64,(.*)$/s', $dataUrl, $m)) {
        throw new ApiError('Format file tidak valid.');
    }
    $raw = base64_decode($m[2], true);
    if ($raw === false || $raw === '') throw new ApiError('Isi file rusak / kosong.');
    if (strlen($raw) > $maxBytes) throw new ApiError('Ukuran file terlalu besar (maksimal ' . round($maxBytes / 1048576) . ' MB).');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($raw) ?: $m[1];
    $extMap = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];
    if (!in_array($mime, $allowedMimes, true) || !isset($extMap[$mime])) {
        throw new ApiError('Jenis file tidak diizinkan (' . $mime . ').');
    }
    if (str_starts_with($mime, 'image/') && @getimagesizefromstring($raw) === false) {
        throw new ApiError('File gambar tidak valid.');
    }

    $rel = 'uploads/' . $sub . '/' . date('Y') . '/' . date('m');
    $dir = dirname(__DIR__) . '/' . $rel;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new ApiError('Folder uploads tidak bisa dibuat. Periksa izin folder di File Manager.', 500);
    }
    $name = bin2hex(random_bytes(16)) . '.' . $extMap[$mime];
    if (file_put_contents($dir . '/' . $name, $raw) === false) {
        throw new ApiError('Gagal menyimpan file ke server.', 500);
    }
    return $rel . '/' . $name;
}

const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const DOC_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

/** Ubah path relatif 'uploads/...' menjadi URL lengkap. URL http(s) dibiarkan apa adanya. */
function public_url(?string $path): string
{
    if (!$path || $path === '-') return $path === null ? '' : $path;
    if (preg_match('#^(https?:|data:)#i', $path)) return $path;
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) return $path;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $base . '/' . ltrim($path, '/');
}

/* ------------------------------------------------------------------ */
/* Aturan karyawan (remote / bebas bonus) - sekarang dari database,     */
/* bukan nama yang ditulis langsung di kode.                            */
/* ------------------------------------------------------------------ */

/* ------------------------------------------------------------------ */
/* Hak akses tambahan (untuk karyawan non-admin, mis. supervisor)       */
/* ------------------------------------------------------------------ */

const PERM_ATT_ALL = 'ATT_ALL';   // lihat riwayat absensi seluruh karyawan
const PERM_REPORT = 'REPORT';     // laporan presensi (PDF)
const PERM_APPROVE = 'APPROVE';   // menyetujui cuti, izin, libur & koreksi absen (BUKAN lembur)
const ALL_PERMS = [PERM_ATT_ALL, PERM_REPORT, PERM_APPROVE];

function user_perms(array $u): array
{
    if (($u['role'] ?? '') === 'ADMIN') return ALL_PERMS;
    $list = array_map('trim', explode(',', strtoupper((string)($u['permissions'] ?? ''))));
    return array_values(array_intersect(ALL_PERMS, $list));
}

function has_perm(array $u, string $perm): bool
{
    return in_array($perm, user_perms($u), true);
}

/** Data karyawan tanpa gaji / BPJS / kasbon — untuk pengguna dengan akses parsial. */
function out_user_basic(array $u): array
{
    $full = out_user($u);
    $keep = ['EMP_ID', 'FULL_NAME', 'POSITION', 'DEPARTMENT', 'JOIN_DATE', 'PROFILE_PIC', 'WORK_MODE', 'BONUS_EXEMPT', 'IS_ACTIVE', 'RESIGN_DATE', 'ROLE'];
    return array_intersect_key($full, array_flip($keep));
}

function is_remote_user(array $u): bool
{
    // Kolom work_mode di database adalah penentu (diatur Admin di menu Edit Karyawan).
    if (array_key_exists('work_mode', $u)) return strtoupper((string)$u['work_mode']) === 'REMOTE';
    $pos = strtolower((string)($u['position'] ?? ''));
    $dept = strtolower((string)($u['department'] ?? ''));
    return str_contains($pos, 'remote') || str_contains($pos, 'wfh') || str_contains($dept, 'remote') || str_contains($dept, 'wfh');
}

function is_bonus_exempt(array $u): bool
{
    if (!empty($u['bonus_exempt'])) return true;
    if (is_remote_user($u)) return true;
    $pos = strtolower((string)($u['position'] ?? ''));
    $role = strtolower((string)($u['role'] ?? ''));
    foreach (['direktur', 'director', 'komisaris', 'direksi', 'pimpinan'] as $w) {
        if (str_contains($pos, $w)) return true;
    }
    return str_contains($role, 'director') || str_contains($role, 'direktur');
}

/* ------------------------------------------------------------------ */
/* Format output (kunci HURUF BESAR seperti kolom Spreadsheet lama)     */
/* ------------------------------------------------------------------ */

function out_user(array $u): array
{
    return [
        'EMP_ID' => $u['emp_id'],
        'FULL_NAME' => $u['full_name'],
        'EMAIL' => $u['email'],
        'ROLE' => $u['role'],
        'POSITION' => $u['position'],
        'DEPARTMENT' => $u['department'],
        'JOIN_DATE' => fmt_date($u['join_date']),
        'LEAVE_BALANCE' => (int)$u['leave_balance'],
        'SAVED_HOLIDAYS' => (int)$u['saved_holidays'],
        'CONTRACT_DOC_URL' => public_url($u['contract_doc_url']),
        'BPJS_DOC_URL' => public_url($u['bpjs_doc_url']),
        'BASIC_SALARY' => (int)$u['basic_salary'],
        'ALLOWANCE' => (int)$u['allowance'],
        'PROFILE_PIC' => public_url($u['profile_pic']),
        'BPJS_TK_AMOUNT' => (int)$u['bpjs_tk_amount'],
        'HAS_BPJS_KES' => (bool)$u['has_bpjs_kes'],
        'BPJS_KES_AMOUNT' => (int)$u['bpjs_kes_amount'],
        'KASBON_AMOUNT' => (int)$u['kasbon_amount'],
        'ENCASHED_HOLIDAYS' => (int)$u['encashed_holidays'],
        'WORK_MODE' => is_remote_user($u) ? 'REMOTE' : 'OFFICE',
        'BONUS_EXEMPT' => is_bonus_exempt($u),
        'IS_ACTIVE' => (bool)$u['is_active'],
        'RESIGN_DATE' => fmt_date($u['resign_date'] ?? null),
        'PERMISSIONS' => user_perms($u),
        'LEAVE_ELIGIBLE' => leave_eligible($u),
        'LEAVE_POLICY' => leave_policy($u),
        'LEAVE_ELIGIBLE_DATE' => fmt_date(leave_eligible_date($u)),
    ];
}

function out_attendance(array $a): array
{
    return [
        'ATT_ID' => $a['att_id'],
        'DATE' => fmt_date($a['date']),
        'EMP_ID' => $a['emp_id'],
        'FULL_NAME' => $a['full_name'],
        'CHECKIN_TIME' => fmt_time($a['checkin_time']),
        'CHECKOUT_TIME' => fmt_time($a['checkout_time']),
        'LATITUDE' => $a['latitude'],
        'LONGITUDE' => $a['longitude'],
        'DISTANCE_METERS' => $a['distance_meters'] === null ? '0' : rtrim(rtrim((string)$a['distance_meters'], '0'), '.'),
        'GPS_ACCURACY' => $a['gps_accuracy'],
        'CHECKIN_PHOTO' => public_url($a['checkin_photo']),
        'CHECKOUT_PHOTO' => public_url($a['checkout_photo']),
        'STATUS' => $a['status'],
        'LOCATION_NAME' => $a['location_name'],
    ];
}

function out_request(array $r): array
{
    return [
        'REQ_ID' => $r['req_id'],
        'SUBMIT_DATE' => fmt_date($r['submit_date']),
        'EMP_ID' => $r['emp_id'],
        'FULL_NAME' => $r['full_name'],
        'TYPE' => $r['type'],
        'START_DATE' => fmt_date($r['start_date']),
        'END_DATE' => fmt_date($r['end_date']),
        'REASON' => (string)$r['reason'],
        'DOC_ATTACHMENT' => public_url($r['doc_attachment']),
        'STATUS' => $r['status'],
        'APPROVED_BY' => $r['approved_by'],
        'APPROVED_AT' => !empty($r['approved_at']) ? date('d/m/Y H:i', strtotime($r['approved_at'])) : '',
        'CORRECTION_KIND' => $r['correction_kind'] ?? null,
        'CORRECTION_TIME' => !empty($r['correction_time']) ? substr($r['correction_time'], 0, 5) : null,
        'OT_START' => !empty($r['ot_start']) ? substr($r['ot_start'], 0, 5) : null,
        'OT_END' => !empty($r['ot_end']) ? substr($r['ot_end'], 0, 5) : null,
        'OT_HOURS' => isset($r['ot_hours']) ? (float)$r['ot_hours'] : null,
        'OT_PAY' => isset($r['ot_pay']) ? (int)$r['ot_pay'] : null,
        'ADMIN_NOTE' => (string)($r['admin_note'] ?? '-'),
    ];
}

function out_payslip(array $p, ?array $user = null): array
{
    $o = [];
    foreach ($p as $k => $v) {
        if ($k === 'created_at') continue;
        if ($k === 'overtime_hours') { $o['OVERTIME_HOURS'] = (float)$v; continue; }
        $o[strtoupper($k)] = is_numeric($v) && !in_array($k, ['pay_id', 'period', 'emp_id', 'payment_date'], true) ? (int)$v : $v;
    }
    $o['HAS_BPJS_KES'] = (bool)$p['has_bpjs_kes'];
    $o['BONUS_EXEMPT'] = $user ? is_bonus_exempt($user) : false;
    $o['WORK_MODE'] = $user ? (is_remote_user($user) ? 'REMOTE' : 'OFFICE') : 'OFFICE';
    return $o;
}

function out_agenda(array $a): array
{
    return [
        'AGENDA_ID' => $a['agenda_id'],
        'DATE' => fmt_date($a['date']),
        'TITLE' => $a['title'],
        'DESCRIPTION' => (string)$a['description'],
        'CATEGORY' => $a['category'],
        'CREATED_BY' => $a['created_by'],
        'CREATED_BY_ID' => $a['created_by_id'],
        'CREATED_AT' => $a['created_at'],
    ];
}

function out_duty(array $d): array
{
    return [
        'DUTY_ID' => $d['duty_id'],
        'EMP_ID' => $d['emp_id'],
        'FULL_NAME' => $d['full_name'],
        'LOCATION_NAME' => $d['location_name'],
        'LATITUDE' => (string)(float)$d['latitude'],
        'LONGITUDE' => (string)(float)$d['longitude'],
        'RADIUS_METERS' => (string)(int)$d['radius_meters'],
        'START_DATE' => fmt_date($d['start_date']),
        'END_DATE' => fmt_date($d['end_date']),
        'REASON' => (string)$d['reason'],
        'CREATED_BY' => $d['created_by'],
        'CREATED_AT' => $d['created_at'],
    ];
}

function get_settings(): array
{
    $s = [];
    foreach (q_all('SELECT setting_key, setting_value FROM settings') as $r) {
        $s[$r['setting_key']] = $r['setting_value'];
    }
    return $s + [
        'OFFICE_NAME' => 'PT Artha Safety Indonesia - Kantor Pusat HQ',
        'OFFICE_LAT' => '-6.175392',
        'OFFICE_LNG' => '106.827153',
        'MAX_RADIUS_METERS' => '50',
        'WORK_START_TIME' => '08:00',
        'WORK_END_TIME' => '17:00',
        'WORK_END_SATURDAY' => '12:00',
        'FINANCE_NAME' => '',
        'LEADER_NAME' => '',
    ];
}

/* ------------------------------------------------------------------ */
/* Hak cuti tahunan                                                      */
/* ------------------------------------------------------------------ */

const LEAVE_ANNUAL_DAYS = 12;

/** Tanggal karyawan mulai berhak cuti (1 tahun setelah tanggal bergabung). null = tanggal bergabung tidak diketahui. */
/*
 * Hak cuti per karyawan (kolom leave_policy, diatur Admin di Edit Karyawan):
 *  ELIGIBLE = berhak cuti tahunan (12 hari, reset tiap 1 Januari)
 *  AUTO     = mengikuti tanggal bergabung (berhak setelah genap 1 tahun)
 *  NONE     = tidak mendapat cuti tahunan (mis. direksi / remote / khusus)
 */
function leave_policy(array $u): string
{
    $p = strtoupper((string)($u['leave_policy'] ?? 'AUTO'));
    return in_array($p, ['ELIGIBLE', 'AUTO', 'NONE'], true) ? $p : 'AUTO';
}

function leave_eligible_date(array $u): ?string
{
    if (leave_policy($u) !== 'AUTO') return null;
    $j = $u['join_date'] ?? null;
    if (!$j) return null;
    return date('Y-m-d', strtotime($j . ' +1 year'));
}

function leave_eligible(array $u, ?string $today = null): bool
{
    $pol = leave_policy($u);
    if ($pol === 'NONE') return false;
    if ($pol === 'ELIGIBLE') return true;
    $d = leave_eligible_date($u);
    return $d === null || $d <= ($today ?? date('Y-m-d'));
}

/**
 * Dijalankan sekali saat fitur aturan cuti dipasang:
 * - karyawan yang sudah >= 1 tahun: saldo cuti tahun ini TIDAK diubah (dianggap sudah diberi jatah tahun ini)
 * - karyawan yang belum 1 tahun: saldo cuti menjadi 0 (belum berhak cuti)
 */
function init_leave_entitlement(): void
{
    $year = (int)date('Y');
    foreach (q_all('SELECT emp_id, full_name, join_date, leave_balance FROM users') as $u) {
        if (leave_eligible($u)) {
            q('UPDATE users SET leave_year = ? WHERE emp_id = ?', [$year, $u['emp_id']]);
        } else {
            q('UPDATE users SET leave_balance = 0, leave_year = NULL WHERE emp_id = ?', [$u['emp_id']]);
            if ((int)$u['leave_balance'] !== 0) {
                audit_log(null, 'RESET_CUTI', $u['emp_id'], 'Saldo cuti ' . $u['full_name'] . ' diubah ' . (int)$u['leave_balance']
                    . ' → 0 hari (masa kerja belum 1 tahun, berhak mulai ' . fmt_date(leave_eligible_date($u)) . ').');
            }
        }
    }
}

/**
 * Perbaikan sekali jalan (v6): pemasangan aturan cuti sebelumnya membaca tanggal bergabung hasil import
 * (tanggal akun dibuat di sistem lama, bukan tanggal mulai bekerja) sehingga banyak saldo cuti menjadi 0.
 * - Saldo yang di-nol-kan sistem dipulihkan ke angka sebelumnya (tercatat di log) dan ditandai "Berhak cuti".
 * - Sesuai arahan HR: Ridho, Rangga, Noih = belum berhak (ikut tanggal bergabung);
 *   Erna, Ricardo (Ardo), Irfan = tidak mendapat cuti tahunan.
 * Semua bisa diubah lagi oleh Admin di menu Edit Karyawan → Hak Cuti Tahunan.
 */
function repair_leave_v6(): void
{
    $year = (int)date('Y');
    $restore = [];
    foreach (q_all("SELECT target, detail FROM audit_log WHERE actor_id = 'SISTEM' AND action = 'RESET_CUTI'
                    AND detail LIKE '%masa kerja belum 1 tahun%' ORDER BY id") as $l) {
        if (preg_match('/diubah\s+(\d+)\s+→\s+0\s+hari/u', (string)$l['detail'], $m)) {
            $restore[$l['target']] = $restore[$l['target']] ?? (int)$m[1]; // ambil nilai pertama (sebelum diubah)
        }
    }
    foreach (q_all('SELECT emp_id, full_name, leave_balance FROM users') as $u) {
        $n = strtolower((string)$u['full_name']);
        $none = (bool)preg_match('/\berna\b|\birfan\b|ricardo|\bardo\b/u', $n);
        $notYet = (bool)preg_match('/\bridho\b|\brangga\b|\bnoih\b/u', $n);
        if ($none) {
            q("UPDATE users SET leave_policy = 'NONE', leave_balance = 0, leave_year = NULL WHERE emp_id = ?", [$u['emp_id']]);
            audit_log(null, 'HAK_CUTI', $u['emp_id'], 'Hak cuti ' . $u['full_name'] . ': Tidak mendapat cuti tahunan (saldo ' . (int)$u['leave_balance'] . ' → 0).');
        } elseif ($notYet) {
            q("UPDATE users SET leave_policy = 'AUTO', leave_balance = 0, leave_year = NULL WHERE emp_id = ?", [$u['emp_id']]);
            audit_log(null, 'HAK_CUTI', $u['emp_id'], 'Hak cuti ' . $u['full_name'] . ': Belum berhak (ikut tanggal bergabung, mohon pastikan tanggal bergabung benar). Saldo 0.');
        } elseif (array_key_exists($u['emp_id'], $restore)) {
            $old = $restore[$u['emp_id']];
            q("UPDATE users SET leave_policy = 'ELIGIBLE', leave_balance = ?, leave_year = ? WHERE emp_id = ?", [$old, $year, $u['emp_id']]);
            audit_log(null, 'PULIHKAN_CUTI', $u['emp_id'], 'Saldo cuti ' . $u['full_name'] . ' dipulihkan 0 → ' . $old . ' hari dan ditandai "Berhak cuti tahunan".');
        }
    }
}

/* ------------------------------------------------------------------ */
/* Log aktivitas                                                         */
/* ------------------------------------------------------------------ */

/** Catat aktivitas. $actor = baris user pelaku (null = sistem otomatis). */
function audit_log(?array $actor, string $action, string $target, string $detail): void
{
    try {
        q('INSERT INTO audit_log (actor_id, actor_name, action, target, detail) VALUES (?, ?, ?, ?, ?)', [
            $actor['emp_id'] ?? 'SISTEM', $actor['full_name'] ?? 'Sistem otomatis', mb_substr($action, 0, 40), mb_substr($target, 0, 100), $detail,
        ]);
    } catch (Throwable $e) {
        error_log('[HRIS audit] ' . $e->getMessage());
    }
}

/** Pengaturan yang boleh dilihat semua karyawan (tanpa data rahasia seperti password email). */
function public_settings(): array
{
    $s = get_settings();
    foreach (array_keys($s) as $k) {
        if (str_starts_with($k, 'SMTP_') || str_starts_with($k, 'NOTIF_') || str_starts_with($k, 'MAIL_') || $k === 'SCHEMA_VERSION') unset($s[$k]);
    }
    return $s;
}

/* ------------------------------------------------------------------ */
/* Backup (dipakai api/backup.php dan api/cron_backup.php)              */
/* ------------------------------------------------------------------ */

const BACKUP_TABLES = ['users', 'settings', 'attendance', 'requests', 'payslips', 'agendas', 'duties', 'holidays', 'announcements', 'announcement_reads', 'audit_log'];

/** Buat file ZIP berisi: CSV per tabel (bisa dibuka Excel), hris.sql (restore via phpMyAdmin), dan opsional folder uploads. */
function build_backup_zip(string $zipPath, bool $includeFiles): void
{
    ensure_schema(); // pastikan semua tabel terbaru sudah ada
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new ApiError('Gagal membuat file ZIP backup.', 500);
    }
    $pdo = db();
    $sql = "-- Backup HRIS PT Artha Safety Indonesia\n-- Dibuat: " . date('d/m/Y H:i:s') . " WIB\n"
        . "-- Restore: phpMyAdmin -> pilih database -> Import -> pilih file ini.\n\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach (BACKUP_TABLES as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $sql .= "DROP TABLE IF EXISTS `$t`;\n$create;\n";

        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll();
        $csv = fopen('php://temp', 'r+');
        fwrite($csv, "\xEF\xBB\xBF"); // BOM agar Excel membaca huruf dengan benar
        if ($rows) {
            $cols = array_keys($rows[0]);
            fputcsv($csv, array_map('strtoupper', $cols));
            $colList = '`' . implode('`,`', $cols) . '`';
            foreach (array_chunk($rows, 200) as $chunk) {
                $values = [];
                foreach ($chunk as $r) {
                    fputcsv($csv, array_values($r));
                    $values[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($r))) . ')';
                }
                $sql .= "INSERT INTO `$t` ($colList) VALUES\n" . implode(",\n", $values) . ";\n";
            }
        }
        $sql .= "\n";
        rewind($csv);
        $zip->addFromString('excel-csv/' . $t . '.csv', stream_get_contents($csv));
        fclose($csv);
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $zip->addFromString('hris.sql', $sql);
    $zip->addFromString('BACA-SAYA.txt',
        "BACKUP HRIS - " . date('d/m/Y H:i') . " WIB\r\n\r\n"
        . "excel-csv/  : data tiap tabel, bisa dibuka di Excel / Google Sheets.\r\n"
        . "             users.csv = akun & data karyawan (password tersimpan dalam bentuk hash/terenkripsi).\r\n"
        . "hris.sql    : backup lengkap database. Untuk mengembalikan data: hPanel -> Databases -> phpMyAdmin\r\n"
        . "             -> pilih database HRIS -> tab Import -> pilih file hris.sql -> Go.\r\n"
        . "uploads/    : foto absensi, foto profil & lampiran pengajuan (jika dicentang saat backup).\r\n");

    if ($includeFiles && is_dir(uploads_dir())) {
        $base = dirname(__DIR__) . '/';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(uploads_dir(), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getFilename() !== '.htaccess') {
                $zip->addFile($file->getPathname(), substr(str_replace('\\', '/', $file->getPathname()), strlen($base)));
            }
        }
    }
    $zip->close();
}

/* ------------------------------------------------------------------ */
/* Pembaruan struktur database otomatis (tanpa perlu buka phpMyAdmin)  */
/* ------------------------------------------------------------------ */

const SCHEMA_VERSION = 6;

function column_exists(string $table, string $col): bool
{
    return (bool)q_one("SHOW COLUMNS FROM `$table` LIKE " . db()->quote($col));
}

function add_column_if_missing(string $table, string $col, string $ddl): bool
{
    if (column_exists($table, $col)) return false;
    db()->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
    return true;
}

/** Pembaruan struktur database otomatis. Aman dijalankan berulang kali. */
function ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $ver = (int)q_val("SELECT setting_value FROM settings WHERE setting_key = 'SCHEMA_VERSION'");
    if ($ver >= SCHEMA_VERSION) return;

    // v2: tanggal HR memproses (ACC / tolak) pengajuan
    if (add_column_if_missing('requests', 'approved_at', 'approved_at DATETIME NULL AFTER approved_by')) {
        backfill_request_approved_at();
    }
    // v3: koreksi absen, lembur, karyawan nonaktif, kalender libur
    add_column_if_missing('requests', 'correction_kind', "correction_kind VARCHAR(10) NULL");
    add_column_if_missing('requests', 'correction_time', "correction_time TIME NULL");
    add_column_if_missing('requests', 'ot_start', "ot_start TIME NULL");
    add_column_if_missing('requests', 'ot_end', "ot_end TIME NULL");
    add_column_if_missing('requests', 'ot_hours', "ot_hours DECIMAL(5,2) NULL");
    add_column_if_missing('requests', 'ot_pay', "ot_pay BIGINT NULL");
    add_column_if_missing('users', 'resign_date', "resign_date DATE NULL AFTER is_active");
    add_column_if_missing('payslips', 'overtime_hours', "overtime_hours DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER encashed_holiday_pay");
    add_column_if_missing('payslips', 'overtime_pay', "overtime_pay BIGINT NOT NULL DEFAULT 0 AFTER overtime_hours");
    db()->exec("CREATE TABLE IF NOT EXISTS holidays (
        holiday_date DATE NOT NULL PRIMARY KEY,
        name VARCHAR(150) NOT NULL DEFAULT '',
        type VARCHAR(20) NOT NULL DEFAULT 'LIBUR NASIONAL',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    q("INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
        ('WORK_END_SATURDAY', '12:00', 'Jam Pulang Hari Sabtu (WIB)')");
    // v4: hak akses tambahan per karyawan (mis. supervisor)
    add_column_if_missing('users', 'permissions', "permissions VARCHAR(255) NOT NULL DEFAULT '' AFTER role");

    // v5: pengumuman HR, log aktivitas, hak cuti tahunan
    db()->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actor_id VARCHAR(20) NOT NULL DEFAULT '',
        actor_name VARCHAR(150) NOT NULL DEFAULT '',
        action VARCHAR(40) NOT NULL DEFAULT '',
        target VARCHAR(100) NOT NULL DEFAULT '',
        detail TEXT,
        KEY idx_audit_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS announcements (
        ann_id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL DEFAULT '',
        body TEXT,
        created_by VARCHAR(150) NOT NULL DEFAULT '',
        created_by_id VARCHAR(20) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
        ann_id INT NOT NULL,
        emp_id VARCHAR(20) NOT NULL,
        read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ann_id, emp_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (add_column_if_missing('users', 'leave_year', "leave_year INT NULL AFTER leave_balance")) {
        init_leave_entitlement();
    }
    // v6: hak cuti diatur per karyawan (tidak lagi hanya dari tanggal bergabung) + pemulihan saldo
    if (add_column_if_missing('users', 'leave_policy', "leave_policy VARCHAR(10) NOT NULL DEFAULT 'AUTO' AFTER leave_year")) {
        repair_leave_v6();
    }

    q("INSERT INTO settings (setting_key, setting_value, description) VALUES ('SCHEMA_VERSION', ?, 'Versi struktur database (otomatis)')
       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [(string)SCHEMA_VERSION]);
}

/**
 * Pengajuan lama (dari spreadsheet) tidak punya tanggal ACC. Saat ACC, sistem lama selalu membuat agenda
 * "Nama - Jenis" oleh "Sistem HR" -> waktu pembuatan agenda itu = waktu HR menyetujui.
 */
function backfill_request_approved_at(): void
{
    q("UPDATE requests r
         JOIN (SELECT created_by_id, title, date, MIN(created_at) AS created_at FROM agendas
                WHERE created_by = 'Sistem HR' GROUP BY created_by_id, title, date) a
           ON a.created_by_id = r.emp_id AND a.title = CONCAT(r.full_name, ' - ', r.type) AND a.date <=> r.start_date
          SET r.approved_at = a.created_at
        WHERE r.approved_at IS NULL AND UPPER(r.status) IN ('APPROVED', 'DISETUJUI')");
}

/* ------------------------------------------------------------------ */
/* Link Google Maps -> koordinat                                         */
/* ------------------------------------------------------------------ */

function valid_latlng($lat, $lng): bool
{
    return is_numeric($lat) && is_numeric($lng) && abs((float)$lat) <= 90 && abs((float)$lng) <= 180
        && !((float)$lat == 0.0 && (float)$lng == 0.0);
}

/** Cari koordinat di teks / URL Google Maps. Urutan: titik pin (!3d!4d), parameter q/ll, lalu @lat,lng. */
function maps_extract_coords(string $text): ?array
{
    $t = rawurldecode(html_entity_decode($text));
    $patterns = [
        '/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/',
        '/[?&](?:q|query|ll|sll|destination|daddr|center)=(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/',
        '/\/search\/(-?\d{1,2}\.\d+),\s*\+?(-?\d{1,3}\.\d+)/',
        '/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/',
        '/^\s*(-?\d{1,2}\.\d+)\s*[,; ]\s*(-?\d{1,3}\.\d+)\s*$/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $t, $m) && valid_latlng($m[1], $m[2])) {
            return [(float)$m[1], (float)$m[2]];
        }
    }
    return null;
}

function maps_extract_name(string $url): string
{
    if (preg_match('#/maps/place/([^/@?]+)#', $url, $m)) {
        return trim(str_replace('+', ' ', rawurldecode($m[1])));
    }
    return '';
}

function maps_host_allowed(string $url): bool
{
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return false;
    return (bool)preg_match('/(^|\.)(goo\.gl|google\.com|google\.co\.id)$/i', (string)($p['host'] ?? ''));
}

/**
 * Buka link pendek (maps.app.goo.gl / goo.gl/maps) dan ikuti redirect -> koordinat.
 * Hanya domain Google yang diizinkan.
 */
function maps_resolve_link(string $url): array
{
    $url = trim($url);
    if (preg_match('#https?://\S+#', $url, $m)) $url = $m[0];
    $direct = maps_extract_coords($url);
    if ($direct) return ['lat' => $direct[0], 'lng' => $direct[1], 'name' => maps_extract_name($url)];
    if (!maps_host_allowed($url)) throw new ApiError('Link bukan link Google Maps. Contoh: https://maps.app.goo.gl/xxxx');
    if (!function_exists('curl_init')) throw new ApiError('Server tidak mendukung pembacaan link pendek. Buka link di browser, lalu copy link panjangnya.');

    $name = '';
    for ($hop = 0; $hop < 8; $hop++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept-Language: id,en;q=0.8', 'Cookie: CONSENT=YES+cb; SOCS=CAESEwgDEgk0ODE3Nzk3MjQaAmlkIAEaBgiA_LyaBg'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ApiError('Gagal membuka link Google Maps (' . $err . ').');
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = substr($resp, 0, $hsize);
        $body = substr($resp, $hsize, 3000000);

        if ($code >= 300 && $code < 400 && preg_match('/^location:\s*(\S+)/im', $headers, $m)) {
            $next = $m[1];
            if (str_starts_with($next, '/')) {
                $p = parse_url($url);
                $next = $p['scheme'] . '://' . $p['host'] . $next;
            }
            // Halaman persetujuan cookie Google: ambil tujuan aslinya
            if (preg_match('#consent\.google\.[^/]+/.*[?&]continue=([^&]+)#', $next, $c)) $next = rawurldecode($c[1]);
            $name = $name ?: maps_extract_name($next);
            $found = maps_extract_coords($next);
            if ($found) return ['lat' => $found[0], 'lng' => $found[1], 'name' => $name ?: maps_extract_name($next)];
            if (!maps_host_allowed($next)) throw new ApiError('Link mengarah ke luar Google Maps.');
            $url = $next;
            continue;
        }
        // Halaman akhir: cari koordinat di isi halaman
        if (preg_match('/APP_INITIALIZATION_STATE=\[\[\[[-\d.e]+,(-?\d{1,3}\.\d+),(-?\d{1,2}\.\d+)\]/', $body, $m) && valid_latlng($m[2], $m[1])) {
            return ['lat' => (float)$m[2], 'lng' => (float)$m[1], 'name' => $name];
        }
        if (preg_match('/center=(-?\d{1,2}\.\d+)(?:%2C|,)(-?\d{1,3}\.\d+)/i', $body, $m) && valid_latlng($m[1], $m[2])) {
            return ['lat' => (float)$m[1], 'lng' => (float)$m[2], 'name' => $name];
        }
        $found = maps_extract_coords($body);
        if ($found) return ['lat' => $found[0], 'lng' => $found[1], 'name' => $name];
        break;
    }
    throw new ApiError('Koordinat tidak ditemukan di link ini. Di Google Maps, tekan lama / klik kanan tepat di titik lokasi, lalu bagikan link titik tersebut (atau copy angka koordinatnya).');
}
