function onOpen() {
  SpreadsheetApp.getUi()
      .createMenu('Payslip Automation')
      .addItem('Generate & Send Payslips', 'generateAndEmailAllPayslips')
      .addItem('Email Empty Template to Me', 'emailEmptyTemplatePdfToMe')
      .addToUi();
}

// setupPayslipNamedRanges removed (autodiscovery creates PS_* as needed)

// Derive PS_* names from field ids (camelCase → UPPER_SNAKE, prefixed with PS_)
function psNameForKey_(key) {
  const snake = key.replace(/([a-z0-9])([A-Z])/g, '$1_$2').toUpperCase();
  return 'PS_' + snake;
}

function normalize_(s) {
  return String(s).replace(/\s+/g, ' ').trim().toLowerCase();
}

// Remove trailing numeric-only tokens when there are multiple words
function sanitizeEmployerName_(name) {
  const raw = String(name || '').trim();
  if (!raw) return '';
  const parts = raw.split(/\s+/);
  while (parts.length > 1 && /^\d+$/.test(parts[parts.length - 1])) {
    parts.pop();
  }
  return parts.join(' ');
}

// Labels in the template used to auto-map PS_* named ranges.
// For each field key, we search these label texts, then bind the PS_* range
// to the first cell to the right of the label row (skipping the label's merged width).
// Simplified fields: name → template label (single source of truth)
const FIELDS = {
  paySlipPeriod: 'Pay Slip for the Period of',
  period: 'PERIOD',
  employer: 'Employer',
  employeeName: 'Employee',
  employeeId: 'Employee Id',
  personalNumber: 'Personal Number',
  emailAddress: 'Email Address',
  workedHours: 'Worked Hours',
  salaryGross: 'Salary Hourly (Gross)',
  sickLeave: 'Sick Leave',
  sickLeaveHourlyGross: 'Sick Leave Hourly (Gross)',
  nightHours: 'Night Hours',
  nightHoursHourlyGross: 'Night Hours Hourly (Gross)',
  nightHoursRate: 'Night Hours Rate',
  paidLeave: 'Paid leave',
  paidLeaveHourlyGross: 'Paid leave Hourly (Gross)',
  otherAbsences: 'Other Absences',
  otherAbsencesHourlyGross: 'Other Absences Hourly (Gross)',
  overtimePublicHoliday: 'Overtime/Public Holiday',
  overtimePublicHolidayHourlyGross: 'Overtime/Public Holiday Hourly (Gross)',
  overtimePublicHolidayRate: 'Overtime/Public Holiday Rate',
  relocationCompensationGross: 'Relocation Compensation (Gross)',
  exchangeRate: 'Exchange Rate',
  bonusAmountGross: 'Bonus/Amount Gross',
  percent: '%',
  additionalBonusGross: 'Additional bonus (Gross)',
  compensation: 'Compensation',
  accruedNet: 'ACCRUED (NET)',
  pension: 'Pension',
  withheldTax: 'WITHHELD Personal income tax ',
  accruedGross: 'ACCRUED (GROSS)'
};

// Header overrides where source header != template label
const HEADER_OVERRIDES = {
  withheldTax: 'WITHHELD \nPersonal income tax ',
  personalNumber: 'Personal Number:',
};

// Fields whose values are taken from the template, not from the source data
const TEMPLATE_ONLY_FIELDS = {
  employer: true,
};

function buildHeaderIndexMap_(dataSheet) {
  const lastCol = dataSheet.getLastColumn();
  const headers = dataSheet.getRange(1, 1, 1, lastCol).getValues()[0].map(function(v){ return String(v); });
  const map = {};
  headers.forEach(function(h, i){ map[h] = i; });
  // Also provide a normalized lookup for resilient matching
  const norm = {};
  headers.forEach(function(h, i){ norm[normalize_(h)] = i; });
  return { exact: map, normalized: norm };
}

// Build field → columnIndex map from the source header row
function buildColIndexByField_(dataSheet) {
  const headerIndex = buildHeaderIndexMap_(dataSheet);
  const colIndexById = {};
  const missing = [];
  Object.keys(FIELDS).forEach(function(id){
    const label = FIELDS[id];
    const candidates = [];
    if (label) candidates.push(label);
    if (HEADER_OVERRIDES[id]) candidates.push(HEADER_OVERRIDES[id]);
    let found = false;
    for (let ci = 0; ci < candidates.length; ci++) {
      const name = candidates[ci];
      if (typeof headerIndex.exact[name] === 'number') { colIndexById[id] = headerIndex.exact[name]; found = true; break; }
      const n = normalize_(name);
      if (typeof headerIndex.normalized[n] === 'number') { colIndexById[id] = headerIndex.normalized[n]; found = true; break; }
    }
    if (!found && !TEMPLATE_ONLY_FIELDS[id]) missing.push(id + '=(' + candidates.join(' | ') + ')');
  });
  if (missing.length) Logger.log('Missing source headers for: ' + missing.join(', '));
  return colIndexById;
}

