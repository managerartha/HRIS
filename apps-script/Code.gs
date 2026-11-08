/**
 * HRIS PT Artha Safety Indonesia - Backend Google Apps Script (VERSI SEMENTARA / DIPERBAIKI)
 * ============================================================================================
 * Dipakai selama website masih di GitHub Pages. Setelah pindah ke Hostinger, backend ini
 * digantikan oleh folder api/ (PHP + MySQL).
 *
 * PERBAIKAN DI VERSI INI:
 *  1. Riana (EMP-0003) dihapus dari daftar karyawan remote / bebas bonus (sudah kerja di kantor lagi).
 *  2. Password tidak lagi ikut terkirim ke browser saat login / dashboard admin.
 *  3. getDashboardData tidak lagi "jatuh" ke data user pertama (Admin) bila EMP_ID tidak ditemukan.
 *  4. Penugasan dinas (DUTIES) dibaca dari nilai asli sel (bukan teks tampilan), sehingga tanggal &
 *     koordinat tidak salah baca karena format locale spreadsheet (mis. "-6,2" atau tanggal M/d/yyyy).
 *  5. Koordinat kantor dengan koma desimal ("-6,175392") sekarang terbaca benar.
 *  6. Hitungan terlambat di slip gaji hanya bulan & tahun berjalan (dulu tanggal 11 bulan lain ikut terhitung di November).
 *  7. Pengajuan yang sudah disetujui tidak bisa diproses 2x (saldo cuti tidak terpotong dobel).
 *  8. Fungsi exportForHostinger() untuk memindahkan SELURUH data lama ke Hostinger (tanpa mengubah spreadsheet).
 *
 * CARA PASANG: salin seluruh isi file ini ke Code.gs di Apps Script Editor -> Simpan ->
 * Deploy -> Manage deployments -> Edit (ikon pensil) -> Version: New version -> Deploy.
 * (Pakai "Manage deployments" agar URL Web App tidak berubah.)
 */

/**
 * FUNGSI OTORISASI CEPAT (Jalankan fungsi ini 1x di Apps Script Editor):
 * Klik 'Pilih fungsi' -> pilih 'authorizeApp' -> klik 'Jalankan' (Run)
 * Lalu berikan izin akses (Review Permissions -> Lanjutan -> Buka -> Izinkan).
 */
function authorizeApp() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const folder = DriveApp.getRootFolder();
  Logger.log("✅ Otorisasi Google Sheets & Google Drive Berhasil untuk: " + ss.getName() + " | Folder: " + folder.getName());
  return "Otorisasi Berhasil!";
}

/**
 * EXPORT DATA UNTUK PINDAH KE HOSTINGER (SEMUA DATA, TIDAK ADA YANG DIHAPUS DARI SPREADSHEET)
 * ------------------------------------------------------------------------------------------
 * Jalankan dari Apps Script Editor: pilih fungsi 'exportForHostinger' -> Jalankan (Run).
 * Hasil: folder "HRIS-export-<tanggal>" di Google Drive berisi 1 file atau lebih:
 *   HRIS-export-...-part1.json  (semua data + absensi bagian 1)
 *   HRIS-export-...-part2.json  (absensi bagian 2, dst. — hanya jika foto absensi banyak)
 * Download SEMUA file di folder itu, lalu upload sekaligus di https://domainanda.com/setup/ (langkah 2).
 *
 * - Tanggal absensi/pengajuan/slip dibaca sebagai tanggal/bulan/tahun (format yang ditulis sistem lama),
 *   tanggal dari kalender (mulai/selesai cuti, dinas, agenda) memakai nilai asli sel, dan angka memakai
 *   nilai asli sel ("Rp 5.000.000" / "-6,17" tidak salah baca).
 * - Setiap file mencatat jumlah baris asli per sheet, sehingga halaman import bisa memastikan
 *   semua baris masuk (dan menampilkan baris mana saja yang tidak bisa diimport beserta alasannya).
 * - Spreadsheet TIDAK diubah sama sekali.
 */
function exportForHostinger() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const tz = ss.getSpreadsheetTimeZone() || 'Asia/Jakarta';
  const stamp = Utilities.formatDate(new Date(), 'Asia/Jakarta', 'yyyyMMdd-HHmm');
  const folder = DriveApp.getRootFolder().createFolder('HRIS-export-' + stamp);
  const names = ['SETTINGS', 'USERS', 'REQUESTS', 'PAYSLIPS', 'AGENDAS', 'DUTIES', 'ATTENDANCE'];

  const all = {};
  const counts = {};
  names.forEach(n => {
    all[n] = exportSheetRows(n, tz);
    counts[n] = all[n].length;
    Logger.log(n + ': ' + counts[n] + ' baris');
  });

  // Absensi (berisi foto) dipecah per ~15 MB agar file tidak terlalu besar untuk diupload.
  const MAX_CHARS = 15 * 1024 * 1024;
  const attChunks = [];
  let cur = [], size = 0;
  all.ATTENDANCE.forEach(r => {
    const len = JSON.stringify(r).length;
    if (cur.length && size + len > MAX_CHARS) { attChunks.push(cur); cur = []; size = 0; }
    cur.push(r); size += len;
  });
  attChunks.push(cur);

  const totalParts = attChunks.length;
  const exportId = stamp + '-' + Math.floor(Math.random() * 1e6);
  const urls = [];
  attChunks.forEach((chunk, idx) => {
    const sheets = { ATTENDANCE: chunk };
    if (idx === 0) names.forEach(n => { if (n !== 'ATTENDANCE') sheets[n] = all[n]; });
    const out = {
      exportId: exportId,
      exportedAt: Utilities.formatDate(new Date(), 'Asia/Jakarta', 'dd/MM/yyyy HH:mm:ss'),
      spreadsheet: ss.getName(),
      part: idx + 1,
      totalParts: totalParts,
      counts: counts,
      sheets: sheets
    };
    const file = folder.createFile('HRIS-export-' + stamp + '-part' + (idx + 1) + '.json', JSON.stringify(out), 'application/json');
    urls.push(file.getUrl());
  });

  Logger.log('✅ Export selesai: ' + totalParts + ' file di folder Google Drive "' + folder.getName() + '"');
  Logger.log('   Buka folder: ' + folder.getUrl());
  Logger.log('   Jumlah baris: ' + JSON.stringify(counts));
  Logger.log('⚠️ File ini berisi password karyawan. Hapus folder tersebut setelah import ke Hostinger berhasil.');
  return folder.getUrl();
}

