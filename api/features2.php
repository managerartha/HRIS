<?php
/**
 * HRIS PT Artha Safety Indonesia - fitur tahap 3:
 *  - Aturan cuti tahunan (reset 12 hari tiap tahun, belum 1 tahun = belum berhak,
 *    maks. 3 hari kerja per pengajuan, diajukan paling lambat H-3)
 *  - Pengumuman dari HR (+ tanda sudah dibaca)
 *  - Log aktivitas admin
 *  - Pengingat "belum absen hari ini"
 */

declare(strict_types=1);

const LEAVE_MAX_PER_REQUEST = 3;   // hari kerja
const LEAVE_MIN_NOTICE_DAYS = 3;   // diajukan paling lambat H-3

/* ================================================================== */
/* CUTI TAHUNAN                                                          */
/* ================================================================== */

/** Pengajuan cuti tahunan (bukan izin sakit / libur pengganti). */
function is_annual_leave_type(string $type): bool
{
    $t = strtolower($type);
    return str_contains($t, 'cuti') && !str_contains($t, 'sakit') && !str_contains($t, 'bersama');
}

/** Jumlah hari kerja (Senin–Sabtu, bukan tanggal merah) di antara dua tanggal. */
function working_days_between(string $start, string $end): int
{
    if ($end < $start) [$start, $end] = [$end, $start];
    $n = 0;
    for ($d = $start; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $k = day_kind($d);
        if ($k === 'WORKDAY' || $k === 'SATURDAY') $n++;
        if ($n > 400) break;
    }
    return $n;
}

/**
 * Reset saldo cuti: dijalankan otomatis maksimal 1x per hari (saat ada yang membuka HRIS).
 * - Awal tahun: karyawan yang sudah berhak -> saldo kembali 12 hari (sisa tahun lalu hangus).
 * - Karyawan yang genap 1 tahun bekerja di tengah tahun -> langsung mendapat 12 hari.
 */
function run_leave_maintenance(): void
{
    $today = date('Y-m-d');
    $last = q_val("SELECT setting_value FROM settings WHERE setting_key = 'LEAVE_CHECK_DATE'");
    if ($last === $today) return;
    q("INSERT INTO settings (setting_key, setting_value, description) VALUES ('LEAVE_CHECK_DATE', ?, 'Pemeriksaan saldo cuti terakhir (otomatis)')
       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$today]);
    $year = (int)date('Y');
    foreach (q_all('SELECT emp_id, full_name, join_date, leave_balance, leave_year, leave_policy FROM users WHERE is_active = 1') as $u) {
        if (!leave_eligible($u, $today)) continue;
        if ($u['leave_year'] === null || (int)$u['leave_year'] < $year) {
            q('UPDATE users SET leave_balance = ?, leave_year = ? WHERE emp_id = ?', [LEAVE_ANNUAL_DAYS, $year, $u['emp_id']]);
            audit_log(null, 'RESET_CUTI', $u['emp_id'], 'Saldo cuti ' . $u['full_name'] . ' diatur ' . (int)$u['leave_balance'] . ' → '
                . LEAVE_ANNUAL_DAYS . ' hari untuk tahun ' . $year . ($u['leave_year'] === null ? ' (genap 1 tahun bekerja).' : ' (reset tahunan).'));
        }
    }
}

/** Validasi pengajuan cuti tahunan. Melempar ApiError jika melanggar aturan. */
function validate_annual_leave(array $me, string $start, string $end): int
{
    $today = date('Y-m-d');
    if (leave_policy($me) === 'NONE') {
        throw new ApiError('Akun Anda tidak mendapat cuti tahunan. Hubungi HR jika ada keperluan.');
    }
    if (!leave_eligible($me, $today)) {
        throw new ApiError('Anda belum berhak cuti tahunan karena masa kerja belum 1 tahun. Hak cuti berlaku mulai '
            . fmt_date(leave_eligible_date($me)) . '. Untuk keperluan mendesak gunakan Izin / Izin Sakit.');
    }
    $minStart = date('Y-m-d', strtotime($today . ' +' . LEAVE_MIN_NOTICE_DAYS . ' days'));
    if ($start < $minStart) {
        throw new ApiError('Cuti harus diajukan paling lambat ' . LEAVE_MIN_NOTICE_DAYS . ' hari sebelum tanggal cuti. '
            . 'Tanggal mulai cuti paling cepat ' . fmt_date($minStart) . '.');
    }
    $days = working_days_between($start, $end);
    if ($days < 1) throw new ApiError('Tanggal yang dipilih jatuh di hari Minggu / tanggal merah, tidak perlu cuti.');
    if ($days > LEAVE_MAX_PER_REQUEST) {
        throw new ApiError('Maksimal cuti ' . LEAVE_MAX_PER_REQUEST . ' hari kerja per pengajuan (yang dipilih: ' . $days . ' hari kerja).');
    }
    $pendingDays = 0;
    foreach (q_all("SELECT type, start_date, end_date FROM requests WHERE emp_id = ? AND status = 'PENDING'", [$me['emp_id']]) as $r) {
        if (is_annual_leave_type($r['type']) && $r['start_date'] && $r['end_date']) $pendingDays += working_days_between($r['start_date'], $r['end_date']);
    }
    $bal = (int)$me['leave_balance'];
    if ($days + $pendingDays > $bal) {
        throw new ApiError('Sisa cuti Anda ' . $bal . ' hari' . ($pendingDays ? ' (' . $pendingDays . ' hari sedang menunggu persetujuan)' : '')
            . ', tidak cukup untuk ' . $days . ' hari kerja.');
    }
    return $days;
}