function applyAutoDiscovery_(templateSheet, dataSheet) {
  // Set PS_* named ranges by scanning labels via a single matrix pass,
  // binding the first cell to the right of each label's merged block.
  const ss = templateSheet.getParent();
  const range = templateSheet.getDataRange();
  const height = range.getNumRows();
  const width = range.getNumColumns();
  const matrix = range.getDisplayValues();

  // Build a quick index of normalized label → first (row, col)
  const labelIndex = {};
  for (let r = 0; r < height; r++) {
    for (let c = 0; c < width; c++) {
      const val = matrix[r][c];
      if (!val) continue;
      const k = normalize_(val);
      if (!(k in labelIndex)) {
        labelIndex[k] = [r + 1, c + 1]; // 1-based
      }
    }
  }

  const headerIndex = buildHeaderIndexMap_(dataSheet);
  const a1ById = {};
  const colIndexById = {};
  const missingLabels = [];
  const missingHeaders = [];

  Object.keys(FIELDS).forEach(function(id){
    const label = FIELDS[id];
    const ps = psNameForKey_(id);
    // Template side
    const pos = labelIndex[normalize_(label)];
    if (pos) {
      const labelCell = templateSheet.getRange(pos[0], pos[1]);
      const merged = labelCell.isPartOfMerge() ? labelCell.getMergedRanges()[0] : labelCell;
      const row = merged.getRow();
      const col = merged.getColumn() + merged.getNumColumns();
      const target = templateSheet.getRange(row, col);
      ss.setNamedRange(ps, target);
      a1ById[id] = target.getA1Notation();
    } else {
      missingLabels.push(label);
    }
    // Source side: try label first, then optional override (additional alias)
    const candidates = [];
    if (label) candidates.push(label);
    if (HEADER_OVERRIDES[id]) candidates.push(HEADER_OVERRIDES[id]);
    let found = false;
    for (let ci = 0; ci < candidates.length; ci++) {
      const name = candidates[ci];
      if (typeof headerIndex.exact[name] === 'number') {
        colIndexById[id] = headerIndex.exact[name];
        found = true; break;
      }
      const n = normalize_(name);
      if (typeof headerIndex.normalized[n] === 'number') {
        colIndexById[id] = headerIndex.normalized[n];
        found = true; break;
      }
    }
    if (!found && !TEMPLATE_ONLY_FIELDS[id]) {
      missingHeaders.push(candidates.join(' | '));
    }
  });

  SpreadsheetApp.flush();
  return { a1ById: a1ById, colIndexById: colIndexById, missingLabels: missingLabels, missingHeaders: missingHeaders };
}

// TEMPLATE_A1_MAPPING removed in favor of pure autodiscovery

// No fixed indices; header positions are autodiscovered at runtime

function getTemplateA1Map_() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const a1 = {};
  Object.keys(FIELDS).forEach(function(key){
    const name = psNameForKey_(key);
    const r = ss.getRangeByName(name);
    if (!r) throw new Error('Missing named range: ' + name);
    a1[key] = r.getA1Notation();
  });
  return a1;
}

// Log current named ranges to help verify mapping quickly
function debugLogNamedRanges() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const lines = [];
  Object.keys(FIELDS).forEach(function(key){
    const name = psNameForKey_(key);
    const r = ss.getRangeByName(name);
    if (!r) { lines.push(key + ': ' + name + ' -> MISSING'); return; }
    const merged = r.isPartOfMerge();
    const topLeft = (merged && r.getMergedRanges().length) ? r.getMergedRanges()[0].getA1Notation() : r.getA1Notation();
    lines.push(key + ': ' + name + ' -> ' + topLeft + (merged ? ' (merged)' : ''));
  });
  Logger.log(lines.join('\n'));
}

// assignSelectedToKey removed (autodiscovery handles PS_* mapping)

// Returns a Range that is safe to write into when the target cell is part of a merged region.
// If the given A1 is inside a merged range, this returns the top-left cell of that merged range.
function getWritableRange_(sheet, a1) {
  const r = sheet.getRange(a1);
  if (r.isPartOfMerge()) {
    const merged = r.getMergedRanges();
    if (merged && merged.length) {
      // Use the first merged range's top-left cell
      return merged[0];
    }
  }
  return r;
}

// Debug: verify Advanced Sheets service availability
function debugSheetsAdvanced() {
  Logger.log(typeof Sheets);
  const available = (typeof Sheets !== 'undefined') && Sheets.Spreadsheets && Sheets.Spreadsheets.Values;
  Logger.log('Sheets Advanced available: ' + !!available);
  if (available) Logger.log('batchUpdate fn type: ' + (typeof Sheets.Spreadsheets.Values.batchUpdate));
}

// Debug: try a small batch write to confirm high-speed path is active
function debugBatchWriteSmokeTest() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sh = ss.getSheetByName('Payslip Template');
  if (!sh) { Logger.log("Sheet 'Payslip Template' not found."); return; }
  const ok = tryBatchWriteValues_(ss.getId(), sh, {}, [{ a1: 'Z999', value: 'ok' }]);
  Logger.log('Batch write used: ' + ok);
  try { sh.getRange('Z999').clearContent(); } catch (e) {}
}

// Convenience setter that accounts for merged cells.
function setValueMergedSafe_(sheet, a1, value) {
  const target = getWritableRange_(sheet, a1);
  target.setValue(value);
}

// Cached writer to avoid repeated merged-range resolution
function getWritableRangeCached_(sheet, a1, cache) {
  if (cache[a1]) return cache[a1];
  const r = getWritableRange_(sheet, a1);
  cache[a1] = r;
  return r;
}

function setValueFast_(sheet, cache, a1, value) {
  const r = getWritableRangeCached_(sheet, a1, cache);
  r.setValue(value);
}