/** Baca satu sheet sebagai array objek, memakai nilai asli sel untuk tanggal/jam/angka. */
function exportSheetRows(sheetName, tz) {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(sheetName);
  if (!sheet) return [];
  const range = sheet.getDataRange();
  const values = range.getValues();
  const display = range.getDisplayValues();
  if (values.length <= 1) return [];

  const headers = display[0].map(h => String(h).trim());
  const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
  // Kolom yang isinya angka murni -> pakai angka asli (hindari "Rp 5.000.000" / "-6,17")
  const numericCols = ['LATITUDE', 'LONGITUDE', 'DISTANCE_METERS', 'RADIUS_METERS', 'LEAVE_BALANCE', 'SAVED_HOLIDAYS',
    'BASIC_SALARY', 'ALLOWANCE', 'BPJS_TK_AMOUNT', 'BPJS_KES_AMOUNT', 'KASBON_AMOUNT', 'ENCASHED_HOLIDAYS',
    'ON_TIME_BONUS', 'ENCASHED_HOLIDAY_PAY', 'LATE_COUNT', 'EXCUSED_LATE_COUNT', 'LATE_DEDUCTION', 'DEDUCTIONS', 'NET_SALARY'];

  // Kolom tanggal yang diisi dari input kalender (yyyy-mm-dd) -> aman memakai nilai asli sel.
  const isoColsBySheet = {
    REQUESTS: ['START_DATE', 'END_DATE'],
    AGENDAS: ['DATE'],
    DUTIES: ['START_DATE', 'END_DATE']
  };
  const isoCols = isoColsBySheet[sheetName] || [];

  const rows = [];
  for (let i = 1; i < values.length; i++) {
    // Lewati baris yang benar-benar kosong
    if (display[i].every(c => String(c).trim() === '')) continue;
    const obj = { _ROW: i + 1 };
    for (let j = 0; j < headers.length; j++) {
      const h = headers[j];
      if (!h) continue;
      const raw = values[i][j];
      let v;
      if (raw instanceof Date) {
        if (h === 'PERIOD') {
          v = monthNames[Number(Utilities.formatDate(raw, tz, 'M')) - 1] + ' ' + Utilities.formatDate(raw, tz, 'yyyy');
        } else if (isoCols.indexOf(h) !== -1) {
          // Kolom ini diisi dari kalender (format yyyy-mm-dd, tidak ambigu) -> nilai asli sel sudah benar.
          v = Utilities.formatDate(raw, tz, 'yyyy-MM-dd');
        } else {
          // Kolom lain (DATE absensi, SUBMIT_DATE, JOIN_DATE, CREATED_AT, jam, dll.) ditulis sistem lama
          // sebagai teks "dd/MM/yyyy". Jika locale spreadsheet bulan/tanggal (Amerika), Sheets salah
          // mengubahnya menjadi tanggal terbalik (12/10 -> 10 Desember). Teks tampilan tetap sama dengan
          // yang ditulis sistem lama, jadi yang dipakai adalah teks tampilan (dibaca tanggal/bulan/tahun).
          v = display[i][j];
        }
      } else if (typeof raw === 'number' && numericCols.indexOf(h) !== -1) {
        v = raw;
      } else if (sheetName === 'SETTINGS' && h === 'VALUE' && typeof raw === 'number') {
        v = String(raw);
      } else {
        v = display[i][j];
      }
      obj[h] = v;
    }
    rows.push(obj);
  }
  return rows;
}

function doGet(e) {
  return ContentService.createTextOutput(JSON.stringify({
    success: true,
    message: "HRIS PT Artha Safety Indonesia Backend Active."
  })).setMimeType(ContentService.MimeType.JSON);
}

function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return ContentService.createTextOutput(JSON.stringify({ success: false, message: 'Data request POST kosong atau tidak valid.' }))
        .setMimeType(ContentService.MimeType.JSON);
    }

    const data = JSON.parse(e.postData.contents);
    const action = data.action;
    let res = { success: false, message: 'Action not found.' };

    if (action === 'login') {
      res = handleLogin(data.email, data.password);
    } else if (action === 'logout') {
      res = { success: true, message: 'Berhasil keluar.' };
    } else if (action === 'getDashboardData') {
      res = getDashboardData(data.empId);
    } else if (action === 'submitAttendance') {
      res = submitAttendance(data);
    } else if (action === 'updateProfilePhoto') {
      res = updateProfilePhoto(data.empId, data.photoBase64);
    } else if (action === 'addAgenda') {
      res = addAgenda(data);
    } else if (action === 'deleteAgenda') {
      res = deleteAgenda(data.agendaId, data.empId, data.role);
    } else if (action === 'addDutyAssignment') {
      res = addDutyAssignment(data);
    } else if (action === 'deleteDutyAssignment') {
      res = deleteDutyAssignment(data.dutyId);
    } else if (action === 'submitRequest') {
      res = submitRequest(data);
    } else if (action === 'updateRequestStatus') {
      res = updateRequestStatus(data.reqId, data.status, data.adminNote, data.adminName);
    } else if (action === 'saveEmployee') {
      res = saveEmployee(data);
    } else if (action === 'saveManualPayslip') {
      res = saveManualPayslip(data);
    } else if (action === 'updateOfficeSettings') {
      res = updateOfficeSettings(data);
    }

    return ContentService.createTextOutput(JSON.stringify(res))
      .setMimeType(ContentService.MimeType.JSON);
  } catch (err) {
    return ContentService.createTextOutput(JSON.stringify({ success: false, message: err.message }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

function getSheet(sheetName) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sheet = ss.getSheetByName(sheetName);
  if (!sheet) {
    sheet = ss.insertSheet(sheetName);
  }
  return sheet;
}

function getBatchData(sheetName) {
  const sheet = getSheet(sheetName);
  const rows = sheet.getDataRange().getDisplayValues();
  if (rows.length <= 1) return [];
  const headers = rows[0];
  const data = [];
  for (let i = 1; i < rows.length; i++) {
    const obj = {};
    for (let j = 0; j < headers.length; j++) {
      obj[headers[j]] = rows[i][j];
    }
    data.push(obj);
  }
  return data;
}

/** Hapus kolom rahasia sebelum data user dikirim ke browser. */
function sanitizeUser(u) {
  if (!u) return u;
  const copy = {};
  Object.keys(u).forEach(k => { if (k !== 'PASSWORD') copy[k] = u[k]; });
  return copy;
}

/** Koordinat: terima "-6.175392" maupun format Indonesia "-6,175392". */
function parseCoord(v, fallback) {
  if (v === null || v === undefined || v === '') return fallback;
  if (typeof v === 'number') return v;
  let s = String(v).trim();
  if (s.indexOf(',') !== -1 && s.indexOf('.') === -1) s = s.replace(',', '.');
  const n = parseFloat(s);
  return isNaN(n) ? fallback : n;
}

/**
 * Baca sheet DUTIES memakai nilai asli sel (Date & Number), lalu ubah ke format standar
 * (tanggal dd/MM/yyyy, koordinat dengan titik). Mencegah salah baca karena locale spreadsheet.
 */
function getDutiesRaw() {
  const sheet = getSheet('DUTIES');
  const range = sheet.getDataRange();
  const values = range.getValues();
  const display = range.getDisplayValues();
  if (values.length <= 1) return [];
  const headers = display[0];
  const list = [];
  for (let i = 1; i < values.length; i++) {
    const obj = {};
    for (let j = 0; j < headers.length; j++) {
      const h = headers[j];
      const raw = values[i][j];
      if ((h === 'START_DATE' || h === 'END_DATE') && raw instanceof Date) {
        obj[h] = Utilities.formatDate(raw, 'Asia/Jakarta', 'dd/MM/yyyy');
      } else if ((h === 'LATITUDE' || h === 'LONGITUDE' || h === 'RADIUS_METERS')) {
        obj[h] = String(parseCoord(raw, ''));
      } else {
        obj[h] = display[i][j];
      }
    }
    obj.EMP_ID = String(obj.EMP_ID || '').trim();
    list.push(obj);
  }
  return list;
}

function findActiveDuty(duties, empId, todayStr) {
  const id = String(empId || '').trim().toUpperCase();
  const matches = duties.filter(d => String(d.EMP_ID || '').trim().toUpperCase() === id && isDateBetween(todayStr, d.START_DATE, d.END_DATE));
  return matches.length ? matches[matches.length - 1] : null; // penugasan terbaru
}

function handleLogin(emailOrEmpId, password) {
  try {
    const users = getBatchData('USERS');
    const inputIdentifier = String(emailOrEmpId || '').trim().toLowerCase();
    const inputPassword = String(password || '').trim();

    if (!inputIdentifier || !inputPassword) {
      return { success: false, message: 'Email / ID Karyawan dan kata sandi wajib diisi.' };
    }

    const user = users.find(u => {
      const uEmail = String(u.EMAIL || '').trim().toLowerCase();
      const uId = String(u.EMP_ID || '').trim().toLowerCase();
      const uPass = String(u.PASSWORD || '').trim();
      return (uEmail === inputIdentifier || uId === inputIdentifier) && uPass === inputPassword;
    });

    if (!user) {
      return { success: false, message: 'Email / ID Karyawan atau kata sandi tidak sesuai!' };
    }

    user.EMP_ID = String(user.EMP_ID || '').trim();
    return { success: true, user: sanitizeUser(user) };
  } catch (err) {
    return { success: false, message: 'Gagal autentikasi: ' + err.message };
  }
}

function parseTimeToMinutes(t) {
  if (!t) return 0;
  if (t instanceof Date) {
    return t.getHours() * 60 + t.getMinutes();
  }
  const str = String(t).trim().toLowerCase();
  const isPM = str.includes('pm');
  const isAM = str.includes('am');
  const clean = str.replace(/am|pm/g, '').trim();
  const parts = clean.split(/[:.]/);
  if (parts.length >= 2) {
    let h = parseInt(parts[0], 10) || 0;
    let m = parseInt(parts[1], 10) || 0;
    if (isPM && h < 12) h += 12;
    if (isAM && h === 12) h = 0;
    return h * 60 + m;
  }
  return 0;
}

function parseDateToMidnight(s) {
  if (!s) return null;
  if (s instanceof Date) {
    const d = new Date(s.getTime());
    d.setHours(0, 0, 0, 0);
    return d.getTime();
  }
  const str = String(s).trim();
  const parts = str.split(/[\/\-]/);
  let d = null;
  if (parts.length === 3) {
    if (parts[0].length === 4) {
      d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    } else {
      d = new Date(parseInt(parts[2], 10), parseInt(parts[1], 10) - 1, parseInt(parts[0], 10));
    }
  } else {
    d = new Date(str);
  }
  if (!d || isNaN(d.getTime())) return null;
  d.setHours(0, 0, 0, 0);
  return d.getTime();
}

function isDateBetween(targetStr, startStr, endStr) {
  try {
    const t = parseDateToMidnight(targetStr);
    const s = parseDateToMidnight(startStr);
    const e = parseDateToMidnight(endStr);
    if (!t || !s || !e) return false;
    return t >= s && t <= e;
  } catch (err) {
    return false;
  }
}

/** true jika tanggal (dd/MM/yyyy dsb.) berada di bulan & tahun yang diminta. */
function isInMonth(dateStr, monthIndex, year) {
  const ts = parseDateToMidnight(dateStr);
  if (!ts) return false;
  const d = new Date(ts);
  return d.getMonth() === monthIndex && d.getFullYear() === year;
}

function calculateDaysBetween(startDateStr, endDateStr) {
  try {
    const parse = (str) => {
      if (!str) return null;
      str = String(str).trim();
      let d = null;
      if (str.includes('/')) {
        const p = str.split('/');
        if (p.length === 3) {
          if (p[0].length === 4) d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
          else d = new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
        }
      } else if (str.includes('-')) {
        const p = str.split('-');
        if (p.length === 3) {
          if (p[0].length === 4) d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
          else d = new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
        }
      } else {
        d = new Date(str);
      }
      return d;
    };

    const d1 = parse(startDateStr);
    const d2 = parse(endDateStr);
    if (!d1 || !d2 || isNaN(d1.getTime()) || isNaN(d2.getTime())) return 1;

    d1.setHours(0, 0, 0, 0);
    d2.setHours(0, 0, 0, 0);
    const diffTime = Math.abs(d2.getTime() - d1.getTime());
    const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24)) + 1;
    return Math.max(1, diffDays);
  } catch (e) {
    return 1;
  }
}

