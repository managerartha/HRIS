<?php
/**
 * HRIS PT Artha Safety Indonesia - API Backend (Hostinger / PHP + MySQL)
 * ----------------------------------------------------------------------
 * Pengganti Google Apps Script (Code.gs). Format request & response sama:
 *   POST { "action": "...", "token": "...", ...data }  ->  { "success": true/false, ... }
 *
 * Perbedaan penting dengan versi Apps Script:
 *  - Setiap aksi (kecuali login) wajib membawa token sesi. Identitas karyawan
 *    diambil dari token di server, bukan dari empId kiriman browser, sehingga
 *    karyawan tidak bisa memalsukan absensi / melihat data orang lain.
 *  - Password disimpan dalam bentuk hash (password_hash), tidak pernah dikirim ke browser.
 *  - Foto disimpan sebagai file di folder uploads/, bukan teks base64 di database (lebih cepat).
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/features.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['success' => true, 'message' => 'HRIS PT Artha Safety Indonesia Backend Active.']);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        throw new ApiError('Data request POST kosong atau tidak valid.', 400);
    }
    $action = (string)($data['action'] ?? '');
    ensure_schema();

    if ($action === 'login') {
        $res = action_login($data);
    } elseif ($action === 'logout') {
        $res = action_logout($data);
    } else {
        $me = require_session($data);
        // updateRequestStatus diperiksa di dalam fungsinya (admin / karyawan dengan hak akses Persetujuan)
        $adminOnly = ['addDutyAssignment', 'updateDutyAssignment', 'deleteDutyAssignment', 'saveEmployee',
            'saveManualPayslip', 'updateOfficeSettings', 'resolveMapsLink', 'addHoliday', 'deleteHoliday',
            'getEmailSettings', 'saveEmailSettings', 'testEmail', 'sendPayslipEmails'];
        if (in_array($action, $adminOnly, true) && $me['role'] !== 'ADMIN') {
            throw new ApiError('Akses ditolak: fitur ini khusus Admin.', 403);
        }
        switch ($action) {
            case 'getDashboardData': $res = action_dashboard($me); break;
            case 'submitAttendance': $res = action_submit_attendance($me, $data); break;
            case 'updateProfilePhoto': $res = action_update_profile_photo($me, $data); break;
            case 'addAgenda': $res = action_add_agenda($me, $data); break;
            case 'deleteAgenda': $res = action_delete_agenda($me, $data); break;
            case 'addDutyAssignment': $res = action_add_duty($me, $data); break;
            case 'updateDutyAssignment': $res = action_update_duty($me, $data); break;
            case 'deleteDutyAssignment': $res = action_delete_duty($data); break;
            case 'resolveMapsLink': $res = ['success' => true] + maps_resolve_link((string)($data['url'] ?? '')); break;
            case 'submitRequest': $res = action_submit_request($me, $data); break;
            case 'updateRequestStatus': $res = action_update_request_status($me, $data); break;
            case 'saveEmployee': $res = action_save_employee($data, $me); break;
            case 'saveManualPayslip': $res = action_save_manual_payslip($data); break;
            case 'updateOfficeSettings': $res = action_update_settings($data); break;
            case 'addHoliday': $res = action_add_holiday($data); break;
            case 'deleteHoliday': $res = action_delete_holiday($data); break;
            case 'getEmailSettings': $res = action_get_email_settings(); break;
            case 'saveEmailSettings': $res = action_save_email_settings($data); break;
            case 'testEmail': $res = action_test_email($me, $data); break;
            case 'sendPayslipEmails': $res = action_send_payslip_emails($data); break;
            case 'previewOvertime': $res = action_preview_overtime($me, $data); break;
            default: $res = ['success' => false, 'message' => 'Action not found.'];
        }
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    flush_mail_queue();
} catch (ApiError $e) {
    if ($e->httpCode >= 400) http_response_code($e->httpCode);
    $out = ['success' => false, 'message' => $e->getMessage()];
    if ($e->httpCode >= 400) $out['code'] = $e->httpCode;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[HRIS] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server. Silakan coba lagi / hubungi admin.'], JSON_UNESCAPED_UNICODE);
}

/* ================================================================== */
/* SESI & LOGIN                                                        */
/* ================================================================== */

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function action_login(array $d): array
{
    $ident = strtolower(trim((string)($d['email'] ?? '')));
    $pass = trim((string)($d['password'] ?? ''));
    if ($ident === '' || $pass === '') {
        return ['success' => false, 'message' => 'Email / ID Karyawan dan kata sandi wajib diisi.'];
    }

    // Batasi percobaan login: maks 10x gagal per 15 menit per akun.
    q('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    $fails = (int)q_val('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)', [$ident]);
    if ($fails >= 10) {
        return ['success' => false, 'message' => 'Terlalu banyak percobaan login gagal. Tunggu 15 menit lalu coba lagi.'];
    }

    $user = q_one('SELECT * FROM users WHERE (LOWER(email) = ? OR LOWER(emp_id) = ?) LIMIT 1', [$ident, $ident]);
    if (!$user || !password_verify($pass, $user['password_hash'])) {
        q('INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)', [$ident, client_ip()]);
        return ['success' => false, 'message' => 'Email / ID Karyawan atau kata sandi tidak sesuai!'];
    }
    if (!(int)$user['is_active']) {
        return ['success' => false, 'message' => 'Akun Anda dinonaktifkan. Hubungi Admin HR.'];
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE emp_id = ?', [password_hash($pass, PASSWORD_DEFAULT), $user['emp_id']]);
    }
    q('DELETE FROM login_attempts WHERE identifier = ?', [$ident]);

    $token = bin2hex(random_bytes(32));
    // Login tersimpan di perangkat: sesi minimal 30 hari (keluar lewat tombol Logout)
    $hours = max((int)(config()['session_hours'] ?? 0), 24 * 30);
    q('DELETE FROM sessions WHERE expires_at < NOW()');
    q('INSERT INTO sessions (token_hash, emp_id, expires_at) VALUES (?, ?, NOW() + INTERVAL ' . max(1, $hours) . ' HOUR)',
        [hash('sha256', $token), $user['emp_id']]);

    return ['success' => true, 'token' => $token, 'user' => out_user($user)];
}