// Fetch a PDF with retry and validation. Returns { ok: true, blob } or { ok: false, error }
function fetchPdfWithRetry_(url, bearerToken, name) {
  const maxAttempts = 5;
  let saw429 = false;
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      const resp = UrlFetchApp.fetch(url, {
        headers: { 'Authorization': 'Bearer ' + bearerToken },
        muteHttpExceptions: true
      });
      const code = resp.getResponseCode();
      const ct = resp.getHeaders() && resp.getHeaders()['Content-Type'] ? String(resp.getHeaders()['Content-Type']) : '';
      const blob = resp.getBlob().setName(name);
      const bytes = blob.getBytes();
      // Validate: response code < 400 and starts with %PDF
      const isPdfHeader = bytes && bytes.length >= 4 && String.fromCharCode(bytes[0], bytes[1], bytes[2], bytes[3]) === '%PDF';
      if (code < 400 && isPdfHeader) {
        return { ok: true, blob: blob, saw429: saw429 };
      }
      Logger.log('PDF export attempt ' + attempt + ' failed: code=' + code + ', contentType=' + ct);
      if (code === 429) saw429 = true;
      // Exponential backoff with jitter
      const base = Math.pow(2, attempt - 1) * 600; // 0.6s,1.2s,2.4s,4.8s,9.6s
      const jitter = Math.floor(Math.random() * 300);
      Utilities.sleep(base + jitter);
    } catch (e) {
      const base = Math.pow(2, attempt - 1) * 600;
      const jitter = Math.floor(Math.random() * 300);
      Utilities.sleep(base + jitter);
    }
  }
  return { ok: false, error: 'PDF export failed after retries (rate limit or non-PDF response).', saw429: true };
}

// Attempt to batch-write many 1x1 updates in a single API call via the Advanced Sheets Service.
// Falls back to false if the service is unavailable or on any error so callers can do per-cell writes.
function tryBatchWriteValues_(spreadsheetId, sheet, cache, writes) {
  try {
    // Ensure Advanced Service is enabled; if not, bail out silently
    if (typeof Sheets === 'undefined' || !Sheets.Spreadsheets || !Sheets.Spreadsheets.Values) return false;
    const data = [];
    const sheetName = sheet.getName();
    for (let i = 0; i < writes.length; i++) {
      const w = writes[i];
      if (!w || !w.a1) continue;
      const rangeObj = getWritableRangeCached_(sheet, w.a1, cache);
      const a1TopLeft = rangeObj.getA1Notation();
      data.push({ range: sheetName + '!' + a1TopLeft, values: [[w.value]] });
    }
    if (!data.length) return true; // nothing to write
    Sheets.Spreadsheets.Values.batchUpdate({ valueInputOption: 'RAW', data: data }, spreadsheetId);
    return true;
  } catch (e) {
    // Swallow and signal fallback path
    return false;
  }
}

// Remove all PS_* named ranges (clean slate). Run only if you intend to remap.
function resetPayslipNamedRanges() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  ss.getNamedRanges().forEach(function(nr) {
    const name = nr.getName();
    if (name.indexOf('PS_') === 0) nr.remove();
  });
  Logger.log('Removed all PS_* named ranges.');
}

// Logs the source header row (from the first tab of the sheet at D1) and a header→index map
function debugLogSourceHeaders() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const template = ss.getSheetByName('Payslip Template');
  if (!template) { Logger.log("Sheet 'Payslip Template' not found."); return; }
  const url = String(template.getRange('D1').getDisplayValue()).trim();
  if (!url || url.indexOf('/spreadsheets/') === -1) { Logger.log('D1 does not contain a valid Google Sheets URL.'); return; }
  const source = SpreadsheetApp.openByUrl(url);
  const dataSheet = source.getSheets()[0];
  if (!dataSheet) { Logger.log('No sheets found in the source file.'); return; }
  const values = dataSheet.getDataRange().getValues();
  if (!values || !values.length) { Logger.log('Source sheet has no data.'); return; }
  const headers = values[0].map(function(v){ return String(v); });
  const map = {};
  headers.forEach(function(h, i){ map[h] = i; });
  Logger.log('Headers: ' + JSON.stringify(headers));
  Logger.log('HeaderIndexMap: ' + JSON.stringify(map));
  const expected = {};
  Object.keys(FIELDS).forEach(function(id){
    const headerName = HEADER_OVERRIDES[id] || FIELDS[id];
    expected[id] = headerName;
  });
  Logger.log('ExpectedHeaderByField: ' + JSON.stringify(expected));
}

// Auto-create all PS_* named ranges from TEMPLATE_A1_MAPPING (idempotent)
function applyNamedRangeMapping() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sh = ss.getSheetByName('Payslip Template');
  if (!sh) throw new Error("Sheet 'Payslip Template' not found.");
  // Auto-map from labels (one-time; do not call during generation for performance)
  const data = sh.getDataRange().getValues();
  const height = data.length;
  const width = data[0] ? data[0].length : 0;

  function findLabelCell(labelText) {
    for (let r = 1; r <= height; r++) {
      for (let c = 1; c <= width; c++) {
        const v = String(sh.getRange(r, c).getDisplayValue());
        if (normalize_(v) === normalize_(labelText)) return sh.getRange(r, c);
      }
    }
    return null;
  }

  Object.keys(FIELDS).forEach(function(id){
    const psName = psNameForKey_(id);
    const label = FIELDS[id];
    const cell = findLabelCell(label);
    if (cell) {
      const merged = cell.isPartOfMerge() ? cell.getMergedRanges()[0] : cell;
      const row = merged.getRow();
      const col = merged.getColumn() + merged.getNumColumns();
      const target = sh.getRange(row, col);
      ss.setNamedRange(psName, target);
    }
  });
  SpreadsheetApp.flush();
  Logger.log('Applied auto-mapping from labels (fallback to TEMPLATE_A1_MAPPING when not found).');
}

