<?php
/**
 * HRIS PT Artha Safety Indonesia - fitur tambahan:
 *  - Kalender hari libur nasional & cuti bersama
 *  - Hari kerja Senin–Sabtu (Sabtu setengah hari), Minggu libur
 *  - Koreksi absen (lupa absen masuk/keluar) — hanya bisa diajukan di hari yang sama
 *  - Lembur sesuai PP 35/2021 (Depnaker)
 *  - Notifikasi email (SMTP Hostinger)
 */

declare(strict_types=1);

const OT_TYPE = 'Lembur';
const CORRECTION_TYPE = 'Koreksi Absen';
const OT_HOURLY_DIVISOR = 173;      // upah sejam = 1/173 x upah sebulan (PP 35/2021 Pasal 32)
const OT_MAX_WORKDAY_HOURS = 4;     // maks. lembur 4 jam/hari di hari kerja (PP 35/2021 Pasal 26)
const OT_MAX_WEEK_HOURS = 18;       // maks. 18 jam/minggu di hari kerja
const OT_MAX_BACKDATE_DAYS = 7;     // lembur boleh diajukan paling lambat 7 hari setelah tanggal lembur

function is_ot_type(string $type): bool { return strcasecmp(trim($type), OT_TYPE) === 0; }
function is_correction_type(string $type): bool { return strcasecmp(trim($type), CORRECTION_TYPE) === 0; }

/* ================================================================== */
/* HARI KERJA & HARI LIBUR                                              */
/* ================================================================== */

function holiday_on(string $ymd): ?array
{
    return q_one('SELECT * FROM holidays WHERE holiday_date = ?', [$ymd]);
}

/** Jenis hari: 'WORKDAY' (Senin–Jumat), 'SATURDAY' (hari kerja pendek), 'SUNDAY', 'HOLIDAY'. */
function day_kind(string $ymd): string
{
    if (holiday_on($ymd)) return 'HOLIDAY';
    $dow = (int)date('N', strtotime($ymd)); // 1=Senin .. 7=Minggu
    if ($dow === 7) return 'SUNDAY';
    if ($dow === 6) return 'SATURDAY';
    return 'WORKDAY';
}

/** Jam kerja normal [mulai, selesai] dalam menit untuk tanggal tsb, atau null jika hari libur. */
function work_hours_on(string $ymd, ?array $settings = null): ?array
{
    $kind = day_kind($ymd);
    if ($kind === 'SUNDAY' || $kind === 'HOLIDAY') return null;
    $s = $settings ?? get_settings();
    $start = time_to_minutes(parse_time_any($s['WORK_START_TIME']) ?? '08:00:00');
    $end = $kind === 'SATURDAY'
        ? time_to_minutes(parse_time_any($s['WORK_END_SATURDAY'] ?? '12:00') ?? '12:00:00')
        : time_to_minutes(parse_time_any($s['WORK_END_TIME']) ?? '17:00:00');
    return [$start, $end];
}

function out_holiday(array $h): array
{
    return ['DATE' => fmt_date($h['holiday_date']), 'NAME' => $h['name'], 'TYPE' => $h['type']];
}

function list_holidays(): array
{
    return array_map('out_holiday', q_all('SELECT * FROM holidays ORDER BY holiday_date'));
}

function action_add_holiday(array $d): array
{
    $date = parse_date_any($d['date'] ?? null);
    $name = trim((string)($d['name'] ?? ''));
    $type = strtoupper(trim((string)($d['type'] ?? ''))) === 'CUTI BERSAMA' ? 'CUTI BERSAMA' : 'LIBUR NASIONAL';
    if (!$date || $name === '') return ['success' => false, 'message' => 'Tanggal dan nama hari libur wajib diisi.'];
    q('INSERT INTO holidays (holiday_date, name, type) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type)', [$date, mb_substr($name, 0, 150), $type]);
    return ['success' => true, 'message' => 'Hari libur ' . fmt_date($date) . ' (' . $name . ') disimpan.'];
}

function action_delete_holiday(array $d): array
{
    $date = parse_date_any($d['date'] ?? null);
    $n = $date ? q('DELETE FROM holidays WHERE holiday_date = ?', [$date])->rowCount() : 0;
    return $n ? ['success' => true, 'message' => 'Hari libur dihapus.'] : ['success' => false, 'message' => 'Hari libur tidak ditemukan.'];
}

/* ================================================================== */
/* LEMBUR (PP 35/2021)                                                   */
/* ================================================================== */

