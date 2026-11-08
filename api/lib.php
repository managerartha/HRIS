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
        'ADMIN_NOTE' => (string)($r['admin_note'] ?? '-'),
    ];
}

function out_payslip(array $p, ?array $user = null): array
{
    $o = [];
    foreach ($p as $k => $v) {
        if ($k === 'created_at') continue;
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
        'FINANCE_NAME' => '',
        'LEADER_NAME' => '',
    ];
}

/* ------------------------------------------------------------------ */
/* Backup (dipakai api/backup.php dan api/cron_backup.php)              */
/* ------------------------------------------------------------------ */

const BACKUP_TABLES = ['users', 'settings', 'attendance', 'requests', 'payslips', 'agendas', 'duties'];

/** Buat file ZIP berisi: CSV per tabel (bisa dibuka Excel), hris.sql (restore via phpMyAdmin), dan opsional folder uploads. */
function build_backup_zip(string $zipPath, bool $includeFiles): void
{
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