function action_logout(array $d): array
{
    $token = (string)($d['token'] ?? '');
    if ($token !== '') q('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]);
    return ['success' => true, 'message' => 'Berhasil keluar.'];
}

function require_session(array $d): array
{
    $token = (string)($d['token'] ?? '');
    if ($token === '') throw new ApiError('Sesi login tidak ditemukan. Silakan login ulang.', 401);
    $user = q_one('SELECT u.* FROM sessions s JOIN users u ON u.emp_id = s.emp_id
                   WHERE s.token_hash = ? AND s.expires_at > NOW() AND u.is_active = 1', [hash('sha256', $token)]);
    if (!$user) throw new ApiError('Sesi login sudah berakhir. Silakan login ulang.', 401);
    return $user;
}

/* ================================================================== */
/* DASHBOARD                                                            */
/* ================================================================== */

function action_dashboard(array $me): array
{
    $isAdmin = $me['role'] === 'ADMIN';
    $empId = $me['emp_id'];
    // Akses parsial (mis. supervisor): lihat absensi semua / laporan / persetujuan, tanpa data gaji
    $seeAllAtt = $isAdmin || has_perm($me, PERM_ATT_ALL) || has_perm($me, PERM_REPORT);
    $seeAllReq = $isAdmin || has_perm($me, PERM_APPROVE) || has_perm($me, PERM_REPORT);
    $canApprove = $isAdmin || has_perm($me, PERM_APPROVE);
    $today = date('Y-m-d');
    $settings = get_settings();

    $todayAtt = q_all('SELECT * FROM attendance WHERE date = ?', [$today]);
    $lateToday = count(array_filter($todayAtt, fn($a) => $a['status'] === 'TERLAMBAT'));
    $userTodayAtt = null;
    foreach ($todayAtt as $a) if ($a['emp_id'] === $empId) $userTodayAtt = out_attendance($a);

    $attRows = $seeAllAtt
        ? q_all('SELECT * FROM attendance ORDER BY date DESC, checkin_time DESC')
        : q_all('SELECT * FROM attendance WHERE emp_id = ? ORDER BY date DESC, checkin_time DESC', [$empId]);

    $allRequests = q_all('SELECT * FROM requests ORDER BY created_at DESC, req_id DESC');
    $pending = count(array_filter($allRequests, fn($r) => $r['status'] === 'PENDING'
        && ($isAdmin || ($r['emp_id'] !== $empId && can_approve_type($r['type'])))));
    $myRequests = array_values(array_filter($allRequests, fn($r) => $r['emp_id'] === $empId));

    // Status disiplin: tidak ada keterlambatan tanpa izin
    $approvedLate = array_filter($myRequests, fn($r) => is_approved($r['status']) && str_contains(strtolower($r['type']), 'terlambat'));
    $unexcused = 0;
    foreach (q_all("SELECT date FROM attendance WHERE emp_id = ? AND status = 'TERLAMBAT'", [$empId]) as $a) {
        if (!date_covered_by($a['date'], $approvedLate)) $unexcused++;
    }

    // Sinkron saldo tabungan libur (logika sama seperti versi Apps Script)
    $tabung = 0; $pengganti = 0;
    foreach ($myRequests as $r) {
        if (!is_approved($r['status'])) continue;
        $t = strtolower($r['type']);
        $days = days_between($r['start_date'], $r['end_date']);
        if (str_contains($t, 'tabung') || str_contains($t, 'menabung')) $tabung += $days;
        elseif (str_contains($t, 'pengganti')) $pengganti += $days;
    }
    $computedSaved = max(0, $tabung - $pengganti - (int)$me['encashed_holidays']);
    $effectiveSaved = max((int)$me['saved_holidays'], $computedSaved);
    if ($effectiveSaved !== (int)$me['saved_holidays']) {
        q('UPDATE users SET saved_holidays = ? WHERE emp_id = ?', [$effectiveSaved, $empId]);
        $me['saved_holidays'] = $effectiveSaved;
    }

    ensure_payslips($isAdmin ? null : $empId);

    $usersById = [];
    foreach (q_all('SELECT * FROM users') as $u) $usersById[$u['emp_id']] = $u;

    $payRows = $isAdmin
        ? q_all('SELECT * FROM payslips ORDER BY created_at DESC, pay_id DESC')
        : q_all('SELECT * FROM payslips WHERE emp_id = ? ORDER BY created_at DESC, pay_id DESC', [$empId]);

    $activeDuty = find_active_duty($empId, $today);

    $duties = ($isAdmin || has_perm($me, PERM_REPORT))
        ? q_all('SELECT * FROM duties ORDER BY created_at DESC, duty_id DESC')
        : q_all('SELECT * FROM duties WHERE emp_id = ? ORDER BY created_at DESC, duty_id DESC', [$empId]);

    $activeUsers = array_filter($usersById, fn($u) => (int)$u['is_active'] === 1);

    return [
        'success' => true,
        'currentUser' => out_user($me),
        'settings' => public_settings(),
        'holidays' => list_holidays(),
        'activeDuty' => $activeDuty ? out_duty($activeDuty) : null,
        'stats' => [
            'totalEmployees' => count($activeUsers),
            'presentToday' => count($todayAtt),
            'lateToday' => $lateToday,
            'leaveBalance' => (int)$me['leave_balance'],
            'savedHolidays' => $effectiveSaved,
            'pendingRequestsCount' => $canApprove ? $pending : 0,
            'isDisciplined' => $unexcused === 0,
        ],
        'userTodayAtt' => $userTodayAtt,
        'recentAttendance' => array_map('out_attendance', $attRows),
        'requests' => array_map(function ($r) use ($isAdmin, $empId) {
            $o = out_request($r);
            if (!$isAdmin && $r['emp_id'] !== $empId) $o['OT_PAY'] = null; // nominal upah lembur orang lain hanya untuk Admin
            return $o;
        }, $seeAllReq ? $allRequests : $myRequests),
        'payslips' => array_map(fn($p) => out_payslip($p, $usersById[$p['emp_id']] ?? null), $payRows),
        'allUsers' => $isAdmin ? array_map('out_user', array_values($usersById))
            : (($seeAllAtt || $seeAllReq) ? array_map('out_user_basic', array_values($usersById)) : []),
        'agendas' => array_map('out_agenda', q_all('SELECT * FROM agendas ORDER BY created_at DESC, agenda_id DESC')),
        'duties' => array_map('out_duty', $duties),
    ];
}

function is_approved(string $status): bool
{
    $s = strtoupper(trim($status));
    return $s === 'APPROVED' || $s === 'DISETUJUI';
}

function date_covered_by(string $date, array $requests): bool
{
    foreach ($requests as $r) {
        if ($r['start_date'] && $r['end_date'] && $date >= $r['start_date'] && $date <= $r['end_date']) return true;
    }
    return false;
}

function days_between(?string $start, ?string $end): int
{
    if (!$start || !$end) return 1;
    $d = (int)round(abs(strtotime($end) - strtotime($start)) / 86400) + 1;
    return max(1, $d);
}

function find_active_duty(string $empId, string $date): ?array
{
    return q_one('SELECT * FROM duties WHERE emp_id = ? AND ? BETWEEN start_date AND end_date
                  ORDER BY created_at DESC, duty_id DESC LIMIT 1', [$empId, $date]);
}

/**
 * Buat / perbarui slip gaji otomatis periode berjalan.
 * Perbaikan dari versi lama: keterlambatan dihitung hanya dari bulan & tahun berjalan
 * (versi lama ikut menghitung tanggal yang angkanya sama dengan nomor bulan, mis. tgl 11 di bulan lain).
 */
function ensure_payslips(?string $onlyEmpId): void
{
    $settings = get_settings();
    $period = current_period();
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');

    // Karyawan bebas bonus: bonus selalu 0 di seluruh slip.
    foreach (q_all('SELECT * FROM users') as $u) {
        if (is_bonus_exempt($u)) {
            q('UPDATE payslips SET net_salary = net_salary - on_time_bonus, on_time_bonus = 0
               WHERE emp_id = ? AND on_time_bonus > 0', [$u['emp_id']]);
        }
    }

    $users = $onlyEmpId
        ? q_all('SELECT * FROM users WHERE emp_id = ? AND is_active = 1', [$onlyEmpId])
        : q_all('SELECT * FROM users WHERE is_active = 1');

    foreach ($users as $u) {
        $id = $u['emp_id'];
        $existing = q_all('SELECT * FROM payslips WHERE emp_id = ? AND period = ?', [$id, $period]);
        foreach ($existing as $e) {
            if ($e['status'] === 'TERBIT MANUAL') continue 2; // slip manual tidak diubah otomatis
        }

        $approvedLate = q_all("SELECT start_date, end_date FROM requests WHERE emp_id = ? AND LOWER(type) LIKE '%terlambat%'
                                AND UPPER(status) IN ('APPROVED','DISETUJUI')", [$id]);
        $late = 0; $excused = 0;
        foreach (q_all("SELECT date FROM attendance WHERE emp_id = ? AND status = 'TERLAMBAT' AND date BETWEEN ? AND ?",
            [$id, $monthStart, $monthEnd]) as $a) {
            if (date_covered_by($a['date'], $approvedLate)) $excused++; else $late++;
        }

        $lateDed = ($late >= 3 ? $late - 2 : 0) * LATE_PENALTY;
        $basic = (int)$u['basic_salary'];
        $allow = (int)$u['allowance'];
        $bonus = (!is_bonus_exempt($u) && $late === 0) ? ON_TIME_BONUS : 0;
        $encDays = (int)$u['encashed_holidays'];
        $encPay = $encDays * ENCASHED_HOLIDAY_RATE;
        $bpjsTk = (int)$u['bpjs_tk_amount'];
        $hasKes = (int)$u['has_bpjs_kes'];
        $bpjsKes = $hasKes ? (int)$u['bpjs_kes_amount'] : 0;
        $kasbon = (int)$u['kasbon_amount'];
        $ded = $lateDed + $bpjsTk + $bpjsKes + $kasbon;
        [$otHours, $otPay] = overtime_for_month($id, $monthStart, $monthEnd); // lembur yang disetujui bulan ini
        $net = ($basic + $allow + $bonus + $encPay + $otPay) - $ded;

        $vals = [$u['full_name'], $u['position'], $u['department'], $basic, $allow, $bonus, $encDays, $encPay,
            $late, $excused, $lateDed, $bpjsTk, $hasKes, $bpjsKes, $kasbon, $ded, $net];

        if (!$existing) {
            q('INSERT INTO payslips (pay_id, period, emp_id, full_name, position, department, basic_salary, allowance,
                 on_time_bonus, encashed_holidays, encashed_holiday_pay, late_count, excused_late_count, late_deduction,
                 bpjs_tk_amount, has_bpjs_kes, bpjs_kes_amount, kasbon_amount, deductions, net_salary, payment_date,
                 status, finance_name, leader_name)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge([random_id('payslips', 'pay_id', 'PAY'), $period, $id], $vals,
                    [date('d/m/Y'), 'TERBIT', $settings['FINANCE_NAME'], $settings['LEADER_NAME']]));
        } else {
            q('UPDATE payslips SET full_name=?, position=?, department=?, basic_salary=?, allowance=?, on_time_bonus=?,
                 encashed_holidays=?, encashed_holiday_pay=?, late_count=?, excused_late_count=?, late_deduction=?,
                 bpjs_tk_amount=?, has_bpjs_kes=?, bpjs_kes_amount=?, kasbon_amount=?, deductions=?, net_salary=?
               WHERE pay_id = ?', array_merge($vals, [$existing[0]['pay_id']]));
        }
        q("UPDATE payslips SET overtime_hours = ?, overtime_pay = ? WHERE emp_id = ? AND period = ? AND status <> 'TERBIT MANUAL'",
            [$otHours, $otPay, $id, $period]);
    }
}

/* ================================================================== */
/* ABSENSI                                                              */
/* ================================================================== */

function action_submit_attendance(array $me, array $d): array
{
    $type = (string)($d['type'] ?? '');
    if ($type !== 'CHECKIN' && $type !== 'CHECKOUT') {
        return ['success' => false, 'message' => 'Tipe presensi tidak valid.'];
    }
    $lat = parse_coord($d['lat'] ?? null);
    $lng = parse_coord($d['lng'] ?? null);
    if ($lat === null || $lng === null || ($lat == 0 && $lng == 0)) {
        return ['success' => false, 'message' => 'Lokasi GPS tidak terbaca. Aktifkan izin lokasi lalu tekan Refresh GPS.'];
    }
    $accuracy = isset($d['accuracy']) && is_numeric($d['accuracy']) ? round((float)$d['accuracy'], 1) : null;
    $photo = (string)($d['photoBase64'] ?? '');
    if ($photo === '') {
        return ['success' => false, 'message' => 'Harap ambil foto selfie terlebih dahulu!'];
    }

    $settings = get_settings();
    $today = date('Y-m-d');
    $now = date('H:i:s');
    $empId = $me['emp_id'];

    $duty = find_active_duty($empId, $today);
    if ($duty) {
        $tLat = (float)$duty['latitude'];
        $tLng = (float)$duty['longitude'];
        $maxR = (float)($duty['radius_meters'] ?: 50);
        $locName = '📍 Dinas: ' . $duty['location_name'];
    } else {
        $tLat = parse_coord($settings['OFFICE_LAT']) ?? -6.175392;
        $tLng = parse_coord($settings['OFFICE_LNG']) ?? 106.827153;
        $maxR = (float)(parse_coord($settings['MAX_RADIUS_METERS']) ?? 50);
        $locName = $settings['OFFICE_NAME'];
    }

    $dist = distance_meters($tLat, $tLng, $lat, $lng);
    if ($dist > $maxR) {
        $accTxt = $accuracy !== null ? ' Akurasi GPS HP Anda ±' . round($accuracy) . 'm — coba tekan Refresh GPS di dekat jendela / area terbuka.' : '';
        return ['success' => false, 'message' => 'Gagal! Posisi Anda berada ' . $dist . ' meter dari lokasi target (Maksimal ' . $maxR . 'm).' . $accTxt];
    }

    $existing = q_one('SELECT * FROM attendance WHERE emp_id = ? AND date = ?', [$empId, $today]);

    if ($type === 'CHECKIN') {
        if ($existing) return ['success' => false, 'message' => 'Anda sudah melakukan Absen Masuk hari ini.'];
        $isLate = time_to_minutes($now) > time_to_minutes(parse_time_any($settings['WORK_START_TIME']) ?? '08:00:00');
        $status = $isLate ? 'TERLAMBAT' : 'TEPAT WAKTU';
        $path = save_data_url($photo, 'attendance', IMAGE_MIMES, 3 * 1048576);
        try {
            q('INSERT INTO attendance (att_id, date, emp_id, full_name, checkin_time, latitude, longitude, distance_meters,
                 gps_accuracy, checkin_photo, status, location_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [random_id('attendance', 'att_id', 'ATT'), $today, $empId, $me['full_name'], $now, $lat, $lng, $dist,
                    $accuracy, $path, $status, $locName]);
        } catch (PDOException $e) {
            @unlink(dirname(__DIR__) . '/' . $path);
            if ($e->getCode() === '23000') return ['success' => false, 'message' => 'Anda sudah melakukan Absen Masuk hari ini.'];
            throw $e;
        }
        return ['success' => true, 'message' => 'Absen Masuk berhasil dicatat pada jam ' . substr($now, 0, 5) . ' (' . $status . ')'];
    }

    if (!$existing) return ['success' => false, 'message' => 'Anda belum melakukan Absen Masuk hari ini.'];
    $path = save_data_url($photo, 'attendance', IMAGE_MIMES, 3 * 1048576);
    q('UPDATE attendance SET checkout_time = ?, checkout_photo = ?, checkout_latitude = ?, checkout_longitude = ?, checkout_distance = ?
       WHERE att_id = ?', [$now, $path, $lat, $lng, $dist, $existing['att_id']]);
    return ['success' => true, 'message' => 'Absen Keluar berhasil dicatat pada jam ' . substr($now, 0, 5)];
}

/* ================================================================== */
/* PROFIL, AGENDA, DINAS                                                */
/* ================================================================== */

function action_update_profile_photo(array $me, array $d): array
{
    $photo = trim((string)($d['photoBase64'] ?? ''));
    if ($photo === '') return ['success' => false, 'message' => 'Foto belum dipilih.'];
    if (str_starts_with($photo, 'data:')) {
        $path = save_data_url($photo, 'profile', IMAGE_MIMES, 3 * 1048576);
    } elseif (preg_match('#^https?://#i', $photo) && strlen($photo) <= 500) {
        $path = $photo;
    } else {
        return ['success' => false, 'message' => 'Format foto tidak valid.'];
    }
    q('UPDATE users SET profile_pic = ? WHERE emp_id = ?', [$path, $me['emp_id']]);
    return ['success' => true, 'message' => 'Foto profil berhasil diperbarui!', 'photoUrl' => public_url($path)];
}

function action_add_agenda(array $me, array $d): array
{
    $title = trim((string)($d['title'] ?? ''));
    if ($title === '') return ['success' => false, 'message' => 'Judul agenda wajib diisi.'];
    q('INSERT INTO agendas (agenda_id, date, title, description, category, created_by, created_by_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        next_seq_id('agendas', 'agenda_id', 'AGD'), parse_date_any($d['date'] ?? null), mb_substr($title, 0, 255),
        (string)($d['description'] ?? ''), mb_substr((string)($d['category'] ?? ''), 0, 100), $me['full_name'], $me['emp_id'],
    ]);
    return ['success' => true, 'message' => 'Agenda / catatan berhasil ditambahkan!'];
}

function action_delete_agenda(array $me, array $d): array
{
    $ag = q_one('SELECT * FROM agendas WHERE agenda_id = ?', [(string)($d['agendaId'] ?? '')]);
    if (!$ag) return ['success' => false, 'message' => 'Agenda tidak ditemukan.'];
    if ($me['role'] !== 'ADMIN' && $ag['created_by_id'] !== $me['emp_id']) {
        return ['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menghapus agenda ini.'];
    }
    q('DELETE FROM agendas WHERE agenda_id = ?', [$ag['agenda_id']]);
    return ['success' => true, 'message' => 'Agenda berhasil dihapus.'];
}

function action_add_duty(array $me, array $d): array
{
    $u = q_one('SELECT * FROM users WHERE emp_id = ?', [(string)($d['empId'] ?? '')]);
    if (!$u) return ['success' => false, 'message' => 'Karyawan tidak ditemukan.'];
    [$lat, $lng] = parse_latlng($d['lat'] ?? null, $d['lng'] ?? null);
    $start = parse_date_any($d['startDate'] ?? null);
    $end = parse_date_any($d['endDate'] ?? null);
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) return ['success' => false, 'message' => 'Koordinat lokasi dinas tidak valid.'];
    if (!$start || !$end) return ['success' => false, 'message' => 'Tanggal mulai / selesai dinas wajib diisi.'];
    if ($end < $start) [$start, $end] = [$end, $start];
    $radius = max(10, (int)($d['radius'] ?? 50));
    q('INSERT INTO duties (duty_id, emp_id, full_name, location_name, latitude, longitude, radius_meters, start_date, end_date, reason, created_by)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        next_seq_id('duties', 'duty_id', 'DUTY'), $u['emp_id'], $u['full_name'], (string)($d['locationName'] ?? ''),
        $lat, $lng, $radius, $start, $end, (string)($d['reason'] ?? ''), $me['full_name'],
    ]);
    return ['success' => true, 'message' => 'Tugas dinas luar kota berhasil diberikan kepada ' . $u['full_name'] . '!'];
}

function action_update_duty(array $me, array $d): array
{
    $duty = q_one('SELECT * FROM duties WHERE duty_id = ?', [(string)($d['dutyId'] ?? '')]);
    if (!$duty) return ['success' => false, 'message' => 'Data tugas dinas tidak ditemukan.'];
    $u = q_one('SELECT * FROM users WHERE emp_id = ?', [(string)($d['empId'] ?? '')]);
    if (!$u) return ['success' => false, 'message' => 'Karyawan tidak ditemukan.'];
    [$lat, $lng] = parse_latlng($d['lat'] ?? null, $d['lng'] ?? null);
    $start = parse_date_any($d['startDate'] ?? null);
    $end = parse_date_any($d['endDate'] ?? null);
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) return ['success' => false, 'message' => 'Koordinat lokasi dinas tidak valid.'];
    if (!$start || !$end) return ['success' => false, 'message' => 'Tanggal mulai / selesai dinas wajib diisi.'];
    if ($end < $start) [$start, $end] = [$end, $start];
    $radius = max(10, (int)($d['radius'] ?? 50));
    q('UPDATE duties SET emp_id = ?, full_name = ?, location_name = ?, latitude = ?, longitude = ?, radius_meters = ?,
         start_date = ?, end_date = ?, reason = ? WHERE duty_id = ?', [
        $u['emp_id'], $u['full_name'], (string)($d['locationName'] ?? ''), $lat, $lng, $radius, $start, $end,
        (string)($d['reason'] ?? ''), $duty['duty_id'],
    ]);
    return ['success' => true, 'message' => 'Tugas dinas ' . $duty['duty_id'] . ' (' . $u['full_name'] . ') berhasil diperbarui!'];
}

function action_delete_duty(array $d): array
{
    $n = q('DELETE FROM duties WHERE duty_id = ?', [(string)($d['dutyId'] ?? '')])->rowCount();
    return $n ? ['success' => true, 'message' => 'Tugas dinas berhasil dihapus.']
              : ['success' => false, 'message' => 'Data tugas dinas tidak ditemukan.'];
}

/* ================================================================== */
/* PENGAJUAN (CUTI / IZIN / TABUNG LIBUR)                               */
/* ================================================================== */

function action_submit_request(array $me, array $d): array
{
    $type = trim((string)($d['type'] ?? ''));
    $start = parse_date_any($d['startDate'] ?? null);
    $end = parse_date_any($d['endDate'] ?? null) ?? $start;
    $extra = [];
    if (is_correction_type($type)) {
        // Koreksi absen hanya untuk HARI INI (tanggal dari server, tidak bisa diubah dari browser)
        [$kind, $time] = validate_correction($me, $d);
        $type = CORRECTION_TYPE;
        $start = $end = date('Y-m-d');
        $extra = ['correction_kind' => $kind, 'correction_time' => $time];
    } elseif (is_ot_type($type)) {
        if (!$start) return ['success' => false, 'message' => 'Tanggal lembur wajib diisi.'];
        [$otStart, $otEnd, $hours, $pay] = validate_overtime($me, $start, $d['otStart'] ?? null, $d['otEnd'] ?? null);
        $type = OT_TYPE;
        $end = $start;
        $extra = ['ot_start' => $otStart, 'ot_end' => $otEnd, 'ot_hours' => $hours, 'ot_pay' => $pay];
    }
    if ($type === '' || !$start) return ['success' => false, 'message' => 'Jenis pengajuan dan tanggal wajib diisi.'];
    if ($end < $start) [$start, $end] = [$end, $start];

    $doc = '-';
    $file = (string)($d['fileBase64'] ?? '');
    if ($file !== '' && str_starts_with($file, 'data:')) {
        $doc = save_data_url($file, 'requests', DOC_MIMES, 15 * 1048576);
    }
    $reqId = next_seq_id('requests', 'req_id', 'REQ');
    q('INSERT INTO requests (req_id, submit_date, emp_id, full_name, type, start_date, end_date, reason, doc_attachment, status)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$reqId, date('Y-m-d'), $me['emp_id'], $me['full_name'], mb_substr($type, 0, 100), $start, $end, (string)($d['reason'] ?? ''), $doc, 'PENDING']);
    if ($extra) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($extra)));
        q("UPDATE requests SET $set WHERE req_id = ?", array_merge(array_values($extra), [$reqId]));
    }
    notify_new_request($reqId);
    $msg = 'Pengajuan ' . $type . ' berhasil dikirim ke Admin!';
    if (is_ot_type($type)) $msg .= ' (' . $extra['ot_hours'] . ' jam, perkiraan upah lembur ' . rp((int)$extra['ot_pay']) . ')';
    return ['success' => true, 'message' => $msg];
}