function generateAndEmailAllPayslips() {
  const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  const ui = SpreadsheetApp.getUi();
  const templateSheetName = 'Payslip Template';
  const urlCell = 'D1';
  const tRunStart = Date.now();
  // Throttling configuration (ms)
  const perRowDelayMs = 450;         // pacing per row when throttling is active
  const perRowJitterMs = 350;        // random jitter to avoid burst patterns
  const cooldownEveryRows = 5;       // add a longer cooldown every N rows when throttling is active
  const cooldownMs = 3500;           // cooldown duration

  let emailsSentCount = 0;
  let errorsCount = 0;
  let emailQuotaReached = false;
  let throttlingActive = false; // becomes true after first 429
  let consecutiveCleanRows = 0;  // rows completed without 429 since last throttle
  const hysteresisThreshold = 8; // disable throttle after N clean rows
  const skippedMissingEmail = [];

  // Track reusable temp sheet for cleanup across try/catch
  let reusableTempSheet = null;
  let reusableTempName = '';

  try {
    const templateSheet = spreadsheet.getSheetByName(templateSheetName);
    if (!templateSheet) {
      ui.alert('Error', "Sheet 'Payslip Template' not found.", ui.ButtonSet.OK);
      return;
    }

    // Early status: starting and clear totals
    try {
      templateSheet.getRange('D5').setValue('Starting...');
      templateSheet.getRange('D6').setValue('');
      SpreadsheetApp.flush();
    } catch (e) {}

    const sourceDataUrl = String(templateSheet.getRange(urlCell).getDisplayValue()).trim();

    // Precompute PDF area bounds once per run (avoid per-row named range lookups)
    let pdfBounds = null;
    try {
      const parent = templateSheet.getParent();
      const pdfArea = parent.getRangeByName('PAYSLIP_PDF_AREA') || parent.getRangeByName('PS_PDF_AREA');
      if (pdfArea) {
        const tr = pdfArea.getRow();
        const lc = pdfArea.getColumn();
        pdfBounds = {
          topRow: tr,
          leftCol: lc,
          lastRow: tr + pdfArea.getNumRows() - 1,
          lastCol: lc + pdfArea.getNumColumns() - 1
        };
      }
    } catch (e) {
      // Ignore and fallback inside worker
    }
    if (!sourceDataUrl || sourceDataUrl.indexOf('/spreadsheets/') === -1) {
      ui.alert('Error', 'Cell D1 must contain a valid Google Sheets URL.', ui.ButtonSet.OK);
      return;
    }

    const runModeRaw = String(templateSheet.getRange('D2').getDisplayValue()).trim().toLowerCase();
    const runMode = (runModeRaw === 'dry') ? 'dry' : 'live';
    // Validate mapping before running
    // Build source header indices (runtime) and use pre-created PS_* named ranges
    let sourceSpreadsheet;
    try {
      sourceSpreadsheet = SpreadsheetApp.openByUrl(sourceDataUrl);
    } catch (e) {
      ui.alert('Error', 'Cannot open source file. Ensure you have access and the URL is a direct spreadsheet link (not to a filter/protected view).\nOriginal error: ' + e.message, ui.ButtonSet.OK);
      return;
    }
    const dataSheet = sourceSpreadsheet.getSheets()[0];
    if (!dataSheet) {
      ui.alert('Error', 'No sheet found in the source file.', ui.ButtonSet.OK);
      return;
    }
    const colIndexById = buildColIndexByField_(dataSheet);
    const a1Map = getTemplateA1Map_();

    // Read only the needed data rows
    let data;
    if (runMode === 'dry') {
      const lastCol = dataSheet.getLastColumn();
      // Read just the header + first data row
      data = dataSheet.getRange(1, 1, Math.min(2, dataSheet.getLastRow()), lastCol).getValues();
    } else {
      data = dataSheet.getDataRange().getValues();
    }
    if (!data || data.length < 2) {
      ui.alert('Error', 'Source has no data rows.', ui.ButtonSet.OK);
      return;
    }

    data.shift();

    const loggedUserEmail = String(Session.getActiveUser().getEmail() || '').trim();
    if (runMode === 'dry' && !loggedUserEmail) {
      ui.alert('Error', 'Dry run requires a logged-in user email.', ui.ButtonSet.OK);
      return;
    }

    // Prepare a reusable temporary sheet to avoid per-row copy/delete
    try { templateSheet.getRange('D5').setValue('Generating PDF template...'); SpreadsheetApp.flush(); } catch (e) {}
    const tCopyStart = Date.now();
    reusableTempName = 'Payslip_TMP_' + Date.now();
    reusableTempSheet = templateSheet.copyTo(spreadsheet);
    try { reusableTempSheet.setName(reusableTempName); } catch (e) {}
    const copyMs = Date.now() - tCopyStart;
    Logger.log('Init copy time (ms): ' + copyMs);

    // Precompute static pieces for the whole run
    const bearerToken = ScriptApp.getOAuthToken();
    const persistentWriteCache = {}; // reuse resolved ranges across rows

    // D3 toggle only: 'mailapp' or 'mail' uses MailApp; otherwise GmailApp. PDF scale fixed (4).
    let pdfScale = 4;
    let useMailApp = false;
    try {
      const raw = String(templateSheet.getRange('D3').getDisplayValue()).trim().toLowerCase();
      useMailApp = (raw === 'mailapp' || raw === 'mail');
    } catch (e) {}

    // Precompute static PDF URL if bounds are known
    let precomputedPdfUrl = null;
    try {
      if (pdfBounds) {
        const tempSheetId = reusableTempSheet.getSheetId();
        const spreadsheetId = spreadsheet.getId();
        const r1Pad = Math.max(1, pdfBounds.topRow - 1);
        const c1Pad = Math.max(1, pdfBounds.leftCol - 1);
        const r2Pad = pdfBounds.lastRow + 1;
        const c2Pad = pdfBounds.lastCol + 1;
        precomputedPdfUrl = 'https://docs.google.com/spreadsheets/d/' + spreadsheetId +
          '/export?exportFormat=pdf&gid=' + tempSheetId +
          '&portrait=true' +
          '&scale=' + pdfScale +
          '&size=A4' +
          '&gridlines=false' +
          '&printtitle=false' +
          '&sheetnames=false' +
          '&pagenum=UNDEFINED' +
          '&top_margin=0.25' +
          '&bottom_margin=0.25' +
          '&left_margin=0.25' +
          '&right_margin=0.25' +
          '&r1=' + r1Pad + '&c1=' + c1Pad + '&r2=' + r2Pad + '&c2=' + c2Pad;
      }
    } catch (e) {}

    // Live status output on the template (D5). Does not affect PDFs.
    const statusCell = 'D5';
    const totalErrorsCell = 'D6';
    try {
      templateSheet.getRange(statusCell).setValue('0/' + data.length + ' emails sent');
      SpreadsheetApp.flush();
    } catch (e) {}

    for (let i = 0; i < data.length; i++) {
      const row = data[i];

      const emailCol = colIndexById.emailAddress;
      const employeeEmail = String((emailCol != null ? row[emailCol] : '') || '').trim();
      if (!employeeEmail) {
        skippedMissingEmail.push(i + 2);
        continue;
      }

      const overrideEmail = (runMode === 'dry') ? loggedUserEmail : null;

      const result = processPayslipForEmployee(
        spreadsheet,
        templateSheet,
        row,
        a1Map,
        overrideEmail,
        colIndexById,
        pdfBounds,
        reusableTempSheet,
        bearerToken,
        precomputedPdfUrl,
        persistentWriteCache,
        pdfScale,
        useMailApp
      );
      if (result && result.ok) {
        emailsSentCount++;
        if (result && result.timing) {
          Logger.log('Row ' + (i + 2) + ' timings (ms): write=' + result.timing.writeMs + ', pdf=' + result.timing.pdfMs + ', email=' + result.timing.emailMs + ', total=' + result.timing.totalMs);
        }
      } else if (result && result.error) {
        Logger.log('Row ' + (i + 2) + ' error: ' + result.error);
        errorsCount++;
        try {
          templateSheet.getRange(statusCell).setValue('Row ' + (i + 2) + ' error: ' + result.error + ' (' + emailsSentCount + '/' + data.length + ')');
          SpreadsheetApp.flush();
        } catch (e) {}
      }

      // Adaptive throttling with hysteresis
      if (result && result.saw429) {
        throttlingActive = true;
        consecutiveCleanRows = 0;
      } else {
        consecutiveCleanRows++;
        if (throttlingActive && consecutiveCleanRows >= hysteresisThreshold) {
          throttlingActive = false;
          consecutiveCleanRows = 0;
        }
      }
      // Update live status
      try { templateSheet.getRange(statusCell).setValue(emailsSentCount + '/' + data.length + ' emails sent'); SpreadsheetApp.flush(); } catch (e) {}
      if (result && result.error && result.error.indexOf('Email quota reached') !== -1) {
        // Stop early if daily email quota was reached
        emailQuotaReached = true;
        try {
          templateSheet.getRange(statusCell).setValue('Stopped: email quota reached (' + emailsSentCount + '/' + data.length + ')');
          if (errorsCount > 0) {
            templateSheet.getRange(totalErrorsCell).setValue(errorsCount + '/' + data.length + ' emails');
          } else {
            templateSheet.getRange(totalErrorsCell).setValue('');
          }
          SpreadsheetApp.flush();
        } catch (e) {}
        break;
      }

      // Conditional throttling between rows: only after first 429 was observed
      if (runMode === 'live' && throttlingActive) {
        const jitter = Math.floor(Math.random() * perRowJitterMs);
        Utilities.sleep(perRowDelayMs + jitter);
        if ((i + 1) % cooldownEveryRows === 0) {
          Utilities.sleep(cooldownMs);
        }
      }
      if (runMode === 'dry') break;
    }

    // Cleanup reusable temp sheet
    try { spreadsheet.deleteSheet(reusableTempSheet); } catch (e) {}

    const parts = [];
    parts.push(emailsSentCount + ' payslip(s) generated and emailed (' + runMode + ').');
    parts.push('Errors: ' + errorsCount);
    if (emailQuotaReached) parts.push('Stopped early: email quota reached for today.');
    if (runMode === 'dry') {
      parts.push('Test email has been sent to your email address.');
    }
    if (skippedMissingEmail.length) {
      if (skippedMissingEmail.length === 1) {
        parts.push('Skipped (missing email): row ' + skippedMissingEmail[0]);
      } else {
        parts.push('Skipped (missing email): rows ' + skippedMissingEmail.join(', '));
      }
    }
    const totalMs = Date.now() - tRunStart;
    Logger.log('Run total time (ms): ' + totalMs);
    ui.alert('Done', parts.join('\n'), ui.ButtonSet.OK);
    try {
      const finalMsg = emailQuotaReached
        ? ('Stopped (quota): ' + emailsSentCount + '/' + data.length + ' (errors: ' + errorsCount + ')')
        : ('Done: ' + emailsSentCount + '/' + data.length + ' (errors: ' + errorsCount + ')');
      templateSheet.getRange('D5').setValue(finalMsg);
      if (errorsCount > 0) {
        templateSheet.getRange('D6').setValue(errorsCount + '/' + data.length + ' emails');
      } else {
        templateSheet.getRange('D6').setValue('');
      }
      // error detail is already reflected in D5 during the run; no separate error cell
    } catch (e) {}

  } catch (e) {
    Logger.log('An error occurred: ' + e.message);
    ui.alert('Error', 'Unexpected error: ' + e.message, ui.ButtonSet.OK);
    // Attempt to cleanup temp sheet if it exists
    try {
      if (reusableTempSheet) {
        spreadsheet.deleteSheet(reusableTempSheet);
      } else if (reusableTempName) {
        const tmp = spreadsheet.getSheetByName(reusableTempName);
        if (tmp) spreadsheet.deleteSheet(tmp);
      }
    } catch (e2) {}
  }
}