/**
 * Hitung upah lembur.
 * - Upah sejam = (gaji pokok + tunjangan tetap) / 173.
 * - Hari kerja: jam ke-1 = 1,5x; jam berikutnya = 2x. Maks. 4 jam.
 * - Hari istirahat mingguan (Minggu) / libur resmi, pola 6 hari kerja:
 *     jam ke-1 s/d 7 = 2x; jam ke-8 = 3x; jam ke-9 s/d 11 = 4x.
 * - Libur resmi yang jatuh di hari kerja terpendek (Sabtu):
 *     jam ke-1 s/d 5 = 2x; jam ke-6 = 3x; jam ke-7 s/d 9 = 4x.
 * Jam yang tidak genap dihitung proporsional per menit.
 */
function overtime_calc(array $user, string $ymd, float $hours): array
{
    $kind = day_kind($ymd);
    $isSatHoliday = $kind === 'HOLIDAY' && (int)date('N', strtotime($ymd)) === 6;
    if ($kind === 'WORKDAY' || $kind === 'SATURDAY') {
        $tiers = [[1, 1.5], [PHP_INT_MAX, 2]];
        $max = OT_MAX_WORKDAY_HOURS;
        $label = 'Hari kerja';
    } elseif ($isSatHoliday) {
        $tiers = [[5, 2], [1, 3], [3, 4]];
        $max = 9;
        $label = 'Libur resmi (hari Sabtu)';
    } else {
        $tiers = [[7, 2], [1, 3], [3, 4]];
        $max = 11;
        $label = $kind === 'SUNDAY' ? 'Hari Minggu (istirahat mingguan)' : 'Libur resmi';
    }
    $base = (int)$user['basic_salary'] + (int)$user['allowance'];
    $hourly = $base / OT_HOURLY_DIVISOR;
    $left = $hours;
    $multiplied = 0.0;
    foreach ($tiers as [$h, $mult]) {
        if ($left <= 0) break;
        $take = min($left, $h);
        $multiplied += $take * $mult;
        $left -= $take;
    }
    return [
        'kind' => $kind,
        'label' => $label,
        'max' => $max,
        'hourly' => $hourly,
        'pay' => (int)round($multiplied * $hourly),
        'factor' => $multiplied,
    ];
}

/** Validasi + hitung pengajuan lembur. Mengembalikan [start, end, hours, pay] atau melempar ApiError. */
function validate_overtime(array $me, string $date, $startRaw, $endRaw, ?string $excludeReqId = null): array
{
    $start = parse_time_any($startRaw);
    $end = parse_time_any($endRaw);
    if (!$start || !$end) throw new ApiError('Jam mulai dan jam selesai lembur wajib diisi.');
    $today = date('Y-m-d');
    if ($date > $today) throw new ApiError('Lembur diajukan setelah dikerjakan (tanggal lembur tidak boleh di masa depan).');
    if ((strtotime($today) - strtotime($date)) / 86400 > OT_MAX_BACKDATE_DAYS) {
        throw new ApiError('Pengajuan lembur paling lambat ' . OT_MAX_BACKDATE_DAYS . ' hari setelah tanggal lembur.');
    }
    $sMin = time_to_minutes($start);
    $eMin = time_to_minutes($end);
    if ($eMin <= $sMin) $eMin += 24 * 60; // lewat tengah malam
    $minutes = $eMin - $sMin;
    if ($date === $today && $eMin <= 24 * 60 && $eMin > time_to_minutes(date('H:i:s'))) {
        throw new ApiError('Jam selesai lembur belum lewat. Ajukan setelah lembur selesai.');
    }
    $hours = round($minutes / 60, 2);
    if ($hours < 0.5) throw new ApiError('Durasi lembur minimal 30 menit.');

    $work = work_hours_on($date);
    if ($work) {
        [$ws, $we] = $work;
        if ($sMin < $we && $eMin > $ws) {
            throw new ApiError('Jam lembur bertabrakan dengan jam kerja normal (' . sprintf('%02d:%02d', intdiv($ws, 60), $ws % 60)
                . '–' . sprintf('%02d:%02d', intdiv($we, 60), $we % 60) . '). Lembur dihitung di luar jam kerja.');
        }
    }

    $calc = overtime_calc($me, $date, $hours);
    if ($hours > $calc['max']) {
        throw new ApiError('Lembur maksimal ' . $calc['max'] . ' jam pada ' . strtolower($calc['label']) . ' (sesuai PP 35/2021).');
    }

    // Tidak boleh dobel / tumpang tindih di tanggal yang sama
    $others = q_all("SELECT req_id, ot_start, ot_end FROM requests WHERE emp_id = ? AND start_date = ? AND type = ?
                     AND UPPER(status) NOT IN ('REJECTED','DITOLAK')" . ($excludeReqId ? ' AND req_id <> ?' : ''),
        array_merge([$me['emp_id'], $date, OT_TYPE], $excludeReqId ? [$excludeReqId] : []));
    foreach ($others as $o) {
        $os = time_to_minutes($o['ot_start']);
        $oe = time_to_minutes($o['ot_end']);
        if ($oe <= $os) $oe += 24 * 60;
        if ($sMin < $oe && $eMin > $os) throw new ApiError('Sudah ada pengajuan lembur ' . $o['req_id'] . ' di jam yang sama.');
    }

    // Maks. 18 jam/minggu untuk lembur di hari kerja
    if ($calc['kind'] === 'WORKDAY' || $calc['kind'] === 'SATURDAY') {
        $monday = date('Y-m-d', strtotime($date . ' -' . ((int)date('N', strtotime($date)) - 1) . ' days'));
        $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
        $week = 0.0;
        foreach (q_all("SELECT start_date, ot_hours FROM requests WHERE emp_id = ? AND type = ? AND start_date BETWEEN ? AND ?
                         AND UPPER(status) NOT IN ('REJECTED','DITOLAK')" . ($excludeReqId ? ' AND req_id <> ?' : ''),
            array_merge([$me['emp_id'], OT_TYPE, $monday, $sunday], $excludeReqId ? [$excludeReqId] : [])) as $w) {
            $k = day_kind($w['start_date']);
            if ($k === 'WORKDAY' || $k === 'SATURDAY') $week += (float)$w['ot_hours'];
        }
        if ($week + $hours > OT_MAX_WEEK_HOURS) {
            throw new ApiError('Total lembur hari kerja minggu ini sudah ' . $week . ' jam. Maksimal ' . OT_MAX_WEEK_HOURS . ' jam/minggu (PP 35/2021).');
        }
    }
    return [$start, $end, $hours, $calc['pay'], $calc];
}