/** Hitung perkiraan upah lembur sebelum diajukan (untuk ditampilkan di form). */
function action_preview_overtime(array $me, array $d): array
{
    $date = parse_date_any($d['date'] ?? null);
    if (!$date) return ['success' => false, 'message' => 'Pilih tanggal lembur.'];
    try {
        [, , $hours, $pay, $calc] = validate_overtime($me, $date, $d['otStart'] ?? null, $d['otEnd'] ?? null);
    } catch (ApiError $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
    return ['success' => true, 'hours' => $hours, 'pay' => $pay, 'dayLabel' => $calc['label'],
        'hourly' => (int)round($calc['hourly']), 'factor' => round($calc['factor'], 2)];
}

/** Jenis pengajuan yang boleh disetujui karyawan dengan hak akses Persetujuan (lembur hanya Admin). */
function can_approve_type(string $type): bool
{
    return !is_ot_type($type);
}

function action_update_request_status(array $me, array $d): array
{
    if ($me['role'] !== 'ADMIN') {
        if (!has_perm($me, PERM_APPROVE)) throw new ApiError('Akses ditolak: Anda tidak memiliki hak akses Persetujuan.', 403);
        $target = q_one('SELECT emp_id, type FROM requests WHERE req_id = ?', [(string)($d['reqId'] ?? '')]);
        if ($target && $target['emp_id'] === $me['emp_id']) {
            return ['success' => false, 'message' => 'Pengajuan milik Anda sendiri harus diproses oleh Admin HR.'];
        }
        if ($target && !can_approve_type($target['type'])) {
            return ['success' => false, 'message' => 'Pengajuan lembur hanya bisa diproses oleh Admin HR.'];
        }
    }
    $status = strtoupper(trim((string)($d['status'] ?? '')));
    if (!in_array($status, ['APPROVED', 'REJECTED', 'DISETUJUI', 'DITOLAK'], true)) {
        return ['success' => false, 'message' => 'Status tidak valid.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = q_one('SELECT * FROM requests WHERE req_id = ? FOR UPDATE', [(string)($d['reqId'] ?? '')]);
        if (!$r) { $pdo->rollBack(); return ['success' => false, 'message' => 'Data pengajuan tidak ditemukan.']; }
        // Cegah saldo cuti terpotong 2x bila tombol ditekan berulang
        if ($r['status'] !== 'PENDING') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Pengajuan ' . $r['req_id'] . ' sudah diproses sebelumnya (' . $r['status'] . ').'];
        }
        $note = trim((string)($d['adminNote'] ?? ''));
        q('UPDATE requests SET status = ?, approved_by = ?, approved_at = NOW(), admin_note = ? WHERE req_id = ?',
            [$status, $me['full_name'], $note === '' ? '-' : $note, $r['req_id']]);

        if (is_approved($status) && is_ot_type($r['type'])) {
            $u = q_one('SELECT * FROM users WHERE emp_id = ?', [$r['emp_id']]);
            $calc = overtime_calc($u ?: [], $r['start_date'], (float)$r['ot_hours']);
            q('UPDATE requests SET ot_pay = ? WHERE req_id = ?', [$calc['pay'], $r['req_id']]);
        } elseif (is_approved($status) && is_correction_type($r['type'])) {
            apply_correction($r);
        } elseif (is_approved($status)) {
            $days = days_between($r['start_date'], $r['end_date']);
            $t = strtolower($r['type']);
            if (str_contains($t, 'cuti')) {
                q('UPDATE users SET leave_balance = GREATEST(0, leave_balance - ?) WHERE emp_id = ?', [$days, $r['emp_id']]);
            } elseif (str_contains($t, 'tabung') || str_contains($t, 'menabung')) {
                q('UPDATE users SET saved_holidays = saved_holidays + ? WHERE emp_id = ?', [$days, $r['emp_id']]);
            } elseif (str_contains($t, 'pengganti')) {
                q('UPDATE users SET saved_holidays = GREATEST(0, saved_holidays - ?) WHERE emp_id = ?', [$days, $r['emp_id']]);
            }
            q('INSERT INTO agendas (agenda_id, date, title, description, category, created_by, created_by_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                next_seq_id('agendas', 'agenda_id', 'AGD'), $r['start_date'], $r['full_name'] . ' - ' . $r['type'],
                'Status Disetujui HR: ' . $r['reason'] . ' (s/d ' . fmt_date($r['end_date']) . ')', 'Cuti Karyawan', 'Sistem HR', $r['emp_id'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    notify_request_decision($r['req_id']);
    if (is_ot_type($r['type']) && is_approved($status)) ensure_payslips($r['emp_id']);
    return ['success' => true, 'message' => 'Status pengajuan ' . $r['req_id'] . ' berhasil diperbarui ke ' . $status];
}

/* ================================================================== */
/* KARYAWAN, SLIP GAJI, PENGATURAN (ADMIN)                              */
/* ================================================================== */

function action_save_employee(array $d, ?array $me = null): array
{
    $isEdit = to_bool($d['isEdit'] ?? false);
    $fullName = trim((string)($d['fullName'] ?? ''));
    $email = strtolower(trim((string)($d['email'] ?? '')));
    $password = trim((string)($d['password'] ?? ''));
    $role = strtoupper(trim((string)($d['role'] ?? 'EMPLOYEE'))) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE';
    if ($fullName === '' || $email === '') return ['success' => false, 'message' => 'Nama dan email wajib diisi.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => 'Format email tidak valid.'];
    if ($password !== '' && strlen($password) < 6) return ['success' => false, 'message' => 'Kata sandi minimal 6 karakter.'];

    $workMode = strtoupper((string)($d['workMode'] ?? '')) === 'REMOTE' ? 'REMOTE' : 'OFFICE';
    $fields = [
        'full_name' => $fullName,
        'email' => $email,
        'role' => $role,
        'position' => trim((string)($d['position'] ?? '')),
        'department' => trim((string)($d['department'] ?? '')),
        'leave_balance' => (int)($d['leaveBalance'] ?? 12),
        'saved_holidays' => (int)($d['savedHolidays'] ?? 0),
        'contract_doc_url' => trim((string)($d['contractUrl'] ?? '')) ?: '-',
        'bpjs_doc_url' => trim((string)($d['bpjsUrl'] ?? '')) ?: '-',
        'basic_salary' => parse_money($d['basicSalary'] ?? 0),
        'allowance' => parse_money($d['allowance'] ?? 0),
        'bpjs_tk_amount' => parse_money($d['bpjsTkAmount'] ?? 0),
        'has_bpjs_kes' => to_bool($d['hasBpjsKes'] ?? false) ? 1 : 0,
        'bpjs_kes_amount' => parse_money($d['bpjsKesAmount'] ?? 0),
        'kasbon_amount' => parse_money($d['kasbonAmount'] ?? 0),
        'encashed_holidays' => (int)($d['encashedHolidays'] ?? 0),
    ];
    if (array_key_exists('workMode', $d)) $fields['work_mode'] = $workMode;
    if (array_key_exists('permissions', $d) && is_array($d['permissions'])) {
        $perms = array_values(array_intersect(ALL_PERMS, array_map(fn($p) => strtoupper(trim((string)$p)), $d['permissions'])));
        $fields['permissions'] = implode(',', $perms);
    }
    if (array_key_exists('bonusExempt', $d)) $fields['bonus_exempt'] = to_bool($d['bonusExempt']) ? 1 : 0;
    if (array_key_exists('isActive', $d)) {
        $active = to_bool($d['isActive']);
        $fields['is_active'] = $active ? 1 : 0;
        // Tanggal nonaktif (resign): absensi setelah tanggal ini tidak dihitung Alpha
        $fields['resign_date'] = $active ? null : (parse_date_any($d['resignDate'] ?? null) ?? date('Y-m-d'));
    }

    if (!$isEdit) {
        if (q_val('SELECT 1 FROM users WHERE LOWER(email) = ?', [$email])) {
            return ['success' => false, 'message' => 'Email sudah terdaftar untuk karyawan lain!'];
        }
        if ($password === '') return ['success' => false, 'message' => 'Kata sandi untuk karyawan baru wajib diisi (minimal 6 karakter).'];
        $empId = next_seq_id('users', 'emp_id', 'EMP');
        $fields['emp_id'] = $empId;
        $fields['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $fields['join_date'] = parse_date_any($d['joinDate'] ?? null) ?? date('Y-m-d');
        $cols = array_keys($fields);
        q('INSERT INTO users (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($fields));
        ensure_payslips($empId);
        return ['success' => true, 'message' => 'Karyawan baru berhasil ditambahkan dengan ID: ' . $empId];
    }

    $empId = (string)($d['empId'] ?? '');
    if (!q_val('SELECT 1 FROM users WHERE emp_id = ?', [$empId])) return ['success' => false, 'message' => 'Data karyawan tidak ditemukan.'];
    if ($me && $empId === $me['emp_id'] && isset($fields['is_active']) && !$fields['is_active']) {
        return ['success' => false, 'message' => 'Anda tidak bisa menonaktifkan akun Anda sendiri.'];
    }
    if (q_val('SELECT 1 FROM users WHERE LOWER(email) = ? AND emp_id <> ?', [$email, $empId])) {
        return ['success' => false, 'message' => 'Email sudah dipakai karyawan lain!'];
    }
    if ($password !== '') $fields['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($fields)));
    q("UPDATE users SET $set WHERE emp_id = ?", array_merge(array_values($fields), [$empId]));
    if ($password !== '' || (isset($fields['is_active']) && !$fields['is_active'])) {
        q('DELETE FROM sessions WHERE emp_id = ?', [$empId]); // paksa login ulang (sandi baru) / keluarkan karyawan nonaktif
    }
    ensure_payslips($empId);
    $msg = 'Data Karyawan ' . $empId . ' berhasil diperbarui.';
    if (isset($fields['is_active']) && !$fields['is_active']) $msg .= ' Status: NONAKTIF sejak ' . fmt_date($fields['resign_date']) . '.';
    return ['success' => true, 'message' => $msg];
}

function action_save_manual_payslip(array $d): array
{
    $u = q_one('SELECT * FROM users WHERE emp_id = ?', [(string)($d['empId'] ?? '')]);
    if (!$u) return ['success' => false, 'message' => 'Karyawan tidak ditemukan.'];
    $period = trim((string)($d['period'] ?? ''));
    if ($period === '') return ['success' => false, 'message' => 'Periode wajib diisi.'];
    $settings = get_settings();

    $basic = parse_money($d['basicSalary'] ?? 0);
    $allow = parse_money($d['allowance'] ?? 0);
    $onTime = is_bonus_exempt($u) ? 0 : parse_money($d['onTimeBonus'] ?? 0);
    $encDays = (int)($d['encashedHolidays'] ?? 0);
    $encPay = $encDays * ENCASHED_HOLIDAY_RATE;
    $lateDed = parse_money($d['lateDeduction'] ?? 0);
    $bpjsTk = parse_money($d['bpjsTkAmount'] ?? 0);
    $hasKes = to_bool($d['hasBpjsKes'] ?? false) ? 1 : 0;
    $bpjsKes = $hasKes ? parse_money($d['bpjsKesAmount'] ?? 0) : 0;
    $kasbon = parse_money($d['kasbonAmount'] ?? 0);
    $ded = $lateDed + $bpjsTk + $bpjsKes + $kasbon;
    $range = period_range($period);
    [$otHours, $otPay] = $range ? overtime_for_month($u['emp_id'], $range[0], $range[1]) : [0, 0];
    $net = ($basic + $allow + $onTime + $encPay + $otPay) - $ded;

    // Slip otomatis pada periode yang sama diganti oleh slip manual (agar tidak dobel)
    q("DELETE FROM payslips WHERE emp_id = ? AND period = ? AND status <> 'TERBIT MANUAL'", [$u['emp_id'], $period]);
    q('INSERT INTO payslips (pay_id, period, emp_id, full_name, position, department, basic_salary, allowance, on_time_bonus,
         encashed_holidays, encashed_holiday_pay, late_count, excused_late_count, late_deduction, bpjs_tk_amount, has_bpjs_kes,
         bpjs_kes_amount, kasbon_amount, deductions, net_salary, payment_date, status, finance_name, leader_name)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        random_id('payslips', 'pay_id', 'PAY'), $period, $u['emp_id'], $u['full_name'], $u['position'], $u['department'],
        $basic, $allow, $onTime, $encDays, $encPay, (int)($d['lateCount'] ?? 0), (int)($d['excusedLateCount'] ?? 0), $lateDed,
        $bpjsTk, $hasKes, $bpjsKes, $kasbon, $ded, $net, (string)($d['paymentDate'] ?? date('d/m/Y')), 'TERBIT MANUAL',
        $settings['FINANCE_NAME'], $settings['LEADER_NAME'],
    ]);
    if ($otPay > 0) {
        q("UPDATE payslips SET overtime_hours = ?, overtime_pay = ? WHERE emp_id = ? AND period = ? AND status = 'TERBIT MANUAL'
           ORDER BY created_at DESC LIMIT 1", [$otHours, $otPay, $u['emp_id'], $period]);
    }
    return ['success' => true, 'message' => 'Slip gaji periode ' . $period . ' berhasil diterbitkan!'
        . ($otPay > 0 ? ' Termasuk upah lembur yang sudah disetujui: ' . rp($otPay) . ' (' . $otHours . ' jam).' : '')];
}

function action_update_settings(array $d): array
{
    [$lat, $lng] = parse_latlng($d['lat'] ?? null, $d['lng'] ?? null);
    $radius = parse_coord($d['radius'] ?? null);
    $start = parse_time_any($d['workStart'] ?? null);
    $end = parse_time_any($d['workEnd'] ?? null);
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) return ['success' => false, 'message' => 'Latitude / Longitude tidak valid. Gunakan titik sebagai pemisah desimal, contoh -6.175392'];
    if ($radius === null || $radius < 5) return ['success' => false, 'message' => 'Radius minimal 5 meter.'];
    if (!$start || !$end) return ['success' => false, 'message' => 'Jam kerja tidak valid.'];

    $vals = [
        'OFFICE_LAT' => (string)$lat, 'OFFICE_LNG' => (string)$lng, 'MAX_RADIUS_METERS' => (string)(int)$radius,
        'WORK_START_TIME' => substr($start, 0, 5), 'WORK_END_TIME' => substr($end, 0, 5),
    ];
    $satEnd = parse_time_any($d['workEndSaturday'] ?? null);
    if ($satEnd) $vals['WORK_END_SATURDAY'] = substr($satEnd, 0, 5);
    foreach ($vals as $k => $v) {
        q('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v]);
    }
    // Hitung ulang status terlambat hari ini jika jam masuk diubah
    $startMin = time_to_minutes($start);
    q("UPDATE attendance SET status = IF(HOUR(checkin_time) * 60 + MINUTE(checkin_time) > ?, 'TERLAMBAT', 'TEPAT WAKTU')
       WHERE date = CURDATE() AND checkin_time IS NOT NULL", [$startMin]);
    return ['success' => true, 'message' => 'Pengaturan kantor & jam kerja berhasil disimpan!'];
}