function isBonusExempt(u) {
  if (!u) return false;
  const empId = String(u.EMP_ID || '').trim().toUpperCase();
  const name = String(u.FULL_NAME || '').toLowerCase();
  const pos = String(u.POSITION || '').toLowerCase();
  const role = String(u.ROLE || '').toLowerCase();
  const dept = String(u.DEPARTMENT || '').toLowerCase();

  // Bebas bonus: Erna (EMP-0013), Ricardo (EMP-0012), Irfan (EMP-0008).
  // Riana (EMP-0003) DIHAPUS dari daftar karena sudah kembali bekerja di kantor.
  if (empId === 'EMP-0012' || empId === 'EMP-0013' || empId === 'EMP-0008') return true;
  if (name.includes('ricardo') || name.includes('simbolon')) return true;
  if ((/\berna\b/i).test(name)) return true;
  if ((/\birfan\b/i).test(name)) return true;
  if (pos.includes('direktur') || pos.includes('director') || pos.includes('komisaris') || pos.includes('direksi') || pos.includes('pimpinan')) return true;
  if (role.includes('director') || role.includes('direktur')) return true;
  if (pos.includes('remote') || pos.includes('wfh') || dept.includes('remote') || dept.includes('wfh')) return true;
  return false;
}

function isUserDireksi(u) {
  return isBonusExempt(u) && !isUserRemote(u);
}

function isUserRemote(u) {
  if (!u) return false;
  const name = String(u.FULL_NAME || '').toLowerCase();
  const pos = String(u.POSITION || '').toLowerCase();
  const dept = String(u.DEPARTMENT || '').toLowerCase();
  const empId = String(u.EMP_ID || '').toUpperCase();
  const isIrfan = (/\birfan\b/i).test(name) || empId === 'EMP-0008';
  const isRemoteJob = pos.includes('remote') || pos.includes('wfh') || dept.includes('remote') || dept.includes('wfh');
  return isIrfan || isRemoteJob;
}