/** Total lembur yang sudah DISETUJUI pada periode slip gaji (bulan tsb). */
function overtime_for_month(string $empId, string $monthStart, string $monthEnd): array
{
    $r = q_one("SELECT COALESCE(SUM(ot_hours),0) AS h, COALESCE(SUM(ot_pay),0) AS p FROM requests
                WHERE emp_id = ? AND type = ? AND UPPER(status) IN ('APPROVED','DISETUJUI') AND start_date BETWEEN ? AND ?",
        [$empId, OT_TYPE, $monthStart, $monthEnd]);
    return [round((float)$r['h'], 2), (int)$r['p']];
}

/** "November 2026" -> ['2026-11-01', '2026-11-30'] */
function period_range(string $period): ?array
{
    if (!preg_match('/^\s*(\p{L}+)\s+(\d{4})\s*$/u', $period, $m)) return null;
    $idx = array_search(mb_strtolower($m[1]), array_map('mb_strtolower', MONTH_NAMES_ID), true);
    if ($idx === false) return null;
    $start = sprintf('%04d-%02d-01', (int)$m[2], $idx + 1);
    return [$start, date('Y-m-t', strtotime($start))];
}

/* ================================================================== */
/* KOREKSI ABSEN                                                        */
/* ================================================================== */

/** Validasi pengajuan koreksi absen. Hanya untuk HARI INI. Mengembalikan [kind, time]. */
function validate_correction(array $me, array $d): array
{
    $today = date('Y-m-d');
    $kind = strtoupper(trim((string)($d['correctionKind'] ?? '')));
    if (!in_array($kind, ['IN', 'OUT'], true)) throw new ApiError('Pilih jenis koreksi: lupa absen masuk atau lupa absen keluar.');
    $time = parse_time_any($d['correctionTime'] ?? null);
    if (!$time) throw new ApiError('Jam absen yang sebenarnya wajib diisi.');
    if (time_to_minutes($time) > time_to_minutes(date('H:i:s'))) throw new ApiError('Jam koreksi tidak boleh lebih dari jam sekarang.');

    $att = q_one('SELECT * FROM attendance WHERE emp_id = ? AND date = ?', [$me['emp_id'], $today]);
    if ($kind === 'IN' && $att && $att['checkin_time']) {
        throw new ApiError('Anda sudah tercatat absen masuk hari ini jam ' . fmt_time($att['checkin_time'])
            . '. Koreksi absen hanya untuk yang belum absen sama sekali. Jika terlambat karena kendala (mis. kendaraan rusak / macet), '
            . 'ajukan "Izin Terlambat" — bila disetujui HR, keterlambatan bebas potongan.');
    }
    if ($kind === 'OUT') {
        if ($att && $att['checkout_time']) throw new ApiError('Anda sudah tercatat absen keluar hari ini jam ' . fmt_time($att['checkout_time']) . '. Koreksi tidak diperlukan.');
        $pendingIn = q_val("SELECT 1 FROM requests WHERE emp_id = ? AND start_date = ? AND type = ? AND correction_kind = 'IN'
                            AND UPPER(status) NOT IN ('REJECTED','DITOLAK')", [$me['emp_id'], $today, CORRECTION_TYPE]);
        if (!$att && !$pendingIn) throw new ApiError('Belum ada absen masuk hari ini. Ajukan koreksi "Lupa Absen Masuk" terlebih dahulu.');
        if ($att && $att['checkin_time'] && time_to_minutes($time) <= time_to_minutes($att['checkin_time'])) {
            throw new ApiError('Jam keluar harus setelah jam masuk (' . fmt_time($att['checkin_time']) . ').');
        }
    }
    if (q_val("SELECT 1 FROM requests WHERE emp_id = ? AND start_date = ? AND type = ? AND correction_kind = ?
               AND UPPER(status) NOT IN ('REJECTED','DITOLAK')", [$me['emp_id'], $today, CORRECTION_TYPE, $kind])) {
        throw new ApiError('Koreksi absen ' . ($kind === 'IN' ? 'masuk' : 'keluar') . ' hari ini sudah pernah diajukan.');
    }
    return [$kind, $time];
}

/** Terapkan koreksi absen yang disetujui HR ke data absensi. */
function apply_correction(array $r): void
{
    $date = $r['start_date'];
    $time = $r['correction_time'];
    $att = q_one('SELECT * FROM attendance WHERE emp_id = ? AND date = ?', [$r['emp_id'], $date]);
    if ($r['correction_kind'] === 'IN') {
        $settings = get_settings();
        $status = time_to_minutes($time) > time_to_minutes(parse_time_any($settings['WORK_START_TIME']) ?? '08:00:00') ? 'TERLAMBAT' : 'TEPAT WAKTU';
        if ($att) {
            q('UPDATE attendance SET checkin_time = ?, status = ? WHERE att_id = ?', [$time, $status, $att['att_id']]);
        } else {
            q("INSERT INTO attendance (att_id, date, emp_id, full_name, checkin_time, status, location_name)
               VALUES (?, ?, ?, ?, ?, ?, ?)", [random_id('attendance', 'att_id', 'ATT'), $date, $r['emp_id'], $r['full_name'],
                $time, $status, 'Koreksi Absen (disetujui HR)']);
        }
    } else {
        if (!$att) throw new ApiError('Data absen masuk tanggal ' . fmt_date($date) . ' belum ada. Setujui dulu koreksi absen masuknya.');
        q('UPDATE attendance SET checkout_time = ? WHERE att_id = ?', [$time, $att['att_id']]);
    }
}

/* ================================================================== */
/* EMAIL (SMTP)                                                          */
/* ================================================================== */

$GLOBALS['HRIS_MAIL_QUEUE'] = [];

function mail_settings(): array
{
    $s = get_settings();
    return [
        'enabled' => to_bool($s['NOTIF_ENABLED'] ?? 'FALSE'),
        'host' => trim($s['SMTP_HOST'] ?? 'smtp.hostinger.com') ?: 'smtp.hostinger.com',
        'port' => (int)($s['SMTP_PORT'] ?? 465) ?: 465,
        'user' => trim($s['SMTP_USER'] ?? ''),
        'pass' => (string)($s['SMTP_PASS'] ?? ''),
        'from_name' => trim($s['SMTP_FROM_NAME'] ?? '') ?: 'HRIS PT Artha Safety Indonesia',
        'hr_emails' => trim($s['NOTIF_HR_EMAILS'] ?? ''),
    ];
}

function valid_mail(string $e): bool
{
    return (bool)filter_var($e, FILTER_VALIDATE_EMAIL) && !str_ends_with(strtolower($e), '.local');
}

function hr_recipients(): array
{
    $cfg = mail_settings();
    $list = array_filter(array_map('trim', preg_split('/[,;\s]+/', $cfg['hr_emails'])), 'valid_mail');
    if (!$list) {
        $list = array_filter(array_column(q_all("SELECT email FROM users WHERE role = 'ADMIN' AND is_active = 1"), 'email'), 'valid_mail');
    }
    return array_values(array_unique($list));
}

function app_url(): string
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) return '';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $base . '/';
}