function processPayslipForEmployee(spreadsheet, templateSheet, employeeDataRow, a1Map, overrideEmail, colIndexById, precomputedPdfBounds, reusableTempSheet, bearerToken, precomputedPdfUrl, persistentWriteCache, pdfScale, useMailApp) {
  function v(id) { const i = colIndexById[id]; return (i != null ? employeeDataRow[i] : ''); }

  const paySlipPeriod = v('paySlipPeriod');
  const period = v('period');
  const employeeId = v('employeeId');
  const employeeName = v('employeeName');
  const personalNumber = v('personalNumber');
  const emailAddress = String(v('emailAddress') || '').trim();

  const workedHours = v('workedHours');
  const salaryGross = v('salaryGross');
  const sickLeave = v('sickLeave');
  const sickLeaveHourlyGross = v('sickLeaveHourlyGross');
  const nightHours = v('nightHours');
  const nightHoursHourlyGross = v('nightHoursHourlyGross');
  const nightHoursRate = v('nightHoursRate');
  const paidLeave = v('paidLeave');
  const paidLeaveHourlyGross = v('paidLeaveHourlyGross');
  const otherAbsences = v('otherAbsences');
  const otherAbsencesHourlyGross = v('otherAbsencesHourlyGross');
  const overtimePublicHoliday = v('overtimePublicHoliday');
  const overtimePublicHolidayHourlyGross = v('overtimePublicHolidayHourlyGross');
  const overtimePublicHolidayRate = v('overtimePublicHolidayRate');
  const relocationCompensationGross = v('relocationCompensationGross');
  const exchangeRate = v('exchangeRate');
  const bonusAmountGross = v('bonusAmountGross');
  const percent = v('percent');
  const additionalBonusGross = v('additionalBonusGross');
  const compensation = v('compensation');
  const accruedNet = v('accruedNet');
  const pension = v('pension');
  const withheldTax = v('withheldTax');
  const accruedGross = v('accruedGross');

  // Use reusable temp sheet when provided; else fallback to per-row copy/rename/delete
  let createdTemporary = false;
  let tempName = '';
  let tempSheet;
  if (reusableTempSheet) {
    tempSheet = reusableTempSheet;
  } else {
    const baseTempName = 'Payslip_' + employeeName;
    tempSheet = spreadsheet.getSheetByName(baseTempName);
    if (tempSheet) spreadsheet.deleteSheet(tempSheet);
    tempSheet = templateSheet.copyTo(spreadsheet);
    tempName = baseTempName;
    try {
      tempSheet.setName(tempName);
    } catch (e) {
      tempName = employeeId ? (baseTempName + '-' + employeeId) : (baseTempName + '-' + Date.now());
      tempSheet.setName(tempName);
    }
    createdTemporary = true;
  }

  // Reuse cached ranges when using a persistent temp sheet; otherwise use a fresh cache per row
  const writeCache = createdTemporary ? {} : (persistentWriteCache || {});
  const spreadsheetId = spreadsheet.getId();
  const tStart = Date.now();
  const batchWrites = [
    { a1: (a1Map.paySlipPeriod || a1Map.period), value: paySlipPeriod },
    { a1: a1Map.period, value: period },
    { a1: a1Map.employeeName, value: employeeName },
    { a1: a1Map.employeeId, value: employeeId },
    { a1: a1Map.personalNumber, value: personalNumber },
    { a1: a1Map.emailAddress, value: emailAddress },

    { a1: a1Map.accruedNet, value: accruedNet },
    { a1: a1Map.pension, value: pension },
    { a1: a1Map.withheldTax, value: withheldTax },
    { a1: a1Map.accruedGross, value: accruedGross },

    { a1: (a1Map.salaryGross || a1Map.salary), value: salaryGross },
    { a1: a1Map.bonusAmountGross, value: bonusAmountGross },
    { a1: a1Map.percent, value: percent },
    { a1: a1Map.additionalBonusGross, value: additionalBonusGross },
    { a1: a1Map.sickLeave, value: sickLeave },
    { a1: a1Map.sickLeaveHourlyGross, value: sickLeaveHourlyGross },
    { a1: a1Map.paidLeave, value: paidLeave },
    { a1: a1Map.paidLeaveHourlyGross, value: paidLeaveHourlyGross },
    { a1: a1Map.otherAbsences, value: otherAbsences },
    { a1: a1Map.otherAbsencesHourlyGross, value: otherAbsencesHourlyGross },
    { a1: a1Map.overtimePublicHoliday, value: overtimePublicHoliday },
    { a1: a1Map.overtimePublicHolidayHourlyGross, value: overtimePublicHolidayHourlyGross },
    { a1: a1Map.overtimePublicHolidayRate, value: overtimePublicHolidayRate },
    { a1: a1Map.nightHours, value: nightHours },
    { a1: a1Map.nightHoursHourlyGross, value: nightHoursHourlyGross },
    { a1: a1Map.nightHoursRate, value: nightHoursRate },
    { a1: a1Map.relocationCompensationGross, value: relocationCompensationGross },
    { a1: a1Map.exchangeRate, value: exchangeRate },
    { a1: a1Map.compensation, value: compensation }
  ].filter(function(w){ return w && w.a1; });

  const didBatch = tryBatchWriteValues_(spreadsheetId, tempSheet, writeCache, batchWrites);
  Logger.log('Batch path used: ' + didBatch + '; writes sent: ' + batchWrites.length);
  if (!didBatch) {
    for (let bi = 0; bi < batchWrites.length; bi++) {
      const w = batchWrites[bi];
      setValueFast_(tempSheet, writeCache, w.a1, w.value);
    }
    // Only flush when using SpreadsheetApp writes; batch API does not require flush
    SpreadsheetApp.flush();
  }
  const tAfterWrite = Date.now();

  // Compute print area using precomputed bounds when available
  // Preferred: named range PAYSLIP_PDF_AREA on the template (covers the whole table, including borders)
  // Fallback: export entire used sheet area (simple, stable)
  let topRow, leftCol, lastRow, lastCol;
  if (precomputedPdfBounds) {
    topRow = precomputedPdfBounds.topRow;
    leftCol = precomputedPdfBounds.leftCol;
    lastRow = precomputedPdfBounds.lastRow;
    lastCol = precomputedPdfBounds.lastCol;
  } else {
    const parent = tempSheet.getParent();
    const pdfArea = parent.getRangeByName('PAYSLIP_PDF_AREA') || parent.getRangeByName('PS_PDF_AREA');
    if (pdfArea) {
      topRow = pdfArea.getRow();
      leftCol = pdfArea.getColumn();
      lastRow = topRow + pdfArea.getNumRows() - 1;
      lastCol = leftCol + pdfArea.getNumColumns() - 1;
    } else {
      topRow = 1;
      leftCol = 1;
      lastRow = tempSheet.getLastRow();
      lastCol = tempSheet.getLastColumn();
    }
  }
  const tempSheetId = tempSheet.getSheetId();
  const r1Pad = Math.max(1, topRow - 1); // include outer border
  const c1Pad = Math.max(1, leftCol - 1); // include outer border
  const r2Pad = lastRow + 1; // include outer border
  const c2Pad = lastCol + 1; // include outer border
  const effectiveScale = (typeof pdfScale === 'number' && pdfScale >= 1 && pdfScale <= 4) ? pdfScale : 4;
  const pdfUrl = (precomputedPdfUrl && !createdTemporary) ? precomputedPdfUrl : (
    'https://docs.google.com/spreadsheets/d/' + spreadsheetId +
    '/export?exportFormat=pdf&gid=' + tempSheetId +
    '&portrait=true' +
    '&scale=' + effectiveScale +
    '&size=A4' +
    '&gridlines=false' +
    '&printtitle=false' +
    '&sheetnames=false' +
    '&pagenum=UNDEFINED' +
    '&top_margin=0.25' +
    '&bottom_margin=0.25' +
    '&left_margin=0.25' +
    '&right_margin=0.25' +
    '&r1=' + r1Pad + '&c1=' + c1Pad + '&r2=' + r2Pad + '&c2=' + c2Pad
  );

  let pdfBlob;
  try {
    const token = bearerToken || ScriptApp.getOAuthToken();
    const pdfResult = fetchPdfWithRetry_(pdfUrl, token, 'Payslip_' + employeeName + '.pdf');
    if (!pdfResult.ok) {
      if (createdTemporary) { try { spreadsheet.deleteSheet(tempSheet); } catch (e2) {} }
      return { ok: false, error: pdfResult.error, saw429: !!pdfResult.saw429 };
    }
    pdfBlob = pdfResult.blob;
    // Adaptive cooldown if we saw 429s during retry
    if (pdfResult.saw429) {
      Utilities.sleep(4000 + Math.floor(Math.random() * 1000));
    }
  } catch (e) {
    if (createdTemporary) { try { spreadsheet.deleteSheet(tempSheet); } catch (e2) {} }
    return { ok: false, error: 'PDF fetch failed: ' + e.message, saw429: false };
  }
  const tAfterPdf = Date.now();

  const recipient = overrideEmail || emailAddress;
  // Email subject: employer + ' - Your Payslip for ' + period
  let employerForSubject = sanitizeEmployerName_(String(v('employer') || '').trim());
  if (!employerForSubject && a1Map.employer) {
    employerForSubject = sanitizeEmployerName_(String(tempSheet.getRange(a1Map.employer).getDisplayValue() || '').trim());
  }
  const emailSubject = (employerForSubject ? (employerForSubject + ' - ') : '') + 'Your Payslip for ' + period;
  const emailBody =
    'Dear ' + employeeName + ',\n\n' +
    'Your payslip for this period is attached.\n\n' +
    'If you have any questions, please feel to reach out.\n\n' +
    'Best regards,\n' +
    'The Payroll Team\n';

  try {
    if (useMailApp) {
      MailApp.sendEmail({
        to: recipient,
        subject: emailSubject,
        body: emailBody,
        attachments: [pdfBlob],
        name: 'Payslip Automation'
      });
    } else {
      GmailApp.sendEmail(recipient, emailSubject, emailBody, {
        attachments: [pdfBlob],
        name: 'Payslip Automation'
      });
    }
  } catch (e) {
    // If we hit daily email quota, surface a clear error and stop subsequent sends
    if (createdTemporary) { try { spreadsheet.deleteSheet(tempSheet); } catch (e2) {} }
    if (String(e).indexOf('Service invoked too many times for one day: email') !== -1) {
      return { ok: false, error: 'Email quota reached for today.', saw429: false };
    }
    return { ok: false, error: 'Email send failed: ' + e.message, saw429: false };
  }
  const tAfterEmail = Date.now();

  if (createdTemporary) { try { spreadsheet.deleteSheet(tempSheet); } catch (e) {} }

  return { ok: true, renameNote: '', timing: { writeMs: (tAfterWrite - tStart), pdfMs: (tAfterPdf - tAfterWrite), emailMs: (tAfterEmail - tAfterPdf), totalMs: (tAfterEmail - tStart) }, saw429: false };
}