function ensurePayslipsData(empId, role, preloaded) {
  try {
    const payslipSheet = getSheet('PAYSLIPS');
    const payDataRange = payslipSheet.getDataRange();
    const payRows = payDataRange.getDisplayValues();
    if (payRows.length === 0) return;

    let updatedPayValues = null;
    let hasUpdates = false;

    for (let r = 1; r < payRows.length; r++) {
      const pEmpId = String(payRows[r][2] || '').trim().toUpperCase();
      const pName = String(payRows[r][3] || '').trim();
      const pPos = String(payRows[r][4] || '').trim();
      const pDept = String(payRows[r][5] || '').trim();
      if (isBonusExempt({ EMP_ID: pEmpId, FULL_NAME: pName, POSITION: pPos, DEPARTMENT: pDept })) {
        const curBonus = parseFloat(payRows[r][8] || 0);
        if (curBonus > 0) {
          if (!updatedPayValues) updatedPayValues = payDataRange.getValues();
          const basic = parseFloat(payRows[r][6] || 0);
          const allow = parseFloat(payRows[r][7] || 0);
          const encashedPay = parseFloat(payRows[r][10] || 0);
          const deductions = parseFloat(payRows[r][18] || 0);
          updatedPayValues[r][8] = 0; // ON_TIME_BONUS disetel ke 0 permanen
          updatedPayValues[r][19] = (basic + allow + 0 + encashedPay) - deductions; // THP
          hasUpdates = true;
        }
      }
    }

    const users = (preloaded && preloaded.users) ? preloaded.users : getBatchData('USERS');
    const attendance = (preloaded && preloaded.attendance) ? preloaded.attendance : getBatchData('ATTENDANCE');
    const requests = (preloaded && preloaded.requests) ? preloaded.requests : getBatchData('REQUESTS');

    const now = new Date();
    const currentMonthIndex = now.getMonth();
    const currentYear = now.getFullYear();
    const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    const currentPeriod = monthNames[currentMonthIndex] + ' ' + currentYear;

    const payslipsMap = {};
    for (let i = 1; i < payRows.length; i++) {
      const pEmpId = String(payRows[i][2] || '').trim().toUpperCase();
      const pPeriod = String(payRows[i][1] || '').trim();
      payslipsMap[pEmpId + '_' + pPeriod] = { rowIndex: i, rowData: payRows[i] };
    }

    const targetUsers = (role === 'ADMIN') ? users : users.filter(u => String(u.EMP_ID || '').trim().toUpperCase() === String(empId || '').trim().toUpperCase());

    const newRowsToAppend = [];

    targetUsers.forEach(u => {
      const uId = String(u.EMP_ID || '').trim().toUpperCase();
      const existing = payslipsMap[uId + '_' + currentPeriod];

      // PERBAIKAN: hanya absensi di bulan & tahun berjalan
      const uAtt = attendance.filter(a => {
        if (String(a.EMP_ID || '').trim().toUpperCase() !== uId) return false;
        if (!a.DATE) return false;
        return isInMonth(a.DATE, currentMonthIndex, currentYear);
      });

      const approvedLateReqs = requests.filter(r =>
        String(r.EMP_ID || '').trim().toUpperCase() === uId &&
        String(r.TYPE || '').trim().toLowerCase().includes('terlambat') &&
        (String(r.STATUS || '').toUpperCase() === 'APPROVED' || String(r.STATUS || '').toUpperCase() === 'DISETUJUI')
      );

      let lateCount = 0;
      let excusedLateCount = 0;

      uAtt.forEach(att => {
        if (String(att.STATUS || '').toUpperCase() === 'TERLAMBAT') {
          const hasApproved = approvedLateReqs.some(req => isDateBetween(att.DATE, req.START_DATE, req.END_DATE));
          if (hasApproved) {
            excusedLateCount++;
          } else {
            lateCount++;
          }
        }
      });

      const penalizedLateCount = (lateCount >= 3) ? (lateCount - 2) : 0;
      const lateDeduction = penalizedLateCount * 50000;
      const basic = parseFloat(u.BASIC_SALARY || 0);
      const allow = parseFloat(u.ALLOWANCE || 0);

      const isExempt = isBonusExempt(u);
      const onTimeBonus = (!isExempt && lateCount === 0) ? 50000 : 0;
      const encashedDays = parseInt(u.ENCASHED_HOLIDAYS || 0, 10);
      const encashedPay = encashedDays * 50000;

      const bpjsTk = parseFloat(u.BPJS_TK_AMOUNT || 0);
      const hasKes = String(u.HAS_BPJS_KES).toUpperCase() === 'TRUE';
      const bpjsKes = hasKes ? parseFloat(u.BPJS_KES_AMOUNT || 0) : 0;
      const kasbon = parseFloat(u.KASBON_AMOUNT || 0);

      const deductions = lateDeduction + bpjsTk + bpjsKes + kasbon;
      const netSalary = (basic + allow + onTimeBonus + encashedPay) - deductions;

      if (!existing) {
        const payId = 'PAY-' + Math.floor(1000 + Math.random() * 9000);
        newRowsToAppend.push([
          payId, currentPeriod, u.EMP_ID, u.FULL_NAME, u.POSITION, u.DEPARTMENT,
          basic, allow, onTimeBonus, encashedDays, encashedPay,
          lateCount, excusedLateCount, lateDeduction, bpjsTk, hasKes, bpjsKes,
          kasbon, deductions, netSalary, Utilities.formatDate(now, 'Asia/Jakarta', 'dd/MM/yyyy'), 'TERBIT', 'Avitya Fitriana', 'Saut Reynald Ricardo Simbolon'
        ]);
      } else {
        const rowData = existing.rowData;
        const status = String(rowData[21] || '').trim();
        if (status !== 'TERBIT MANUAL') {
          const curName = String(rowData[3] || '');
          const curPos = String(rowData[4] || '');
          const curDept = String(rowData[5] || '');
          const curBasic = parseFloat(rowData[6] || 0);
          const curAllow = parseFloat(rowData[7] || 0);
          const curBonus = parseFloat(rowData[8] || 0);
          const curEncashed = parseInt(rowData[9] || 0, 10);
          const curEncashedPay = parseFloat(rowData[10] || 0);
          const curLate = parseInt(rowData[11] || 0, 10);
          const curExcused = parseInt(rowData[12] || 0, 10);
          const curLateDed = parseFloat(rowData[13] || 0);
          const curBpjsTk = parseFloat(rowData[14] || 0);
          const curHasKes = String(rowData[15]).toUpperCase() === 'TRUE';
          const curBpjsKes = parseFloat(rowData[16] || 0);
          const curKasbon = parseFloat(rowData[17] || 0);
          const curDed = parseFloat(rowData[18] || 0);
          const curNet = parseFloat(rowData[19] || 0);

          if (curBasic !== basic || curAllow !== allow || curBonus !== onTimeBonus ||
              curEncashed !== encashedDays || curEncashedPay !== encashedPay ||
              curLate !== lateCount || curExcused !== excusedLateCount ||
              curLateDed !== lateDeduction || curBpjsTk !== bpjsTk ||
              curHasKes !== hasKes || curBpjsKes !== bpjsKes ||
              curKasbon !== kasbon || curDed !== deductions || curNet !== netSalary ||
              curName !== u.FULL_NAME || curPos !== u.POSITION || curDept !== u.DEPARTMENT) {
            if (!updatedPayValues) {
              updatedPayValues = payDataRange.getValues();
            }
            const rIdx = existing.rowIndex;
            updatedPayValues[rIdx][3] = u.FULL_NAME;
            updatedPayValues[rIdx][4] = u.POSITION;
            updatedPayValues[rIdx][5] = u.DEPARTMENT;
            updatedPayValues[rIdx][6] = basic;
            updatedPayValues[rIdx][7] = allow;
            updatedPayValues[rIdx][8] = onTimeBonus;
            updatedPayValues[rIdx][9] = encashedDays;
            updatedPayValues[rIdx][10] = encashedPay;
            updatedPayValues[rIdx][11] = lateCount;
            updatedPayValues[rIdx][12] = excusedLateCount;
            updatedPayValues[rIdx][13] = lateDeduction;
            updatedPayValues[rIdx][14] = bpjsTk;
            updatedPayValues[rIdx][15] = hasKes;
            updatedPayValues[rIdx][16] = bpjsKes;
            updatedPayValues[rIdx][17] = kasbon;
            updatedPayValues[rIdx][18] = deductions;
            updatedPayValues[rIdx][19] = netSalary;
            hasUpdates = true;
          }
        }
      }
    });

    if (hasUpdates && updatedPayValues) {
      payDataRange.setValues(updatedPayValues);
    }

    if (newRowsToAppend.length > 0) {
      const startRow = payRows.length + 1;
      payslipSheet.getRange(startRow, 1, newRowsToAppend.length, newRowsToAppend[0].length).setValues(newRowsToAppend);
    }
  } catch (err) {
    Logger.log('ensurePayslipsData error: ' + err.message);
  }
}

function getPayslipsData(empId, role, preloaded) {
  try {
    ensurePayslipsData(empId, role, preloaded);
    const payslips = getBatchData('PAYSLIPS');
    const id = String(empId || '').trim().toUpperCase();
    if (role === 'ADMIN') {
      return { success: true, payslips: payslips.slice().reverse() };
    } else {
      return { success: true, payslips: payslips.filter(p => String(p.EMP_ID || '').trim().toUpperCase() === id).slice().reverse() };
    }
  } catch (err) {
    return { success: false, payslips: [] };
  }
}