function mail_layout(string $title, string $bodyHtml): string
{
    $url = app_url();
    $btn = $url ? '<p style="margin:24px 0 0"><a href="' . htmlspecialchars($url) . '" style="background:#059669;color:#fff;text-decoration:none;padding:10px 18px;border-radius:10px;font-weight:bold;font-size:13px">Buka Portal HRIS</a></p>' : '';
    return '<!doctype html><html><body style="margin:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a">'
        . '<div style="max-width:560px;margin:0 auto;padding:24px 16px">'
        . '<div style="background:#0f172a;color:#fff;padding:14px 20px;border-radius:14px 14px 0 0;font-weight:bold;font-size:14px">HRIS PT Artha Safety Indonesia</div>'
        . '<div style="background:#fff;padding:20px;border-radius:0 0 14px 14px;border:1px solid #e2e8f0;border-top:0">'
        . '<h2 style="font-size:17px;margin:0 0 12px">' . htmlspecialchars($title) . '</h2>'
        . '<div style="font-size:13px;line-height:1.6">' . $bodyHtml . '</div>' . $btn
        . '</div><p style="font-size:11px;color:#94a3b8;text-align:center;margin-top:12px">Email otomatis dari sistem HRIS. Mohon tidak membalas email ini.</p>'
        . '</div></body></html>';
}