// Email a PDF of the empty template to the logged-in user (no Drive usage)
function emailEmptyTemplatePdfToMe() {
  const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  const ui = SpreadsheetApp.getUi();
  const templateSheetName = 'Payslip Template';
  const pdfScale = 4;

  const recipient = String(Session.getActiveUser().getEmail() || '').trim();
  if (!recipient) {
    ui.alert('Error', 'No logged-in user email is available to send the PDF.', ui.ButtonSet.OK);
    return;
  }

  const templateSheet = spreadsheet.getSheetByName(templateSheetName);
  if (!templateSheet) {
    ui.alert('Error', "Sheet 'Payslip Template' not found.", ui.ButtonSet.OK);
    return;
  }

  Logger.log('emailEmptyTemplatePdfToMe: start for ' + recipient);

  // Create a temporary working copy
  let tempSheet;
  const tmpName = 'Payslip_Empty_Email_TMP_' + Date.now();
  try {
    tempSheet = templateSheet.copyTo(spreadsheet);
    try { tempSheet.setName(tmpName); } catch (e) {}
  } catch (e) {
    ui.alert('Error', 'Cannot copy template: ' + e.message, ui.ButtonSet.OK);
    return;
  }

  try {
    // Clear all mapped value fields (PS_* ranges)
    const a1Map = getTemplateA1Map_();
    const writeCache = {};
    const spreadsheetId = spreadsheet.getId();
    const keys = Object.keys(a1Map);
    Logger.log('Clearing fields count: ' + keys.length);
    const writes = keys.map(function(k){ return { a1: a1Map[k], value: '' }; });
    const didBatch = tryBatchWriteValues_(spreadsheetId, tempSheet, writeCache, writes);
    if (!didBatch) {
      for (let i = 0; i < writes.length; i++) {
        const w = writes[i];
        setValueFast_(tempSheet, writeCache, w.a1, w.value);
      }
      SpreadsheetApp.flush();
    }
    Logger.log('Fields cleared. didBatch=' + didBatch);
  } catch (e) {
    try { spreadsheet.deleteSheet(tempSheet); } catch (e2) {}
    Logger.log('emailEmptyTemplatePdfToMe: clear fields error: ' + e.message);
    ui.alert('Error', 'Failed to clear fields: ' + e.message, ui.ButtonSet.OK);
    return;
  }

  // Determine PDF area bounds
  let topRow, leftCol, lastRow, lastCol;
  try {
    const parent = templateSheet.getParent();
    const pdfArea = parent.getRangeByName('PAYSLIP_PDF_AREA') || parent.getRangeByName('PS_PDF_AREA');
    if (pdfArea) {
      topRow = pdfArea.getRow();
      leftCol = pdfArea.getColumn();
      lastRow = topRow + pdfArea.getNumRows() - 1;
      lastCol = leftCol + pdfArea.getNumColumns() - 1;
    }
  } catch (e) {}
  if (!(topRow && leftCol && lastRow && lastCol)) {
    topRow = 1;
    leftCol = 1;
    lastRow = tempSheet.getLastRow();
    lastCol = tempSheet.getLastColumn();
  }
  const spreadsheetId = spreadsheet.getId();
  const tempSheetId = tempSheet.getSheetId();
  const r1Pad = Math.max(1, topRow - 1);
  const c1Pad = Math.max(1, leftCol - 1);
  const r2Pad = lastRow + 1;
  const c2Pad = lastCol + 1;
  const pdfUrl =
    'https://docs.google.com/spreadsheets/d/' + spreadsheetId +
    '/export?exportFormat=pdf&gid=' + tempSheetId +
    '&portrait=true' +
    '&scale=' + pdfScale +
    '&size=A4' +
    '&gridlines=false' +
    '&printtitle=false' +
    '&sheetnames=false' +
    '&pagenum=UNDEFINED' +
    '&top_margin=0.25' +
    '&bottom_margin=0.25' +
    '&left_margin=0.25' +
    '&right_margin=0.25' +
    '&r1=' + r1Pad + '&c1=' + c1Pad + '&r2=' + r2Pad + '&c2=' + c2Pad;
  Logger.log('PDF URL: ' + pdfUrl);

  // Fetch PDF
  let pdfBlob;
  try {
    const token = ScriptApp.getOAuthToken();
    const pdfResult = fetchPdfWithRetry_(pdfUrl, token, 'Payslip_Template_Empty.pdf');
    if (!pdfResult.ok) {
      try { spreadsheet.deleteSheet(tempSheet); } catch (e2) {}
      Logger.log('PDF fetch failed: ' + pdfResult.error + ' (saw429=' + !!pdfResult.saw429 + ')');
      ui.alert('Error', pdfResult.error, ui.ButtonSet.OK);
      return;
    }
    pdfBlob = pdfResult.blob;
  } catch (e) {
    try { spreadsheet.deleteSheet(tempSheet); } catch (e2) {}
    ui.alert('Error', 'PDF export failed: ' + e.message, ui.ButtonSet.OK);
    return;
  }

  // Choose email API from D3 toggle (mailapp/mail → MailApp, else GmailApp)
  let useMailApp = false;
  try {
    const raw = String(templateSheet.getRange('D3').getDisplayValue()).trim().toLowerCase();
    useMailApp = (raw === 'mailapp' || raw === 'mail');
  } catch (e) {}

  const subject = 'Empty Payslip Template';
  const body = 'The empty payslip template PDF is attached.';
  try {
    if (useMailApp) {
      MailApp.sendEmail({ to: recipient, subject: subject, body: body, attachments: [pdfBlob], name: 'Payslip Automation' });
    } else {
      GmailApp.sendEmail(recipient, subject, body, { attachments: [pdfBlob], name: 'Payslip Automation' });
    }
  } catch (e) {
    try { spreadsheet.deleteSheet(tempSheet); } catch (e2) {}
    ui.alert('Error', 'Failed to send email: ' + e.message, ui.ButtonSet.OK);
    return;
  }

  try { spreadsheet.deleteSheet(tempSheet); } catch (e) {}
  ui.alert('Done', 'Empty template PDF emailed to ' + recipient + '.', ui.ButtonSet.OK);
}