/* ================================================================== */
/* PENGUMUMAN HR                                                         */
/* ================================================================== */

function list_announcements(array $me): array
{
    $isAdmin = $me['role'] === 'ADMIN';
    $rows = q_all('SELECT a.*, r.read_at FROM announcements a
                   LEFT JOIN announcement_reads r ON r.ann_id = a.ann_id AND r.emp_id = ?
                   WHERE a.is_active = 1 ORDER BY a.created_at DESC, a.ann_id DESC LIMIT 30', [$me['emp_id']]);
    $out = [];
    $activeUsers = $isAdmin ? q_all('SELECT emp_id, full_name FROM users WHERE is_active = 1 ORDER BY full_name') : [];
    foreach ($rows as $a) {
        $o = [
            'ID' => (int)$a['ann_id'],
            'TITLE' => $a['title'],
            'BODY' => (string)$a['body'],
            'CREATED_BY' => $a['created_by'],
            'CREATED_AT' => date('d/m/Y H:i', strtotime($a['created_at'])),
            'IS_READ' => $a['read_at'] !== null || $a['created_by_id'] === $me['emp_id'],
        ];
        if ($isAdmin) {
            $readers = array_column(q_all('SELECT emp_id FROM announcement_reads WHERE ann_id = ?', [$a['ann_id']]), 'emp_id');
            $unread = array_values(array_filter($activeUsers, fn($u) => !in_array($u['emp_id'], $readers, true) && $u['emp_id'] !== $a['created_by_id']));
            $o['READ_COUNT'] = count($activeUsers) - count($unread);
            $o['TOTAL'] = count($activeUsers);
            $o['UNREAD_NAMES'] = array_column($unread, 'full_name');
        }
        $out[] = $o;
    }
    return $out;
}

function action_create_announcement(array $me, array $d): array
{
    $title = trim((string)($d['title'] ?? ''));
    $body = trim((string)($d['body'] ?? ''));
    if ($title === '' || $body === '') return ['success' => false, 'message' => 'Judul dan isi pengumuman wajib diisi.'];
    q('INSERT INTO announcements (title, body, created_by, created_by_id) VALUES (?, ?, ?, ?)',
        [mb_substr($title, 0, 200), $body, $me['full_name'], $me['emp_id']]);
    audit_log($me, 'PENGUMUMAN', $title, 'Membuat pengumuman: ' . mb_substr($body, 0, 300));
    $msg = 'Pengumuman diterbitkan. Karyawan akan melihatnya saat membuka HRIS.';
    if (to_bool($d['sendEmail'] ?? false)) {
        $emails = array_values(array_filter(array_column(q_all('SELECT email FROM users WHERE is_active = 1'), 'email'), 'valid_mail'));
        if (!mail_settings()['enabled']) {
            $msg .= ' (Email tidak dikirim: notifikasi email belum diaktifkan di Pengaturan.)';
        } elseif ($emails) {
            // dikirim satu per satu agar alamat email karyawan tidak saling terlihat
            foreach ($emails as $e) {
                queue_mail([$e], 'Pengumuman HR: ' . $title, mail_layout($title, nl2br(htmlspecialchars($body))
                    . '<p style="font-size:12px;color:#64748b">— ' . htmlspecialchars($me['full_name']) . '</p>'));
            }
            $msg .= ' Email dikirim ke ' . count($emails) . ' karyawan.';
        }
    }
    return ['success' => true, 'message' => $msg];
}

function action_delete_announcement(array $me, array $d): array
{
    $a = q_one('SELECT * FROM announcements WHERE ann_id = ?', [(int)($d['id'] ?? 0)]);
    if (!$a) return ['success' => false, 'message' => 'Pengumuman tidak ditemukan.'];
    q('UPDATE announcements SET is_active = 0 WHERE ann_id = ?', [$a['ann_id']]);
    audit_log($me, 'PENGUMUMAN', $a['title'], 'Menghapus pengumuman.');
    return ['success' => true, 'message' => 'Pengumuman dihapus.'];
}

function action_mark_announcement_read(array $me, array $d): array
{
    $ids = array_filter(array_map('intval', (array)($d['ids'] ?? [$d['id'] ?? 0])));
    foreach ($ids as $id) {
        if (q_val('SELECT 1 FROM announcements WHERE ann_id = ?', [$id])) {
            q('INSERT IGNORE INTO announcement_reads (ann_id, emp_id) VALUES (?, ?)', [$id, $me['emp_id']]);
        }
    }
    return ['success' => true];
}

/* ================================================================== */
/* LOG AKTIVITAS                                                         */
/* ================================================================== */

function action_get_audit_log(array $d): array
{
    $qtext = trim((string)($d['q'] ?? ''));
    $sql = 'SELECT * FROM audit_log';
    $params = [];
    if ($qtext !== '') {
        $sql .= ' WHERE actor_name LIKE ? OR action LIKE ? OR target LIKE ? OR detail LIKE ?';
        $like = '%' . $qtext . '%';
        $params = [$like, $like, $like, $like];
    }
    $sql .= ' ORDER BY id DESC LIMIT 300';
    return ['success' => true, 'logs' => array_map(fn($l) => [
        'AT' => date('d/m/Y H:i', strtotime($l['created_at'])),
        'ACTOR' => $l['actor_name'],
        'ACTOR_ID' => $l['actor_id'],
        'ACTION' => $l['action'],
        'TARGET' => $l['target'],
        'DETAIL' => (string)$l['detail'],
    ], q_all($sql, $params))];
}

/** Ringkas perubahan data karyawan untuk log (password tidak pernah dicatat). */
function employee_diff(array $old, array $new): string
{
    $labels = [
        'full_name' => 'Nama', 'email' => 'Email', 'role' => 'Role', 'permissions' => 'Hak akses', 'position' => 'Jabatan',
        'department' => 'Departemen', 'leave_balance' => 'Sisa cuti', 'saved_holidays' => 'Tabungan libur',
        'basic_salary' => 'Gaji pokok', 'allowance' => 'Tunjangan', 'bpjs_tk_amount' => 'BPJS TK', 'has_bpjs_kes' => 'BPJS Kes aktif',
        'bpjs_kes_amount' => 'BPJS Kes', 'kasbon_amount' => 'Kasbon', 'encashed_holidays' => 'Libur diuangkan',
        'work_mode' => 'Mode kerja', 'bonus_exempt' => 'Bebas bonus', 'leave_policy' => 'Hak cuti', 'join_date' => 'Tgl bergabung', 'is_active' => 'Aktif', 'resign_date' => 'Tgl resign',
        'contract_doc_url' => 'Link kontrak', 'bpjs_doc_url' => 'Link BPJS',
    ];
    $money = ['basic_salary', 'allowance', 'bpjs_tk_amount', 'bpjs_kes_amount', 'kasbon_amount'];
    $parts = [];
    foreach ($labels as $k => $label) {
        if (!array_key_exists($k, $new)) continue;
        $a = (string)($old[$k] ?? '');
        $b = (string)($new[$k] ?? '');
        if ($a === $b) continue;
        if (in_array($k, $money, true)) { $a = rp((int)$a); $b = rp((int)$b); }
        $parts[] = "$label: " . ($a === '' ? '-' : $a) . ' → ' . ($b === '' ? '-' : $b);
    }
    if (array_key_exists('password_hash', $new)) $parts[] = 'Password diubah';
    return $parts ? implode('; ', $parts) : 'Tidak ada perubahan data.';
}

/* ================================================================== */
/* PENGINGAT BELUM ABSEN                                                 */
/* ================================================================== */

function action_remind_absent(array $me, array $d): array
{
    $cfg = mail_settings();
    if ($cfg['user'] === '' || $cfg['pass'] === '') return ['success' => false, 'message' => 'Atur dulu akun email pengirim di Pengaturan Kantor → Notifikasi Email.'];
    $ids = array_values(array_unique(array_map('strval', (array)($d['empIds'] ?? []))));
    if (!$ids) return ['success' => false, 'message' => 'Tidak ada karyawan yang dipilih.'];
    $today = date('Y-m-d');
    $sent = []; $skip = [];
    foreach ($ids as $id) {
        $u = q_one('SELECT * FROM users WHERE emp_id = ? AND is_active = 1', [$id]);
        if (!$u) continue;
        if (q_val('SELECT 1 FROM attendance WHERE emp_id = ? AND date = ?', [$id, $today])) { $skip[] = $u['full_name'] . ' (sudah absen)'; continue; }
        if (!valid_mail((string)$u['email'])) { $skip[] = $u['full_name'] . ' (email belum valid)'; continue; }
        try {
            smtp_send([$u['email']], 'Pengingat: Anda belum absen hari ini', mail_layout('Pengingat absensi',
                '<p>Halo ' . htmlspecialchars($u['full_name']) . ', sampai pukul ' . date('H:i') . ' WIB Anda <b>belum tercatat absen masuk</b> hari ini ('
                . fmt_date($today) . ').</p><p>Silakan absen melalui HRIS. Jika lupa absen saat tiba, ajukan <b>Koreksi Absen</b> hari ini juga. '
                . 'Jika berhalangan hadir, ajukan izin melalui menu Pengajuan.</p>'), $cfg);
            $sent[] = $u['full_name'];
        } catch (Throwable $e) {
            $skip[] = $u['full_name'] . ' (gagal: ' . $e->getMessage() . ')';
            if (str_contains($e->getMessage(), 'Login email gagal') || str_contains($e->getMessage(), 'Tidak bisa terhubung')) break;
        }
    }
    if ($sent) audit_log($me, 'PENGINGAT_ABSEN', count($sent) . ' karyawan', 'Mengirim pengingat belum absen ke: ' . implode(', ', $sent));
    $msg = count($sent) . ' pengingat terkirim.';
    if ($skip) $msg .= ' Dilewati: ' . implode(', ', $skip) . '.';
    return ['success' => count($sent) > 0, 'message' => $msg];
}

/* ================================================================== */
/* PENCATATAN LOG UNTUK AKSI ADMIN / SUPERVISOR                          */
/* ================================================================== */

function audit_action(array $me, string $action, array $d, array $res): void
{
    switch ($action) {
        case 'updateRequestStatus':
            $r = q_one('SELECT * FROM requests WHERE req_id = ?', [(string)($d['reqId'] ?? '')]);
            if ($r) audit_log($me, 'PERSETUJUAN', $r['req_id'] . ' ' . $r['full_name'], $r['status'] . ' — ' . $r['type'] . ' '
                . fmt_date($r['start_date']) . ($r['end_date'] !== $r['start_date'] ? ' s/d ' . fmt_date($r['end_date']) : '')
                . (trim((string)$r['admin_note']) !== '' && $r['admin_note'] !== '-' ? '. Catatan: ' . $r['admin_note'] : ''));
            break;
        case 'updateOfficeSettings':
            audit_log($me, 'PENGATURAN', 'Lokasi & jam kerja', 'Lokasi ' . ($d['lat'] ?? '') . ', ' . ($d['lng'] ?? '') . ' radius ' . ($d['radius'] ?? '')
                . ' m; jam ' . ($d['workStart'] ?? '') . '–' . ($d['workEnd'] ?? '') . ', Sabtu s/d ' . ($d['workEndSaturday'] ?? '-'));
            break;
        case 'addDutyAssignment':
        case 'updateDutyAssignment':
            audit_log($me, $action === 'addDutyAssignment' ? 'DINAS_TAMBAH' : 'DINAS_UBAH', (string)($d['empId'] ?? ''),
                ($d['locationName'] ?? '') . ' (' . ($d['lat'] ?? '') . ', ' . ($d['lng'] ?? '') . ', radius ' . ($d['radius'] ?? '') . ' m) '
                . ($d['startDate'] ?? '') . ' s/d ' . ($d['endDate'] ?? ''));
            break;
        case 'deleteDutyAssignment':
            audit_log($me, 'DINAS_HAPUS', (string)($d['dutyId'] ?? ''), 'Menghapus tugas dinas.');
            break;
        case 'addHoliday':
            audit_log($me, 'LIBUR_TAMBAH', (string)($d['date'] ?? ''), ($d['name'] ?? '') . ' (' . ($d['type'] ?? '') . ')');
            break;
        case 'deleteHoliday':
            audit_log($me, 'LIBUR_HAPUS', (string)($d['date'] ?? ''), 'Menghapus hari libur.');
            break;
        case 'saveManualPayslip':
            audit_log($me, 'SLIP_MANUAL', ($d['empId'] ?? '') . ' ' . ($d['period'] ?? ''), 'Menerbitkan slip manual: gaji pokok ' . rp(parse_money($d['basicSalary'] ?? 0))
                . ', tunjangan ' . rp(parse_money($d['allowance'] ?? 0)) . ', kasbon ' . rp(parse_money($d['kasbonAmount'] ?? 0)));
            break;
        case 'saveEmailSettings':
            audit_log($me, 'PENGATURAN', 'Notifikasi email', 'Pengaturan email diubah (pengirim: ' . ($d['user'] ?? '') . ', aktif: '
                . (to_bool($d['enabled'] ?? false) ? 'ya' : 'tidak') . (($d['password'] ?? '') !== '' ? ', password diganti' : '') . ').');
            break;
        case 'sendPayslipEmails':
            audit_log($me, 'SLIP_EMAIL', (string)($d['period'] ?? ''), (string)($res['message'] ?? ''));
            break;
    }
}