/** Antrekan email; dikirim setelah respons ke browser selesai (supaya aplikasi tidak terasa lambat). */
function queue_mail(array $to, string $subject, string $html): void
{
    if (!mail_settings()['enabled']) return;
    $to = array_values(array_filter($to, 'valid_mail'));
    if ($to) $GLOBALS['HRIS_MAIL_QUEUE'][] = [$to, $subject, $html];
}

function flush_mail_queue(): void
{
    $queue = $GLOBALS['HRIS_MAIL_QUEUE'] ?? [];
    if (!$queue) return;
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    foreach ($queue as [$to, $subject, $html]) {
        try {
            smtp_send($to, $subject, $html);
        } catch (Throwable $e) {
            error_log('[HRIS mail] ' . $e->getMessage());
            try {
                q("INSERT INTO settings (setting_key, setting_value) VALUES ('MAIL_LAST_ERROR', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [mb_substr(date('d/m/Y H:i') . ' — ' . $e->getMessage(), 0, 500)]);
            } catch (Throwable $ignored) {}
        }
    }
    $GLOBALS['HRIS_MAIL_QUEUE'] = [];
}

/** Kirim email lewat SMTP (port 465 SSL atau 587 STARTTLS). Melempar Exception jika gagal. */
function smtp_send(array $to, string $subject, string $html, ?array $cfg = null): void
{
    $cfg = $cfg ?? mail_settings();
    if ($cfg['user'] === '' || $cfg['pass'] === '') throw new RuntimeException('Akun email pengirim (SMTP) belum diatur.');
    $host = $cfg['host'];
    $port = $cfg['port'];
    $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("Tidak bisa terhubung ke server email $host:$port ($errstr).");
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function (string $c, array $okCodes) use ($fp, $read): string {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        $r = $read();
        $code = (int)substr($r, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            $shown = str_starts_with($c, 'AUTH') || strlen($c) > 60 ? '' : " (perintah: $c)";
            throw new RuntimeException('Server email menolak' . $shown . ': ' . trim($r));
        }
        return $r;
    };

    try {
        $cmd('', [220]);
        $ehloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $cmd('EHLO ' . $ehloHost, [250]);
        if ($port !== 465) {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Gagal mengaktifkan TLS.');
            $cmd('EHLO ' . $ehloHost, [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($cfg['user']), [334]);
        try {
            $cmd(base64_encode($cfg['pass']), [235]);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Login email gagal. Periksa alamat email & password akun email pengirim.');
        }
        $cmd('MAIL FROM:<' . $cfg['user'] . '>', [250]);
        foreach ($to as $rcpt) $cmd('RCPT TO:<' . $rcpt . '>', [250, 251]);
        $cmd('DATA', [354]);

        $boundary = 'b' . bin2hex(random_bytes(8));
        $text = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/tr|\/h2|\/div)[^>]*>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $headers = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode($cfg['from_name']) . '?= <' . $cfg['user'] . '>',
            'To: ' . implode(', ', array_map(fn($e) => '<' . $e . '>', $to)),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $cfg['user'])[1] ?? 'hris') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . "--$boundary--\r\n";
        fwrite($fp, $body . "\r\n.\r\n");
        $cmd('', [250]);
        $cmd('QUIT', [221, 250]);
    } finally {
        fclose($fp);
    }
}

function rp(int $n): string
{
    return 'Rp ' . number_format($n, 0, ',', '.');
}