function getDashboardData(empId) {
  try {
    const normalizedEmpId = String(empId || '').trim().toUpperCase();
    const users = getBatchData('USERS');
    // PERBAIKAN: tidak lagi memakai users[0] (Admin) bila ID tidak ditemukan
    const currentUser = users.find(u => String(u.EMP_ID || '').trim().toUpperCase() === normalizedEmpId);
    if (!currentUser) {
      return { success: false, message: 'Data karyawan ' + empId + ' tidak ditemukan. Silakan login ulang.' };
    }
    empId = String(currentUser.EMP_ID || '').trim();
    const sameEmp = (v) => String(v || '').trim().toUpperCase() === normalizedEmpId;

    const settingsRaw = getBatchData('SETTINGS');

    let settings = {};
    settingsRaw.forEach(s => {
      settings[s.KEY] = s.VALUE;
    });
    settings.OFFICE_LAT = String(parseCoord(settings.OFFICE_LAT, -6.175392));
    settings.OFFICE_LNG = String(parseCoord(settings.OFFICE_LNG, 106.827153));

    const workStart = settings.WORK_START_TIME || '08:00';
    const workStartMinutes = parseTimeToMinutes(workStart);

    const todayStr = Utilities.formatDate(new Date(), 'Asia/Jakarta', 'dd/MM/yyyy');
    const attSheet = getSheet('ATTENDANCE');
    const attRange = attSheet.getDataRange();
    const attRows = attRange.getDisplayValues();
    let attValues = null;
    let attUpdated = false;

    for (let i = 1; i < attRows.length; i++) {
      if (attRows[i][1] === todayStr) {
        const checkinTime = attRows[i][4];
        if (checkinTime && checkinTime !== '-') {
          const cMinutes = parseTimeToMinutes(checkinTime);
          if (cMinutes > 0 && workStartMinutes > 0) {
            const shouldBeStatus = (cMinutes > workStartMinutes) ? 'TERLAMBAT' : 'TEPAT WAKTU';
            if (attRows[i][11] !== shouldBeStatus) {
              if (!attValues) attValues = attRange.getValues();
              attValues[i][11] = shouldBeStatus;
              attRows[i][11] = shouldBeStatus;
              attUpdated = true;
            }
          }
        }
      }
    }

    if (attUpdated && attValues) {
      attRange.setValues(attValues);
    }

    const attendanceList = [];
    if (attRows.length > 1) {
      const headers = attRows[0];
      for (let i = 1; i < attRows.length; i++) {
        const obj = {};
        for (let j = 0; j < headers.length; j++) {
          obj[headers[j]] = attRows[i][j];
        }
        attendanceList.push(obj);
      }
    }

    const todayAtt = attendanceList.filter(a => a.DATE === todayStr);

    const presentCount = todayAtt.length;
    const lateCount = todayAtt.filter(a => a.STATUS === 'TERLAMBAT').length;
    const requests = getBatchData('REQUESTS');
    const pendingReqs = requests.filter(r => r.STATUS === 'PENDING').length;

    const userTodayAtt = todayAtt.find(a => sameEmp(a.EMP_ID));
    const agendas = getBatchData('AGENDAS');
    const duties = getDutiesRaw();

    const activeDuty = findActiveDuty(duties, empId, todayStr);

    let filteredAttendance = [];
    if (currentUser.ROLE === 'ADMIN') {
      filteredAttendance = attendanceList.slice().reverse();
    } else {
      filteredAttendance = attendanceList.filter(a => sameEmp(a.EMP_ID)).reverse();
    }

    let userRequests = [];
    if (currentUser.ROLE === 'ADMIN') {
      userRequests = requests.slice().reverse();
    } else {
      userRequests = requests.filter(r => sameEmp(r.EMP_ID)).reverse();
    }

    const approvedLateRequests = requests.filter(r =>
      sameEmp(r.EMP_ID) &&
      String(r.TYPE || '').trim().toLowerCase().includes('terlambat') &&
      (String(r.STATUS || '').toUpperCase() === 'APPROVED' || String(r.STATUS || '').toUpperCase() === 'DISETUJUI')
    );
    const userLates = attendanceList.filter(a => sameEmp(a.EMP_ID) && a.STATUS === 'TERLAMBAT');
    let unexcusedLateCount = 0;
    userLates.forEach(att => {
      const hasApprovedLeave = approvedLateRequests.some(req =>
        isDateBetween(att.DATE, req.START_DATE, req.END_DATE)
      );
      if (!hasApprovedLeave) {
        unexcusedLateCount++;
      }
    });
    const isDisciplined = (unexcusedLateCount === 0);

    let approvedTabungDays = 0;
    let approvedPenggantiDays = 0;

    requests.forEach(r => {
      const isApproved = (String(r.STATUS || '').trim().toUpperCase() === 'APPROVED' || String(r.STATUS || '').trim().toUpperCase() === 'DISETUJUI');
      if (sameEmp(r.EMP_ID) && isApproved) {
        const typeClean = (r.TYPE || '').trim().toLowerCase();
        const days = calculateDaysBetween(r.START_DATE, r.END_DATE);
        if (typeClean.includes('tabung') || typeClean.includes('menabung')) {
          approvedTabungDays += days;
        } else if (typeClean.includes('pengganti')) {
          approvedPenggantiDays += days;
        }
      }
    });

    const encashedCount = parseInt(currentUser.ENCASHED_HOLIDAYS || '0', 10) || 0;
    const sheetSaved = parseInt(currentUser.SAVED_HOLIDAYS || '0', 10) || 0;
    const computedSaved = Math.max(0, approvedTabungDays - approvedPenggantiDays - encashedCount);
    const effectiveSavedHolidays = Math.max(sheetSaved, computedSaved);

    if (effectiveSavedHolidays !== sheetSaved) {
      try {
        const userSheet = getSheet('USERS');
        const uData = userSheet.getDataRange().getDisplayValues();
        for (let uIdx = 1; uIdx < uData.length; uIdx++) {
          if (uData[uIdx][0].trim().toUpperCase() === normalizedEmpId) {
            userSheet.getRange(uIdx + 1, 10).setValue(effectiveSavedHolidays);
            currentUser.SAVED_HOLIDAYS = String(effectiveSavedHolidays);
            break;
          }
        }
      } catch (syncErr) {
        Logger.log('Sync saved holidays warning: ' + syncErr.message);
      }
    }

    const payslipBundle = getPayslipsData(empId, currentUser.ROLE, { users: users, attendance: attendanceList, requests: requests });

    return {
      success: true,
      currentUser: sanitizeUser(currentUser),
      settings: settings,
      activeDuty: activeDuty || null,
      stats: {
        totalEmployees: users.length,
        presentToday: presentCount,
        lateToday: lateCount,
        leaveBalance: currentUser.LEAVE_BALANCE,
        savedHolidays: effectiveSavedHolidays,
        pendingRequestsCount: pendingReqs,
        isDisciplined: isDisciplined
      },
      userTodayAtt: userTodayAtt || null,
      recentAttendance: filteredAttendance,
      requests: userRequests,
      payslips: payslipBundle.success ? payslipBundle.payslips : [],
      allUsers: currentUser.ROLE === 'ADMIN' ? users.map(sanitizeUser) : [],
      agendas: agendas.slice().reverse(),
      duties: currentUser.ROLE === 'ADMIN' ? duties.slice().reverse() : duties.filter(d => sameEmp(d.EMP_ID)).reverse()
    };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

function updateProfilePhoto(empId, photoBase64) {
  try {
    const userSheet = getSheet('USERS');
    const data = userSheet.getDataRange().getDisplayValues();
    const id = String(empId || '').trim().toUpperCase();

    let targetRow = -1;
    for (let i = 1; i < data.length; i++) {
      if (String(data[i][0]).trim().toUpperCase() === id) {
        targetRow = i + 1;
        break;
      }
    }

    if (targetRow === -1) {
      return { success: false, message: 'Data karyawan tidak ditemukan.' };
    }
    if (String(photoBase64 || '').length > 49000) {
      return { success: false, message: 'Ukuran foto terlalu besar untuk Spreadsheet. Gunakan foto yang lebih kecil.' };
    }

    userSheet.getRange(targetRow, 15).setValue(photoBase64);
    SpreadsheetApp.flush();

    return { success: true, message: 'Foto profil berhasil diperbarui!', photoUrl: photoBase64 };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

function updateRequestStatus(reqId, status, adminNote, adminName) {
  try {
    const reqSheet = getSheet('REQUESTS');
    const reqData = reqSheet.getDataRange().getDisplayValues();

    let rowIndex = -1;
    let targetReq = null;

    for (let i = 1; i < reqData.length; i++) {
      if (reqData[i][0] === reqId) {
        rowIndex = i + 1;
        targetReq = {
          reqId: reqData[i][0],
          empId: reqData[i][2],
          fullName: reqData[i][3],
          type: reqData[i][4],
          startDate: reqData[i][5],
          endDate: reqData[i][6],
          reason: reqData[i][7],
          status: reqData[i][9]
        };
        break;
      }
    }

    if (rowIndex === -1) {
      return { success: false, message: 'Data pengajuan tidak ditemukan.' };
    }
    // PERBAIKAN: cegah saldo cuti terpotong 2x
    if (String(targetReq.status || '').trim().toUpperCase() !== 'PENDING') {
      return { success: false, message: 'Pengajuan ' + reqId + ' sudah diproses sebelumnya (' + targetReq.status + ').' };
    }

    reqSheet.getRange(rowIndex, 10).setValue(status);
    reqSheet.getRange(rowIndex, 11).setValue(adminName || 'Admin');
    reqSheet.getRange(rowIndex, 12).setValue(adminNote || '-');

    if (status === 'APPROVED' || status === 'DISETUJUI') {
      const userSheet = getSheet('USERS');
      const userData = userSheet.getDataRange().getDisplayValues();
      let uRowIndex = -1;
      let currentLeave = 0;
      let currentSaved = 0;

      for (let j = 1; j < userData.length; j++) {
        if (userData[j][0].trim() === targetReq.empId.trim()) {
          uRowIndex = j + 1;
          currentLeave = parseInt(userData[j][8] || '0', 10);
          currentSaved = parseInt(userData[j][9] || '0', 10);
          break;
        }
      }

      const reqDays = calculateDaysBetween(targetReq.startDate, targetReq.endDate);
      const reqTypeLower = (targetReq.type || '').trim().toLowerCase();

      if (uRowIndex !== -1) {
        if (reqTypeLower.includes('cuti')) {
          const newLeave = Math.max(0, currentLeave - reqDays);
          userSheet.getRange(uRowIndex, 9).setValue(newLeave);
        } else if (reqTypeLower.includes('tabung') || reqTypeLower.includes('menabung')) {
          userSheet.getRange(uRowIndex, 10).setValue(currentSaved + reqDays);
        } else if (reqTypeLower.includes('pengganti')) {
          const newSaved = Math.max(0, currentSaved - reqDays);
          userSheet.getRange(uRowIndex, 10).setValue(newSaved);
        }
      }

      const agendaSheet = getSheet('AGENDAS');
      const agData = agendaSheet.getDataRange().getDisplayValues();
      const nextAgdId = 'AGD-' + String(agData.length).padStart(4, '0');
      const todayStr = Utilities.formatDate(new Date(), 'Asia/Jakarta', 'dd/MM/yyyy HH:mm:ss');

      agendaSheet.appendRow([
        nextAgdId,
        targetReq.startDate,
        targetReq.fullName + ' - ' + targetReq.type,
        'Status Disetujui HR: ' + targetReq.reason + ' (s/d ' + targetReq.endDate + ')',
        'Cuti Karyawan',
        'Sistem HR',
        targetReq.empId,
        todayStr
      ]);
    }

    SpreadsheetApp.flush();
    return { success: true, message: 'Status pengajuan ' + reqId + ' berhasil diperbarui ke ' + status };

  } catch (err) {
    return { success: false, message: err.message };
  }
}

function saveEmployee(employeeData) {
  try {
    const userSheet = getSheet('USERS');
    const data = userSheet.getDataRange().getDisplayValues();

    const bpjsTk = employeeData.bpjsTkAmount || '0';
    const hasKes = employeeData.hasBpjsKes ? 'TRUE' : 'FALSE';
    const bpjsKes = employeeData.bpjsKesAmount || '0';
    const kasbon = employeeData.kasbonAmount || '0';
    const encashedHolidays = employeeData.encashedHolidays || '0';

    if (!employeeData.isEdit) {
      const exist = data.some(row => row[2].trim().toLowerCase() === employeeData.email.trim().toLowerCase());
      if (exist) {
        return { success: false, message: 'Email sudah terdaftar untuk karyawan lain!' };
      }

      // PERBAIKAN: ID baru = nomor terbesar + 1 (dulu memakai jumlah baris -> bisa dobel jika ada baris dihapus)
      let maxNum = 0;
      for (let i = 1; i < data.length; i++) {
        const n = parseInt(String(data[i][0]).replace(/\D/g, ''), 10);
        if (!isNaN(n) && n > maxNum) maxNum = n;
      }
      const nextId = 'EMP-' + String(maxNum + 1).padStart(4, '0');
      const defaultPic = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80';

      userSheet.appendRow([
        nextId, employeeData.fullName, employeeData.email, employeeData.password || 'user123', employeeData.role || 'EMPLOYEE',
        employeeData.position, employeeData.department, employeeData.joinDate, employeeData.leaveBalance || '12',
        employeeData.savedHolidays || '0', employeeData.contractUrl || '-', employeeData.bpjsUrl || '-',
        employeeData.basicSalary || '0', employeeData.allowance || '0', defaultPic,
        bpjsTk, hasKes, bpjsKes, kasbon, encashedHolidays
      ]);

      SpreadsheetApp.flush();
      return { success: true, message: 'Karyawan baru berhasil ditambahkan dengan ID: ' + nextId };
    } else {
      let targetRow = -1;
      for (let i = 1; i < data.length; i++) {
        if (data[i][0] === employeeData.empId) {
          targetRow = i + 1;
          break;
        }
      }

      if (targetRow === -1) return { success: false, message: 'Data karyawan tidak ditemukan.' };

      userSheet.getRange(targetRow, 2).setValue(employeeData.fullName);
      userSheet.getRange(targetRow, 5).setValue(employeeData.role);
      userSheet.getRange(targetRow, 6).setValue(employeeData.position);
      userSheet.getRange(targetRow, 7).setValue(employeeData.department);
      userSheet.getRange(targetRow, 9).setValue(employeeData.leaveBalance);

      if (employeeData.savedHolidays !== undefined && employeeData.savedHolidays !== null && employeeData.savedHolidays !== '') {
        userSheet.getRange(targetRow, 10).setValue(employeeData.savedHolidays);
      }

      userSheet.getRange(targetRow, 11).setValue(employeeData.contractUrl);
      userSheet.getRange(targetRow, 12).setValue(employeeData.bpjsUrl);
      userSheet.getRange(targetRow, 13).setValue(employeeData.basicSalary);
      userSheet.getRange(targetRow, 14).setValue(employeeData.allowance);
      userSheet.getRange(targetRow, 16).setValue(bpjsTk);
      userSheet.getRange(targetRow, 17).setValue(hasKes);
      userSheet.getRange(targetRow, 18).setValue(bpjsKes);
      userSheet.getRange(targetRow, 19).setValue(kasbon);
      userSheet.getRange(targetRow, 20).setValue(encashedHolidays);

      if (employeeData.password) {
        userSheet.getRange(targetRow, 4).setValue(employeeData.password);
      }

      // Sinkronkan langsung ke sheet PAYSLIPS untuk periode aktif
      try {
        const paySheet = getSheet('PAYSLIPS');
        const pRange = paySheet.getDataRange();
        const pRows = pRange.getValues();
        let pUpdated = false;

        const now = new Date();
        const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        const currentPeriod = monthNames[now.getMonth()] + ' ' + now.getFullYear();

        for (let p = 1; p < pRows.length; p++) {
          const rowEmpId = String(pRows[p][2] || '').trim().toUpperCase();
          const rowPeriod = String(pRows[p][1] || '').trim();
          const rowStatus = String(pRows[p][21] || '').trim();

          if (rowEmpId === String(employeeData.empId).trim().toUpperCase() && rowPeriod === currentPeriod && rowStatus !== 'TERBIT MANUAL') {
            const basic = parseFloat(employeeData.basicSalary || 0);
            const allow = parseFloat(employeeData.allowance || 0);
            const encashedDays = parseInt(employeeData.encashedHolidays || 0, 10);
            const encashedPay = encashedDays * 50000;
            const isExempt = isBonusExempt({ EMP_ID: employeeData.empId, FULL_NAME: employeeData.fullName, POSITION: employeeData.position, DEPARTMENT: employeeData.department, ROLE: employeeData.role });
            const onTimeBonus = isExempt ? 0 : parseFloat(pRows[p][8] || 0);
            const lateDed = parseFloat(pRows[p][13] || 0);
            const bTk = parseFloat(bpjsTk || 0);
            const bKes = (hasKes === 'TRUE') ? parseFloat(bpjsKes || 0) : 0;
            const kb = parseFloat(kasbon || 0);

            const totDed = lateDed + bTk + bKes + kb;
            const net = (basic + allow + onTimeBonus + encashedPay) - totDed;

            pRows[p][3] = employeeData.fullName;
            pRows[p][4] = employeeData.position;
            pRows[p][5] = employeeData.department;
            pRows[p][6] = basic;
            pRows[p][7] = allow;
            pRows[p][8] = onTimeBonus;
            pRows[p][9] = encashedDays;
            pRows[p][10] = encashedPay;
            pRows[p][14] = bTk;
            pRows[p][15] = (hasKes === 'TRUE');
            pRows[p][16] = bKes;
            pRows[p][17] = kb;
            pRows[p][18] = totDed;
            pRows[p][19] = net;
            pUpdated = true;
          }
        }
        if (pUpdated) {
          pRange.setValues(pRows);
        }
      } catch (paySyncErr) {
        Logger.log('Sync pay on save employee error: ' + paySyncErr.message);
      }

      SpreadsheetApp.flush();
      return { success: true, message: 'Data Karyawan ' + employeeData.empId + ' berhasil diperbarui.' };
    }

  } catch (err) {
    return { success: false, message: err.message };
  }
}

function submitAttendance(data) {
  try {
    const attSheet = getSheet('ATTENDANCE');
    const settings = getBatchData('SETTINGS');
    let settingsObj = {};
    settings.forEach(s => settingsObj[s.KEY] = s.VALUE);

    const empId = String(data.empId || '').trim();
    const lat = parseCoord(data.lat, NaN);
    const lng = parseCoord(data.lng, NaN);
    if (isNaN(lat) || isNaN(lng) || (lat === 0 && lng === 0)) {
      return { success: false, message: 'Lokasi GPS tidak terbaca. Aktifkan izin lokasi lalu tekan Refresh GPS.' };
    }

    const todayStr = Utilities.formatDate(new Date(), 'Asia/Jakarta', 'dd/MM/yyyy');
    const timeStr = Utilities.formatDate(new Date(), 'Asia/Jakarta', 'HH:mm');
    const rows = attSheet.getDataRange().getDisplayValues();

    let existingRowIndex = -1;
    for (let i = 1; i < rows.length; i++) {
      if (rows[i][1] === todayStr && String(rows[i][2]).trim().toUpperCase() === empId.toUpperCase()) {
        existingRowIndex = i + 1;
        break;
      }
    }

    // PERBAIKAN: tanggal & koordinat dinas dibaca dari nilai asli sel
    const activeDuty = findActiveDuty(getDutiesRaw(), empId, todayStr);

    let targetLat = activeDuty ? parseCoord(activeDuty.LATITUDE, NaN) : parseCoord(settingsObj.OFFICE_LAT, -6.175392);
    let targetLng = activeDuty ? parseCoord(activeDuty.LONGITUDE, NaN) : parseCoord(settingsObj.OFFICE_LNG, 106.827153);
    let maxRadius = activeDuty ? parseCoord(activeDuty.RADIUS_METERS, 50) : parseCoord(settingsObj.MAX_RADIUS_METERS, 10);
    let locationName = activeDuty ? ('📍 Dinas: ' + activeDuty.LOCATION_NAME) : (settingsObj.OFFICE_NAME || 'PT Artha Safety Indonesia - Kantor Pusat HQ');
    if (isNaN(targetLat) || isNaN(targetLng)) {
      return { success: false, message: 'Koordinat lokasi tugas dinas tidak valid. Hubungi Admin untuk memperbaiki data dinas.' };
    }

    const distance = calculateDistanceMeters(targetLat, targetLng, lat, lng);
    if (distance > maxRadius) {
      const accTxt = data.accuracy ? ' Akurasi GPS HP Anda ±' + Math.round(data.accuracy) + 'm — coba tekan Refresh GPS di dekat jendela / area terbuka.' : '';
      return { success: false, message: 'Gagal! Posisi Anda berada ' + distance + ' meter dari lokasi target (Maksimal ' + maxRadius + 'm).' + accTxt };
    }

    const workStart = settingsObj.WORK_START_TIME || '08:00';
    const checkinMinutes = parseTimeToMinutes(timeStr);
    const workStartMinutes = parseTimeToMinutes(workStart);
    const isLate = checkinMinutes > workStartMinutes;
    const status = isLate ? 'TERLAMBAT' : 'TEPAT WAKTU';

    if (data.type === 'CHECKIN') {
      if (existingRowIndex !== -1) {
        return { success: false, message: 'Anda sudah melakukan Absen Masuk hari ini.' };
      }
      const attId = 'ATT-' + Math.floor(1000 + Math.random() * 9000);
      attSheet.appendRow([
        attId, todayStr, empId, data.fullName, timeStr, '-',
        lat, lng, distance, data.photoBase64, '-', status, locationName
      ]);
      SpreadsheetApp.flush();
      return { success: true, message: 'Absen Masuk berhasil dicatat pada jam ' + timeStr + ' (' + status + ')' };
    } else if (data.type === 'CHECKOUT') {
      if (existingRowIndex === -1) {
        return { success: false, message: 'Anda belum melakukan Absen Masuk hari ini.' };
      }
      attSheet.getRange(existingRowIndex, 6).setValue(timeStr);
      attSheet.getRange(existingRowIndex, 11).setValue(data.photoBase64);
      SpreadsheetApp.flush();
      return { success: true, message: 'Absen Keluar berhasil dicatat pada jam ' + timeStr };
    }

    return { success: false, message: 'Tipe presensi tidak valid.' };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

function submitRequest(data) {
  try {
    const reqSheet = getSheet('REQUESTS');
    const rows = reqSheet.getDataRange().getDisplayValues();
    const reqId = 'REQ-' + String(rows.length).padStart(4, '0');
    const submitDate = Utilities.formatDate(new Date(), 'Asia/Jakarta', 'dd/MM/yyyy');

    let docUrl = data.docUrl || '-';
    if (data.fileBase64 && data.fileBase64.startsWith('data:')) {
      try {
        const parts = data.fileBase64.split(',');
        const contentType = parts[0].split(':')[1].split(';')[0];
        const rawData = Utilities.base64Decode(parts[1]);
        const blob = Utilities.newBlob(rawData, contentType, data.fileName || 'Lampiran_Pengajuan.pdf');

        const folder = DriveApp.getRootFolder();
        const file = folder.createFile(blob);
        file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
        docUrl = file.getUrl();
      } catch (driveErr) {
        Logger.log('Drive upload warning: ' + driveErr.message);
      }
    }

    reqSheet.appendRow([
      reqId, submitDate, data.empId, data.fullName, data.type,
      data.startDate, data.endDate, data.reason, docUrl, 'PENDING', '-', '-'
    ]);

    SpreadsheetApp.flush();
    return { success: true, message: 'Pengajuan ' + data.type + ' berhasil dikirim ke Admin!' };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

function addAgenda(data) {
  try {
    const sheet = getSheet('AGENDAS');
    const rows = sheet.getDataRange().getDisplayValues();
    const agendaId = 'AGD-' + String(rows.length).padStart(4, '0');
    const nowStr = Utilities.formatDate(new Date(), 'Asia/Jakarta', 'dd/MM/yyyy HH:mm:ss');

    sheet.appendRow([
      agendaId, data.date, data.title, data.description, data.category, data.createdBy, data.empId, nowStr
    ]);
    SpreadsheetApp.flush();
    return { success: true, message: 'Agenda / catatan berhasil ditambahkan!' };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

function deleteAgenda(agendaId, empId, role) {
  try {
    const sheet = getSheet('AGENDAS');
    const rows = sheet.getDataRange().getDisplayValues();
    for (let i = 1; i < rows.length; i++) {
      if (rows[i][0] === agendaId) {
        if (role === 'ADMIN' || rows[i][6] === empId) {
          sheet.deleteRow(i + 1);
          SpreadsheetApp.flush();
          return { success: true, message: 'Agenda berhasil dihapus.' };
        } else {
          return { success: false, message: 'Anda tidak memiliki hak akses untuk menghapus agenda ini.' };
        }
      }
    }
    return { success: false, message: 'Agenda tidak ditemukan.' };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

function addDutyAssignment(data) {
  try {
    const sheet = getSheet('DUTIES');
    const rows = sheet.getDataRange().getDisplayValues();
    const dutyId = 'DUTY-' + String(rows.length).padStart(4, '0');
    const nowStr = Utilities.formatDate(new Date(), 'Asia/Jakarta', 'dd/MM/yyyy HH:mm:ss');

    const users = getBatchData('USERS');
    const targetUser = users.find(u => u.EMP_ID === data.empId);
    const fullName = targetUser ? targetUser.FULL_NAME : data.empId;

    const lat = parseCoord(data.lat, NaN);
    const lng = parseCoord(data.lng, NaN);
    if (isNaN(lat) || isNaN(lng)) {
      return { success: false, message: 'Koordinat lokasi dinas tidak valid. Contoh: -6.175392 dan 106.827153' };
    }

    sheet.appendRow([
      dutyId, data.empId, fullName, data.locationName, lat,
      lng, parseCoord(data.radius, 50), data.startDate, data.endDate, data.reason, data.createdBy, nowStr
    ]);
    SpreadsheetApp.flush();
    return { success: true, message: 'Tugas dinas luar kota berhasil diberikan kepada ' + fullName + '!' };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

function deleteDutyAssignment(dutyId) {
  try {
    const sheet = getSheet('DUTIES');
    const rows = sheet.getDataRange().getDisplayValues();
    for (let i = 1; i < rows.length; i++) {
      if (rows[i][0] === dutyId) {
        sheet.deleteRow(i + 1);
        SpreadsheetApp.flush();
        return { success: true, message: 'Tugas dinas berhasil dihapus.' };
      }
    }
    return { success: false, message: 'Data tugas dinas tidak ditemukan.' };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

function saveManualPayslip(data) {
  try {
    const sheet = getSheet('PAYSLIPS');
    const payslipId = 'PAY-' + Math.floor(1000 + Math.random() * 9000);

    const basic = parseFloat(data.basicSalary || 0);
    const allow = parseFloat(data.allowance || 0);
    const onTime = parseFloat(data.onTimeBonus || 0);
    const encashedDays = parseInt(data.encashedHolidays || 0, 10);
    const encashedPay = encashedDays * 50000;

    const lateDed = parseFloat(data.lateDeduction || 0);
    const bpjsTk = parseFloat(data.bpjsTkAmount || 0);
    const bpjsKes = data.hasBpjsKes ? parseFloat(data.bpjsKesAmount || 0) : 0;
    const kasbon = parseFloat(data.kasbonAmount || 0);

    const income = basic + allow + onTime + encashedPay;
    const deductions = lateDed + bpjsTk + bpjsKes + kasbon;
    const net = income - deductions;

    sheet.appendRow([
      payslipId, data.period, data.empId, data.fullName, data.position, data.department,
      basic, allow, onTime, encashedDays, encashedPay,
      parseInt(data.lateCount || 0), parseInt(data.excusedLateCount || 0), lateDed, bpjsTk, data.hasBpjsKes, bpjsKes,
      kasbon, deductions, net, data.paymentDate || new Date().toLocaleDateString('id-ID'), 'TERBIT MANUAL', 'Avitya Fitriana', 'Saut Reynald Ricardo Simbolon'
    ]);

    SpreadsheetApp.flush();
    return { success: true, message: 'Slip gaji periode ' + data.period + ' berhasil diterbitkan!' };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

function updateOfficeSettings(data) {
  try {
    const sheet = getSheet('SETTINGS');
    const rows = sheet.getDataRange().getValues();
    const lat = parseCoord(data.lat, NaN);
    const lng = parseCoord(data.lng, NaN);
    if (isNaN(lat) || isNaN(lng)) {
      return { success: false, message: 'Latitude / Longitude tidak valid. Gunakan titik sebagai pemisah desimal, contoh -6.175392' };
    }

    for (let i = 1; i < rows.length; i++) {
      const key = rows[i][0];
      // Simpan koordinat sebagai teks agar tidak diubah format locale spreadsheet
      if (key === 'OFFICE_LAT') sheet.getRange(i + 1, 2).setNumberFormat('@').setValue(String(lat));
      if (key === 'OFFICE_LNG') sheet.getRange(i + 1, 2).setNumberFormat('@').setValue(String(lng));
      if (key === 'MAX_RADIUS_METERS') sheet.getRange(i + 1, 2).setValue(data.radius);
      if (key === 'WORK_START_TIME') sheet.getRange(i + 1, 2).setValue(data.workStart);
      if (key === 'WORK_END_TIME') sheet.getRange(i + 1, 2).setValue(data.workEnd);
    }

    SpreadsheetApp.flush();
    return { success: true, message: 'Pengaturan kantor & jam kerja berhasil disimpan!' };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

function calculateDistanceMeters(lat1, lon1, lat2, lon2) {
  const R = 6371000;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLon = (lon2 - lon1) * Math.PI / 180;
  const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLon/2) * Math.sin(dLon/2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
  return Math.round(R * c * 10) / 10;
}