function request_summary_html(array $r): string
{
    $rows = [
        'Nama' => $r['full_name'] . ' (' . $r['emp_id'] . ')',
        'Jenis' => $r['type'],
    ];
    if (is_ot_type($r['type'])) {
        $rows['Tanggal'] = fmt_date($r['start_date']);
        $rows['Jam lembur'] = fmt_time($r['ot_start']) . ' – ' . fmt_time($r['ot_end']) . ' (' . (float)$r['ot_hours'] . ' jam)';
        $rows['Perkiraan upah lembur'] = rp((int)$r['ot_pay']);
    } elseif (is_correction_type($r['type'])) {
        $rows['Tanggal'] = fmt_date($r['start_date']);
        $rows['Koreksi'] = ($r['correction_kind'] === 'IN' ? 'Lupa absen masuk' : 'Lupa absen keluar') . ', jam ' . fmt_time($r['correction_time']);
    } else {
        $rows['Periode'] = fmt_date($r['start_date']) . ' s/d ' . fmt_date($r['end_date']);
    }
    $rows['Alasan'] = (string)$r['reason'];
    $html = '<table style="border-collapse:collapse;width:100%;font-size:13px">';
    foreach ($rows as $k => $v) {
        $html .= '<tr><td style="padding:5px 8px 5px 0;color:#64748b;white-space:nowrap;vertical-align:top">' . htmlspecialchars($k)
            . '</td><td style="padding:5px 0;font-weight:bold">' . nl2br(htmlspecialchars($v)) . '</td></tr>';
    }
    return $html . '</table>';
}

function notify_new_request(string $reqId): void
{
    $r = q_one('SELECT * FROM requests WHERE req_id = ?', [$reqId]);
    if (!$r) return;
    $to = hr_recipients();
    // Karyawan dengan hak akses Persetujuan juga diberi tahu (kecuali pengajuannya sendiri & lembur)
    if (!is_ot_type($r['type'])) {
        foreach (q_all("SELECT emp_id, email, role, permissions FROM users WHERE is_active = 1 AND role <> 'ADMIN' AND permissions LIKE '%APPROVE%'") as $u) {
            if ($u['emp_id'] !== $r['emp_id'] && has_perm($u, PERM_APPROVE) && valid_mail((string)$u['email'])) $to[] = $u['email'];
        }
    }
    queue_mail(array_values(array_unique($to)), 'Pengajuan baru: ' . $r['type'] . ' — ' . $r['full_name'],
        mail_layout('Pengajuan baru menunggu persetujuan', '<p>Ada pengajuan baru <b>' . htmlspecialchars($r['req_id']) . '</b>:</p>'
            . request_summary_html($r) . '<p>Silakan buka menu <b>Persetujuan</b> di HRIS untuk menyetujui atau menolak.</p>'));
}

function notify_request_decision(string $reqId): void
{
    $r = q_one('SELECT r.*, u.email FROM requests r JOIN users u ON u.emp_id = r.emp_id WHERE r.req_id = ?', [$reqId]);
    if (!$r) return;
    $ok = is_approved($r['status']);
    $note = trim((string)$r['admin_note']);
    queue_mail([$r['email']], 'Pengajuan ' . $r['type'] . ' Anda ' . ($ok ? 'DISETUJUI' : 'DITOLAK'),
        mail_layout('Pengajuan ' . ($ok ? 'disetujui ✅' : 'ditolak ❌'),
            '<p>Halo ' . htmlspecialchars($r['full_name']) . ', pengajuan Anda <b>' . htmlspecialchars($r['req_id']) . '</b> telah '
            . ($ok ? '<b style="color:#059669">DISETUJUI</b>' : '<b style="color:#dc2626">DITOLAK</b>') . ' oleh ' . htmlspecialchars($r['approved_by'])
            . ' pada ' . date('d/m/Y H:i', strtotime((string)$r['approved_at'])) . '.</p>' . request_summary_html($r)
            . ($note !== '' && $note !== '-' ? '<p><b>Catatan HR:</b> ' . nl2br(htmlspecialchars($note)) . '</p>' : '')));
}

/**
 * Nilai slip gaji seperti yang tampil di HRIS (logika sama dengan tampilan di browser).
 */
function payslip_view(array $p, ?array $u): array
{
    $v = $p;
    if ($u) {
        foreach (['full_name', 'position', 'department', 'basic_salary', 'allowance', 'bpjs_tk_amount', 'has_bpjs_kes', 'bpjs_kes_amount', 'kasbon_amount'] as $k) {
            if (isset($u[$k]) && $u[$k] !== '') $v[$k] = $u[$k];
        }
        if (isset($u['encashed_holidays'])) {
            $v['encashed_holidays'] = (int)$u['encashed_holidays'];
            $v['encashed_holiday_pay'] = (int)$u['encashed_holidays'] * ENCASHED_HOLIDAY_RATE;
        }
    }
    $manual = $p['status'] === 'TERBIT MANUAL';
    $late = (int)$p['late_count'];
    $v['late_deduction'] = $manual ? (int)$p['late_deduction'] : ($late >= 3 ? $late - 2 : 0) * LATE_PENALTY;
    if (($u && is_bonus_exempt($u))) $v['on_time_bonus'] = 0;
    elseif (!$manual) $v['on_time_bonus'] = $late === 0 ? ON_TIME_BONUS : 0;
    $kes = (int)$v['has_bpjs_kes'] ? (int)$v['bpjs_kes_amount'] : 0;
    $v['deductions'] = (int)$v['late_deduction'] + (int)$v['bpjs_tk_amount'] + $kes + (int)$v['kasbon_amount'];
    $v['net_salary'] = (int)$v['basic_salary'] + (int)$v['allowance'] + (int)$v['on_time_bonus'] + (int)$v['encashed_holiday_pay']
        + (int)($v['overtime_pay'] ?? 0) - $v['deductions'];
    $v['bpjs_kes_effective'] = $kes;
    return $v;
}

function payslip_email_html(array $v): string
{
    $inc = [['Gaji Pokok', (int)$v['basic_salary']], ['Tunjangan Jabatan & Operasional', (int)$v['allowance']]];
    if ((int)$v['on_time_bonus'] > 0) $inc[] = ['Bonus Ketepatan Waktu', (int)$v['on_time_bonus']];
    if ((int)$v['encashed_holiday_pay'] > 0) $inc[] = ['Tabungan Libur Diuangkan (' . (int)$v['encashed_holidays'] . ' hari)', (int)$v['encashed_holiday_pay']];
    if ((int)($v['overtime_pay'] ?? 0) > 0) $inc[] = ['Upah Lembur (' . (float)$v['overtime_hours'] . ' jam)', (int)$v['overtime_pay']];
    $ded = [['Potongan Keterlambatan', (int)$v['late_deduction']], ['BPJS Ketenagakerjaan', (int)$v['bpjs_tk_amount']]];
    if ($v['bpjs_kes_effective'] > 0) $ded[] = ['BPJS Kesehatan', $v['bpjs_kes_effective']];
    if ((int)$v['kasbon_amount'] > 0) $ded[] = ['Kasbon', (int)$v['kasbon_amount']];

    $row = fn($l, $n, $c) => '<tr><td style="padding:6px 0;border-bottom:1px solid #f1f5f9">' . htmlspecialchars($l)
        . '</td><td style="padding:6px 0;border-bottom:1px solid #f1f5f9;text-align:right;color:' . $c . ';font-family:monospace">' . rp($n) . '</td></tr>';
    $html = '<p>Halo ' . htmlspecialchars($v['full_name']) . ', berikut slip gaji Anda periode <b>' . htmlspecialchars($v['period']) . '</b>.</p>'
        . '<p style="font-size:12px;color:#64748b">' . htmlspecialchars($v['emp_id'] . ' · ' . $v['position'] . ' · ' . $v['department']) . '</p>'
        . '<table style="border-collapse:collapse;width:100%;font-size:13px"><tr><td colspan="2" style="padding-top:8px;font-weight:bold;color:#059669">PENERIMAAN</td></tr>';
    foreach ($inc as [$l, $n]) $html .= $row($l, $n, '#059669');
    $html .= '<tr><td colspan="2" style="padding-top:12px;font-weight:bold;color:#dc2626">POTONGAN</td></tr>';
    foreach ($ded as [$l, $n]) $html .= $row($l, $n, '#dc2626');
    $html .= '<tr><td style="padding:10px 0;font-weight:bold;font-size:15px">GAJI BERSIH (THP)</td><td style="padding:10px 0;text-align:right;font-weight:bold;font-size:15px;font-family:monospace">'
        . rp((int)$v['net_salary']) . '</td></tr></table>'
        . '<p style="font-size:12px;color:#64748b">Slip lengkap & versi cetak tersedia di menu <b>Slip Gaji</b> pada portal HRIS.</p>';
    return mail_layout('Slip Gaji ' . $v['period'], $html);
}

function action_send_payslip_emails(array $d): array
{
    $cfg = mail_settings();
    if ($cfg['user'] === '' || $cfg['pass'] === '') return ['success' => false, 'message' => 'Atur dulu akun email pengirim di menu Pengaturan Kantor → Notifikasi Email.'];
    $period = trim((string)($d['period'] ?? ''));
    if ($period === '' || $period === 'ALL') return ['success' => false, 'message' => 'Pilih satu periode slip gaji terlebih dahulu (filter periode).'];
    @set_time_limit(300);
    $rows = q_all('SELECT p.* FROM payslips p WHERE p.period = ? ORDER BY p.emp_id', [$period]);
    if (!$rows) return ['success' => false, 'message' => 'Tidak ada slip gaji untuk periode ' . $period . '.'];
    $sent = 0; $fail = []; $skip = [];
    foreach ($rows as $p) {
        $u = q_one('SELECT * FROM users WHERE emp_id = ?', [$p['emp_id']]);
        if (!$u || !(int)$u['is_active']) { $skip[] = $p['full_name'] . ' (nonaktif)'; continue; }
        if (!valid_mail((string)$u['email'])) { $skip[] = $p['full_name'] . ' (email belum valid)'; continue; }
        try {
            smtp_send([$u['email']], 'Slip Gaji ' . $period . ' — ' . $u['full_name'], payslip_email_html(payslip_view($p, $u)), $cfg);
            $sent++;
        } catch (Throwable $e) {
            $fail[] = $p['full_name'] . ': ' . $e->getMessage();
            if (str_contains($e->getMessage(), 'Login email gagal') || str_contains($e->getMessage(), 'Tidak bisa terhubung')) break;
        }
    }
    $msg = "$sent slip gaji periode $period terkirim ke email karyawan.";
    if ($skip) $msg .= ' Dilewati: ' . implode(', ', $skip) . '.';
    if ($fail) $msg .= ' Gagal: ' . implode(' | ', array_slice($fail, 0, 3));
    return ['success' => $sent > 0 && !$fail, 'message' => $msg];
}

function action_get_email_settings(): array
{
    $c = mail_settings();
    $s = get_settings();
    return ['success' => true, 'email' => [
        'enabled' => $c['enabled'],
        'host' => $c['host'],
        'port' => $c['port'],
        'user' => $c['user'],
        'hasPassword' => $c['pass'] !== '',
        'fromName' => $c['from_name'],
        'hrEmails' => $c['hr_emails'],
        'lastError' => $s['MAIL_LAST_ERROR'] ?? '',
    ]];
}

function action_save_email_settings(array $d): array
{
    $user = trim((string)($d['user'] ?? ''));
    if ($user !== '' && !filter_var($user, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => 'Alamat email pengirim tidak valid.'];
    $port = (int)($d['port'] ?? 465);
    if (!in_array($port, [465, 587, 25, 2525], true)) return ['success' => false, 'message' => 'Port SMTP tidak valid (gunakan 465).'];
    $hr = trim((string)($d['hrEmails'] ?? ''));
    foreach (array_filter(array_map('trim', preg_split('/[,;\s]+/', $hr))) as $e) {
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => "Email penerima HR tidak valid: $e"];
    }
    $vals = [
        'NOTIF_ENABLED' => to_bool($d['enabled'] ?? false) ? 'TRUE' : 'FALSE',
        'SMTP_HOST' => trim((string)($d['host'] ?? 'smtp.hostinger.com')) ?: 'smtp.hostinger.com',
        'SMTP_PORT' => (string)$port,
        'SMTP_USER' => $user,
        'SMTP_FROM_NAME' => mb_substr(trim((string)($d['fromName'] ?? '')), 0, 100),
        'NOTIF_HR_EMAILS' => mb_substr($hr, 0, 500),
        'MAIL_LAST_ERROR' => '',
    ];
    $pass = (string)($d['password'] ?? '');
    if ($pass !== '') $vals['SMTP_PASS'] = $pass;
    foreach ($vals as $k => $v) {
        q('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v]);
    }
    return ['success' => true, 'message' => 'Pengaturan email disimpan.'];
}

function action_test_email(array $me, array $d): array
{
    $to = trim((string)($d['to'] ?? '')) ?: (string)$me['email'];
    if (!valid_mail($to)) return ['success' => false, 'message' => 'Isi alamat email tujuan tes yang valid.'];
    try {
        smtp_send([$to], 'Tes email HRIS', mail_layout('Tes email berhasil ✅', '<p>Jika Anda menerima email ini, notifikasi email HRIS sudah berfungsi.</p>'));
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal mengirim: ' . $e->getMessage()];
    }
    return ['success' => true, 'message' => 'Email tes terkirim ke ' . $to . '. Cek kotak masuk (dan folder Spam).'];
}
