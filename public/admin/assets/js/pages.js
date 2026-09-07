/**
 * Admin Pages – render functions for each module
 */
const Pages = {
  content: null,
  PAGE_SIZE: 15,

  // -------------------- TABLE HELPERS (pagination + export) --------------------
  /**
   * Render a page of rows into tbody and update pager controls.
   * @param {object} opts
   * @param {string} opts.key          – unique key for this table state (e.g. 'apps')
   * @param {Array}  opts.rows         – full dataset (already filtered)
   * @param {function} opts.rowHtml    – (row) => html string
   * @param {string} opts.tbodyId
   * @param {string} opts.pagerId
   * @param {number} opts.colspan
   * @param {string} [opts.emptyText]
   * @param {number} [opts.page]       – 1-based; defaults to stored or 1
   */
  renderPagedTable(opts) {
    const key = opts.key;
    const rows = opts.rows || [];
    const pageSize = this.PAGE_SIZE;
    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / pageSize) || 1);

    if (!this._pager) this._pager = {};
    let page = opts.page != null ? opts.page : (this._pager[key]?.page || 1);
    page = Math.min(Math.max(1, page), totalPages);
    this._pager[key] = { page, total, totalPages, rows };

    const start = (page - 1) * pageSize;
    const slice = rows.slice(start, start + pageSize);
    const tbody = document.getElementById(opts.tbodyId);
    if (tbody) {
      if (!slice.length) {
        tbody.innerHTML = `<tr><td colspan="${opts.colspan}" class="text-center text-muted">${opts.emptyText || 'No records'}</td></tr>`;
      } else {
        tbody.innerHTML = slice.map(opts.rowHtml).join('');
      }
    }

    const pager = document.getElementById(opts.pagerId);
    if (pager) {
      const from = total === 0 ? 0 : start + 1;
      const to = Math.min(start + pageSize, total);
      pager.innerHTML = `
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-2 border-top">
          <div class="small text-muted">Showing ${from}–${to} of ${total}</div>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item ${page <= 1 ? 'disabled' : ''}">
                <button type="button" class="page-link" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>&laquo;</button>
              </li>
              ${this._pagerButtons(page, totalPages)}
              <li class="page-item ${page >= totalPages ? 'disabled' : ''}">
                <button type="button" class="page-link" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>&raquo;</button>
              </li>
            </ul>
          </nav>
        </div>`;
      pager.querySelectorAll('button[data-page]').forEach(btn => {
        btn.addEventListener('click', () => {
          const p = parseInt(btn.dataset.page, 10);
          if (!p || p < 1 || p > totalPages) return;
          this.renderPagedTable({ ...opts, page: p, rows: this._pager[key].rows });
        });
      });
    }
  },

  _pagerButtons(page, totalPages) {
    const buttons = [];
    const window = 2;
    let start = Math.max(1, page - window);
    let end = Math.min(totalPages, page + window);
    if (start > 1) {
      buttons.push(`<li class="page-item"><button type="button" class="page-link" data-page="1">1</button></li>`);
      if (start > 2) buttons.push(`<li class="page-item disabled"><span class="page-link">…</span></li>`);
    }
    for (let i = start; i <= end; i++) {
      buttons.push(`<li class="page-item ${i === page ? 'active' : ''}"><button type="button" class="page-link" data-page="${i}">${i}</button></li>`);
    }
    if (end < totalPages) {
      if (end < totalPages - 1) buttons.push(`<li class="page-item disabled"><span class="page-link">…</span></li>`);
      buttons.push(`<li class="page-item"><button type="button" class="page-link" data-page="${totalPages}">${totalPages}</button></li>`);
    }
    return buttons.join('');
  },

  /** Dropdown HTML for Excel / PDF export */
  exportButtonsHtml(tableKey) {
    return `
      <div class="dropdown">
        <button class="btn btn-sm btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Export">
          <i class="bi bi-download"></i> Export
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><button class="dropdown-item" type="button" onclick="Pages.exportTable('${tableKey}','excel')"><i class="bi bi-file-earmark-excel me-2 text-success"></i>MS Excel (.csv)</button></li>
          <li><button class="dropdown-item" type="button" onclick="Pages.exportTable('${tableKey}','pdf')"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>PDF</button></li>
        </ul>
      </div>`;
  },

  /**
   * Register export dataset for a table key.
   * @param {string} key
   * @param {{ title: string, columns: string[], rows: Array<Array|object>, getRows?: function }} meta
   */
  setExportData(key, meta) {
    if (!this._exports) this._exports = {};
    this._exports[key] = meta;
  },

  async exportTable(key, format) {
    const meta = this._exports?.[key];
    if (!meta) {
      Admin.toast('Nothing to export yet', 'error');
      return;
    }
    const rows = typeof meta.getRows === 'function' ? meta.getRows() : (meta.rows || []);
    if (!rows.length) {
      Admin.toast('No rows to export', 'error');
      return;
    }
    const columns = meta.columns || [];
    const title = meta.title || key;
    // Normalise to array-of-arrays
    const matrix = rows.map(r => {
      if (Array.isArray(r)) return r;
      return columns.map(c => {
        const k = typeof c === 'object' ? c.key : c;
        let v = r[k];
        if (v == null) return '';
        return String(v);
      });
    });
    const headers = columns.map(c => (typeof c === 'object' ? c.label : c));

    if (format === 'excel') {
      this._downloadCsv(title, headers, matrix);
    } else if (format === 'pdf') {
      await this._downloadPdf(title, headers, matrix);
    }
  },

  _downloadCsv(title, headers, matrix) {
    const esc = (v) => {
      const s = String(v ?? '');
      if (/[",\n\r]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
      return s;
    };
    // Title/header row first, then blank line, then column headers + data
    const lines = [
      [esc(title)].join(','),
      '',
      headers.map(esc).join(',')
    ].concat(matrix.map(row => row.map(esc).join(',')));
    // UTF-8 BOM so Excel recognises encoding
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `${title.replace(/[^\w\-]+/g, '_')}_${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    Admin.toast('Excel file downloaded');
  },

  async _loadScript(src) {
    if (document.querySelector(`script[src="${src}"]`)) {
      // wait briefly if still loading
      await new Promise(r => setTimeout(r, 50));
      return;
    }
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = () => reject(new Error('Failed to load ' + src));
      document.head.appendChild(s);
    });
  },

  async _downloadPdf(title, headers, matrix) {
    try {
      Admin.toast('Preparing PDF…');
      await this._loadScript('https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js');
      await this._loadScript('https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.4/dist/jspdf.plugin.autotable.min.js');
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF({ orientation: headers.length > 6 ? 'landscape' : 'portrait', unit: 'mm', format: 'a4' });
      doc.setFontSize(14);
      doc.text(title, 14, 15);
      doc.setFontSize(9);
      doc.setTextColor(100);
      doc.text(`Generated ${new Date().toLocaleString()} · MITRE Admin`, 14, 21);
      doc.autoTable({
        startY: 26,
        head: [headers],
        body: matrix,
        styles: { fontSize: 8, cellPadding: 2 },
        headStyles: { fillColor: [30, 41, 59], textColor: 255 },
        alternateRowStyles: { fillColor: [248, 250, 252] },
        margin: { left: 10, right: 10 }
      });
      doc.save(`${title.replace(/[^\w\-]+/g, '_')}_${new Date().toISOString().slice(0, 10)}.pdf`);
      Admin.toast('PDF downloaded');
    } catch (e) {
      Admin.toast(e.message || 'PDF export failed', 'error');
    }
  },

  async load(page, section) {
    this.content = document.getElementById('content');
    this.content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    try {
      // Zones / Sets / Users live under Site Settings (super_admin).
      // Conclaves are available to all admins via main sidebar.
      if (['zones', 'sets', 'users'].includes(page)) {
        if (!Admin.user || Admin.user.role !== 'super_admin') {
          this.content.innerHTML = `<div class="alert alert-danger">Access denied. Super Admin only (Site Settings).</div>`;
          return;
        }
        this._settingsSection = page;
        page = 'settings';
      }
      if (page === 'settings' && section) {
        this._settingsSection = section;
      }
      if (this[page]) await this[page]();
      else this.content.innerHTML = `<div class="alert alert-warning">Page "${page}" not implemented yet.</div>`;
    } catch (e) {
      this.content.innerHTML = `<div class="alert alert-danger">${e.message}</div>`;
    }
  },

  // -------------------- DASHBOARD --------------------
  async dashboard() {
    const [apps, activeCountRes, conclaves, setsOv] = await Promise.all([
      Admin.get('/students/applications.php?status=pending').catch(() => ({ data: [] })),
      Admin.get('/students/index.php?statuses=admitted,active,probation&count=1').catch(() => ({ data: { count: 0 } })),
      Admin.get('/conclaves/index.php').catch(() => ({ data: [] })),
      Admin.get('/sets/index.php?overview=1').catch(() => ({ data: {} }))
    ]);

    const pending = apps.data?.length || 0;
    const activeStudents = Number(activeCountRes.data?.count || 0);
    const totalConclaves = conclaves.data?.length || 0;
    const latestConclaveSeq = (conclaves.data || []).reduce((max, c) => {
      const seq = Number(c?.sequence || 0);
      return seq > max ? seq : max;
    }, 0);
    const ov = setsOv.data || {};
    const seniorProg = ov.senior?.effective_conclave ?? ov.senior?.max_conclave ?? ov.senior?.current_sequence ?? latestConclaveSeq;
    const juniorProg = ov.junior?.effective_conclave ?? ov.junior?.max_conclave ?? ov.junior?.current_sequence ?? latestConclaveSeq;
    const seniorLabel = ov.senior ? `Set ${ov.senior.set_number} (conclave ~${seniorProg})` : `Conclave ~${latestConclaveSeq || 0}`;
    const juniorLabel = ov.junior ? `Set ${ov.junior.set_number} (conclave ~${juniorProg})` : `Conclave ~${latestConclaveSeq || 0}`;

    this.content.innerHTML = `
      <div class="row g-3 mb-4">
        <div class="col-md-3">
          <div class="card stat-card">
            <div class="card-body">
              <div class="text-muted small">Pending Applications</div>
              <div class="stat-value text-primary">${pending}</div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card stat-card">
            <div class="card-body">
              <div class="text-muted small">Active Students</div>
              <div class="stat-value text-success">${activeStudents}</div>
              <div class="small text-muted mt-1">Conclaves: ${totalConclaves}</div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card stat-card">
            <div class="card-body">
              <div class="text-muted small">Senior Set</div>
              <div class="fs-6 fw-semibold text-primary">${seniorLabel}</div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card stat-card">
            <div class="card-body">
              <div class="text-muted small">Junior Set</div>
              <div class="fs-6 fw-semibold text-info">${juniorLabel}</div>
            </div>
          </div>
        </div>
      </div>
      <div class="card table-card">
        <div class="card-header bg-white fw-semibold">Quick Actions</div>
        <div class="card-body d-flex flex-wrap gap-2">
          <button class="btn btn-outline-primary btn-sm" onclick="document.querySelector('[data-page=applications]').click()">Review Applications</button>
          <button class="btn btn-outline-primary btn-sm" onclick="document.querySelector('[data-page=students]').click()">View Students</button>
          <button class="btn btn-outline-primary btn-sm" onclick="document.querySelector('[data-page=probation]').click()">Check Probation</button>
          ${Admin.user && Admin.user.role === 'super_admin' ? `
          <button class="btn btn-outline-secondary btn-sm" onclick="Pages.load('settings','sets')">Manage Sets</button>
          <button class="btn btn-outline-secondary btn-sm" onclick="Pages.load('settings','conclaves')">Manage Conclaves</button>
          <button class="btn btn-outline-secondary btn-sm" onclick="Pages.load('settings','zones')">Manage Zones</button>
          <button class="btn btn-outline-secondary btn-sm" onclick="Pages.load('settings','users')">Manage Users</button>
          ` : ''}
        </div>
      </div>
    `;
  },

  // -------------------- APPLICATIONS --------------------
  async applications() {
    const res = await Admin.get('/students/applications.php?status=pending');
    const rows = res.data || [];
    this._appRows = rows;
    this._appFiltered = rows;

    const rowHtml = (r) => `
      <tr>
        <td>${r.first_name} ${r.last_name}</td>
        <td>${r.phone}</td>
        <td>${r.zone_name}</td>
        <td>${Admin.formatDate(r.applied_at)}</td>
        <td class="text-nowrap">
          <button class="btn btn-outline-primary btn-sm me-1" onclick="Pages.viewStudent(${r.student_id}, 'applications')" title="View profile"><i class="bi bi-eye"></i></button>
          <button class="btn btn-success btn-sm me-1" onclick="Pages.reviewApp(${r.application_id},'admit')" title="Admit">Admit</button>
          <button class="btn btn-outline-danger btn-sm me-1" onclick="Pages.reviewApp(${r.application_id},'reject')" title="Reject">Reject</button>
          <button class="btn btn-outline-secondary btn-sm me-1" onclick="Pages.editApplication(${r.application_id})" title="Edit"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-outline-danger btn-sm" onclick="Pages.deleteApplication(${r.application_id})" title="Delete"><i class="bi bi-trash"></i></button>
        </td>
      </tr>`;

    const appOpts = {
      key: 'apps',
      rows,
      rowHtml,
      tbodyId: 'appTableBody',
      pagerId: 'appPager',
      colspan: 5,
      emptyText: 'No pending applications',
      page: 1
    };

    this.content.innerHTML = `
      <div class="card table-card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span class="fw-semibold">Pending Applications</span>
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <div class="search-input-wrap">
              <i class="bi bi-search"></i>
              <input type="search" id="appSearch" class="form-control form-control-sm" placeholder="Filter name, phone, zone...">
            </div>
            ${this.exportButtonsHtml('apps')}
            <button class="btn btn-sm btn-outline-secondary" onclick="Pages.load('applications')" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Name</th><th>Phone</th><th>Zone</th><th>Applied</th><th>Action</th></tr></thead>
            <tbody id="appTableBody"></tbody>
          </table>
        </div>
        <div id="appPager"></div>
      </div>
      ${this._applicationModalHtml()}
    `;

    this.renderPagedTable(appOpts);
    this.setExportData('apps', {
      title: 'Pending_Applications',
      columns: [
        { key: 'name', label: 'Name' },
        { key: 'phone', label: 'Phone' },
        { key: 'zone_name', label: 'Zone' },
        { key: 'applied_at', label: 'Applied' }
      ],
      getRows: () => (this._appFiltered || []).map(r => ({
        name: `${r.first_name} ${r.last_name}`,
        phone: r.phone,
        zone_name: r.zone_name,
        applied_at: Admin.formatDate(r.applied_at)
      }))
    });

    const searchEl = document.getElementById('appSearch');
    searchEl?.addEventListener('input', () => {
      const q = searchEl.value.trim().toLowerCase();
      const filtered = !q ? this._appRows : this._appRows.filter(r => {
        const hay = `${r.first_name} ${r.last_name} ${r.phone || ''} ${r.zone_name || ''}`.toLowerCase();
        return hay.includes(q);
      });
      this._appFiltered = filtered;
      this.renderPagedTable({ ...appOpts, rows: filtered, page: 1 });
    });
  },

  _applicationModalHtml() {
    return `
      <div class="modal fade" id="editAppModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Edit Application</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" id="editAppId">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">First name *</label>
                  <input type="text" class="form-control" id="editAppFirstName" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Last name *</label>
                  <input type="text" class="form-control" id="editAppLastName" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Other names</label>
                  <input type="text" class="form-control" id="editAppOtherNames">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Phone *</label>
                  <input type="text" class="form-control" id="editAppPhone" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Email</label>
                  <input type="email" class="form-control" id="editAppEmail">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Gender</label>
                  <select class="form-select" id="editAppGender">
                    <option value="">—</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Date of birth</label>
                  <input type="date" class="form-control" id="editAppDob">
                </div>
                <div class="col-md-8">
                  <label class="form-label">Address</label>
                  <input type="text" class="form-control" id="editAppAddress">
                </div>
                <div class="col-md-4">
                  <label class="form-label">State of origin</label>
                  <input type="text" class="form-control" id="editAppState">
                </div>
                <div class="col-md-4">
                  <label class="form-label">LGA</label>
                  <input type="text" class="form-control" id="editAppLga">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Next of kin</label>
                  <input type="text" class="form-control" id="editAppKinName">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Next of kin phone</label>
                  <input type="text" class="form-control" id="editAppKinPhone">
                </div>
                <div class="col-12">
                  <label class="form-label">Review notes</label>
                  <textarea class="form-control" id="editAppNotes" rows="2"></textarea>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" onclick="Pages.saveApplication()">Save changes</button>
            </div>
          </div>
        </div>
      </div>`;
  },

  async reviewApp(id, action) {
    if (!confirm(`Are you sure you want to ${action} this application?`)) return;
    try {
      await Admin.post('/students/applications.php', { application_id: id, action });
      Admin.toast(`Application ${action}ted successfully`);
      this.load('applications');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  async editApplication(id) {
    try {
      let row = (this._appRows || []).find(r => r.application_id == id);
      if (!row) {
        const res = await Admin.get('/students/applications.php?id=' + id);
        row = res.data;
      }
      if (!row) {
        Admin.toast('Application not found', 'error');
        return;
      }
      document.getElementById('editAppId').value = id;
      document.getElementById('editAppFirstName').value = row.first_name || '';
      document.getElementById('editAppLastName').value = row.last_name || '';
      document.getElementById('editAppOtherNames').value = row.other_names || '';
      document.getElementById('editAppPhone').value = row.phone || '';
      document.getElementById('editAppEmail').value = row.email || '';
      document.getElementById('editAppGender').value = row.gender || '';
      document.getElementById('editAppDob').value = row.date_of_birth ? String(row.date_of_birth).slice(0, 10) : '';
      document.getElementById('editAppAddress').value = row.address || '';
      document.getElementById('editAppState').value = row.state_of_origin || '';
      document.getElementById('editAppLga').value = row.lga || '';
      document.getElementById('editAppNotes').value = row.review_notes || '';
      const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editAppModal'));
      modal.show();
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  async saveApplication() {
    const id = parseInt(document.getElementById('editAppId').value, 10);
    const body = {
      application_id: id,
      first_name: document.getElementById('editAppFirstName').value.trim(),
      last_name: document.getElementById('editAppLastName').value.trim(),
      other_names: document.getElementById('editAppOtherNames').value.trim(),
      phone: document.getElementById('editAppPhone').value.trim(),
      email: document.getElementById('editAppEmail').value.trim(),
      gender: document.getElementById('editAppGender').value,
      date_of_birth: document.getElementById('editAppDob').value,
      address: document.getElementById('editAppAddress').value.trim(),
      state_of_origin: document.getElementById('editAppState').value.trim(),
      lga: document.getElementById('editAppLga').value.trim(),
      review_notes: document.getElementById('editAppNotes').value.trim()
    };
    if (!body.first_name || !body.last_name || !body.phone) {
      Admin.toast('First name, last name and phone are required', 'error');
      return;
    }
    try {
      await Admin.put('/students/applications.php', body);
      Admin.toast('Application updated');
      bootstrap.Modal.getInstance(document.getElementById('editAppModal'))?.hide();
      this.load('applications');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  async deleteApplication(id) {
    if (!confirm('Delete this application? This cannot be undone.')) return;
    const alsoStudent = confirm('Also delete the linked applicant record if they are still an applicant with no other applications?');
    try {
      const res = await Admin.delete('/students/applications.php', {
        application_id: id,
        delete_student: alsoStudent
      });
      Admin.toast(res.message || 'Application deleted');
      this.load('applications');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  // -------------------- STUDENTS --------------------
  async students() {
    // Load active sets overview so we can offer Junior / Senior tabs
    const [setsOvRes, initialRes] = await Promise.all([
      Admin.get('/sets/index.php?overview=1').catch(() => ({ data: {} })),
      Admin.get('/students/index.php').catch(() => ({ data: [] }))
    ]);
    const ov = setsOvRes.data || {};
    const juniorSet = ov.junior || null;
    const seniorSet = ov.senior || null;
    this._studentSetFilter = this._studentSetFilter || ''; // '' = all, or set_number string
    this._studentRows = initialRes.data || [];

    const rowHtml = (r) => `
      <tr>
        <td>${r.first_name} ${r.last_name}</td>
        <td>${r.reg_no || '—'}</td>
        <td>${r.phone}</td>
        <td>${r.zone_name || ''}</td>
        <td>${Admin.statusBadge(r.status)}</td>
        <td>${Admin.formatDate(r.admission_date)}</td>
        <td class="text-nowrap">
          <button class="btn btn-outline-primary btn-sm me-1" onclick="Pages.viewStudent(${r.id}, 'students')" title="View profile"><i class="bi bi-eye"></i></button>
          <button class="btn btn-outline-secondary btn-sm me-1" onclick="Pages.editStudent(${r.id})" title="Edit"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-outline-danger btn-sm" onclick="Pages.deleteStudent(${r.id}, '${(r.status || '').replace(/'/g, '')}')" title="Delete"><i class="bi bi-trash"></i></button>
        </td>
      </tr>`;

    const stuOpts = {
      key: 'students',
      rows: this._studentRows,
      rowHtml,
      tbodyId: 'studentTableBody',
      pagerId: 'studentPager',
      colspan: 7,
      emptyText: 'No students found',
      page: 1
    };

    // Build set tabs from the two current active sets
    const setTabs = [];
    setTabs.push(`<li class="nav-item"><button type="button" class="nav-link ${this._studentSetFilter === '' ? 'active' : ''}" data-set="">All sets</button></li>`);
    if (seniorSet) {
      const n = String(seniorSet.set_number);
      setTabs.push(`<li class="nav-item"><button type="button" class="nav-link ${this._studentSetFilter === n ? 'active' : ''}" data-set="${n}">Senior · Set ${n}</button></li>`);
    }
    if (juniorSet) {
      const n = String(juniorSet.set_number);
      setTabs.push(`<li class="nav-item"><button type="button" class="nav-link ${this._studentSetFilter === n ? 'active' : ''}" data-set="${n}">Junior · Set ${n}</button></li>`);
    }

    this.content.innerHTML = `
      <ul class="nav nav-tabs mb-3" id="studentSetTabs">${setTabs.join('')}</ul>
      <div class="card table-card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span class="fw-semibold">Students</span>
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <div class="search-input-wrap">
              <i class="bi bi-search"></i>
              <input type="search" id="studentSearch" class="form-control form-control-sm" placeholder="Search name, phone, reg...">
            </div>
            <select id="studentStatusFilter" class="form-select form-select-sm" style="width:auto">
              <option value="">All statuses</option>
              <option value="applicant">Applicant</option>
              <option value="admitted">Admitted</option>
              <option value="active">Active</option>
              <option value="probation">Probation</option>
              <option value="inactive">Inactive</option>
              <option value="withdrawn">Withdrawn</option>
              <option value="graduated">Graduated</option>
            </select>
            ${Admin.user?.role === 'super_admin' ? '<button class="btn btn-sm btn-outline-primary" onclick="Pages.resequenceStudentRegNos()" title="Rebuild registration numbers"><i class="bi bi-sort-numeric-down"></i> Rebuild Reg. No.</button>' : ''}
            ${this.exportButtonsHtml('students')}
            <button class="btn btn-sm btn-outline-secondary" onclick="Pages.load('students')" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Name</th><th>Reg. No.</th><th>Phone</th><th>Zone</th><th>Status</th><th>Admitted</th><th>Action</th></tr></thead>
            <tbody id="studentTableBody"></tbody>
          </table>
        </div>
        <div id="studentPager"></div>
      </div>
      ${this._studentModalHtml()}
    `;

    this.renderPagedTable(stuOpts);

    const exportTitle = (() => {
      const f = this._studentSetFilter || '';
      if (!f) return 'Students – All sets';
      if (seniorSet && String(seniorSet.set_number) === f) return `Students – Senior · Set ${f}`;
      if (juniorSet && String(juniorSet.set_number) === f) return `Students – Junior · Set ${f}`;
      return `Students – Set ${f}`;
    })();

    this.setExportData('students', {
      title: exportTitle,
      columns: [
        { key: 'name', label: 'Name' },
        { key: 'reg_no', label: 'Reg. No.' },
        { key: 'phone', label: 'Phone' },
        { key: 'zone_name', label: 'Zone' },
        { key: 'status', label: 'Status' },
        { key: 'admission_date', label: 'Admitted' }
      ],
      getRows: () => (this._studentRows || []).map(r => ({
        name: `${r.first_name} ${r.last_name}`,
        reg_no: r.reg_no || '',
        phone: r.phone,
        zone_name: r.zone_name || '',
        status: r.status,
        admission_date: Admin.formatDate(r.admission_date)
      }))
    });

    let searchTimer = null;
    const fetchStudents = async () => {
      const status = document.getElementById('studentStatusFilter')?.value || '';
      const q = document.getElementById('studentSearch')?.value.trim() || '';
      const setFilter = this._studentSetFilter || '';
      let url = '/students/index.php?';
      const params = [];
      if (status) params.push('status=' + encodeURIComponent(status));
      if (q) params.push('search=' + encodeURIComponent(q));
      if (setFilter !== '') params.push('set_number=' + encodeURIComponent(setFilter));
      url += params.join('&');
      try {
        const res2 = await Admin.get(url);
        this._studentRows = res2.data || [];
        this.renderPagedTable({ ...stuOpts, rows: this._studentRows, page: 1 });
        // Keep export title in sync with active set tab
        if (this._exports?.students) {
          const f = this._studentSetFilter || '';
          let t = 'Students – All sets';
          if (f) {
            if (seniorSet && String(seniorSet.set_number) === f) t = `Students – Senior · Set ${f}`;
            else if (juniorSet && String(juniorSet.set_number) === f) t = `Students – Junior · Set ${f}`;
            else t = `Students – Set ${f}`;
          }
          this._exports.students.title = t;
        }
      } catch (e) {
        Admin.toast(e.message, 'error');
      }
    };

    // If a set tab was already selected from a prior visit, refetch filtered
    if (this._studentSetFilter) {
      await fetchStudents();
    }

    document.getElementById('studentStatusFilter')?.addEventListener('change', fetchStudents);
    document.getElementById('studentSearch')?.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(fetchStudents, 300);
    });

    document.querySelectorAll('#studentSetTabs [data-set]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('#studentSetTabs .nav-link').forEach(l => l.classList.remove('active'));
        btn.classList.add('active');
        this._studentSetFilter = btn.dataset.set || '';
        fetchStudents();
      });
    });
  },

  async resequenceStudentRegNos() {
    if (Admin.user?.role !== 'super_admin') {
      Admin.toast('Only super admin can rebuild registration numbers', 'error');
      return;
    }
    if (!confirm('Rebuild registration numbers for all existing students now? This will renumber records by alphabetical order within each zone and set.')) return;
    try {
      const res = await Admin.get('/students/index.php?action=resequence_reg_no');
      const count = Number(res?.data?.processed_groups || 0);
      Admin.toast(`Registration numbers rebuilt for ${count} zone/set group(s).`);
      await this.load('students');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  _studentModalHtml() {
    return `
      <div class="modal fade" id="editStudentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Edit Student</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" id="editStudentId">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">First name *</label>
                  <input type="text" class="form-control" id="editStuFirstName" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Last name *</label>
                  <input type="text" class="form-control" id="editStuLastName" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Other names</label>
                  <input type="text" class="form-control" id="editStuOtherNames">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Phone *</label>
                  <input type="text" class="form-control" id="editStuPhone" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Email</label>
                  <input type="email" class="form-control" id="editStuEmail">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Gender</label>
                  <select class="form-select" id="editStuGender">
                    <option value="">—</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Date of birth</label>
                  <input type="date" class="form-control" id="editStuDob">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Status</label>
                  <select class="form-select" id="editStuStatus">
                    <option value="applicant">Applicant</option>
                    <option value="admitted">Admitted</option>
                    <option value="active">Active</option>
                    <option value="probation">Probation</option>
                    <option value="inactive">Inactive</option>
                    <option value="withdrawn">Withdrawn</option>
                    <option value="graduated">Graduated</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Current conclave (0–6)</label>
                  <input type="number" class="form-control" id="editStuConclave" min="0" max="6">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Set number</label>
                  <input type="number" class="form-control" id="editStuSet" min="0">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Reg. No.</label>
                  <input type="text" class="form-control" id="editStuRegNo">
                </div>
                <div class="col-md-8">
                  <label class="form-label">Address</label>
                  <input type="text" class="form-control" id="editStuAddress">
                </div>
                <div class="col-md-4">
                  <label class="form-label">State of origin</label>
                  <input type="text" class="form-control" id="editStuState">
                </div>
                <div class="col-md-4">
                  <label class="form-label">LGA</label>
                  <input type="text" class="form-control" id="editStuLga">
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" onclick="Pages.saveStudent()">Save changes</button>
            </div>
          </div>
        </div>
      </div>`;
  },

  async editStudent(id) {
    try {
      let row = (this._studentRows || []).find(r => r.id == id);
      if (!row || row.date_of_birth === undefined) {
        const res = await Admin.get('/students/index.php?id=' + id);
        row = res.data;
      }
      if (!row) {
        Admin.toast('Student not found', 'error');
        return;
      }
      document.getElementById('editStudentId').value = id;
      document.getElementById('editStuFirstName').value = row.first_name || '';
      document.getElementById('editStuLastName').value = row.last_name || '';
      document.getElementById('editStuOtherNames').value = row.other_names || '';
      document.getElementById('editStuPhone').value = row.phone || '';
      document.getElementById('editStuEmail').value = row.email || '';
      document.getElementById('editStuGender').value = row.gender || '';
      document.getElementById('editStuDob').value = row.date_of_birth ? String(row.date_of_birth).slice(0, 10) : '';
      document.getElementById('editStuStatus').value = row.status || 'applicant';
      document.getElementById('editStuConclave').value = row.current_conclave ?? 0;
      document.getElementById('editStuSet').value = row.set_number ?? '';
      document.getElementById('editStuRegNo').value = row.reg_no || '';
      document.getElementById('editStuAddress').value = row.address || '';
      document.getElementById('editStuState').value = row.state_of_origin || '';
      document.getElementById('editStuLga').value = row.lga || '';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('editStudentModal')).show();
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  async saveStudent() {
    const id = parseInt(document.getElementById('editStudentId').value, 10);
    const setVal = document.getElementById('editStuSet').value;
    const body = {
      id,
      first_name: document.getElementById('editStuFirstName').value.trim(),
      last_name: document.getElementById('editStuLastName').value.trim(),
      other_names: document.getElementById('editStuOtherNames').value.trim(),
      phone: document.getElementById('editStuPhone').value.trim(),
      email: document.getElementById('editStuEmail').value.trim(),
      gender: document.getElementById('editStuGender').value,
      date_of_birth: document.getElementById('editStuDob').value,
      status: document.getElementById('editStuStatus').value,
      current_conclave: parseInt(document.getElementById('editStuConclave').value, 10) || 0,
      set_number: setVal === '' ? null : parseInt(setVal, 10),
      reg_no: document.getElementById('editStuRegNo').value.trim(),
      address: document.getElementById('editStuAddress').value.trim(),
      state_of_origin: document.getElementById('editStuState').value.trim(),
      lga: document.getElementById('editStuLga').value.trim()
    };
    if (!body.first_name || !body.last_name || !body.phone) {
      Admin.toast('First name, last name and phone are required', 'error');
      return;
    }
    try {
      await Admin.put('/students/index.php', body);
      Admin.toast('Student updated');
      bootstrap.Modal.getInstance(document.getElementById('editStudentModal'))?.hide();
      this.load('students');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  async deleteStudent(id, status) {
    const safe = ['applicant', 'withdrawn', 'inactive'].includes(status);
    const msg = safe
      ? 'Permanently delete this student? Related applications, attendance and results will also be removed.'
      : 'This student is currently "' + status + '". Permanent delete will remove attendance/results too.\n\nOnly super admins can delete non-applicant records. Continue?';
    if (!confirm(msg)) return;
    if (!confirm('Final confirmation: delete student #' + id + '?')) return;
    try {
      await Admin.delete('/students/index.php', { id });
      Admin.toast('Student deleted');
      this.load('students');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  // -------------------- STUDENT PROFILE --------------------
  _photoUrl(path) {
    if (!path) return null;
    if (/^https?:\/\//i.test(path)) return path;
    // Prefer full API base (uploads live on the API host)
    const base = (typeof API_BASE !== 'undefined' && API_BASE)
      ? API_BASE.replace(/\/$/, '')
      : (window.API_BASE || '').replace(/\/$/, '');
    return `${base}/${String(path).replace(/^\//, '')}`;
  },

  _kv(label, value) {
    const display = value == null || value === '' ? '—' : String(value);
    return `
      <div class="profile-kv">
        <div class="profile-kv-label">${label}</div>
        <div class="profile-kv-value">${display}</div>
      </div>`;
  },

  async viewStudent(studentId, returnPage = 'students') {
    this.content = document.getElementById('content');
    this.content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    document.getElementById('page-title').textContent = 'Student Profile';
    document.querySelectorAll('#sidebar .nav-link').forEach(l => l.classList.remove('active'));

    try {
      const res = await Admin.get('/students/index.php?id=' + studentId);
      const s = res.data;
      if (!s) throw new Error('Student not found');

      // Optional: recent applications for this student
      let apps = [];
      try {
        const appRes = await Admin.get('/students/applications.php');
        apps = (appRes.data || []).filter(a => a.student_id == studentId);
      } catch (_) { /* ignore */ }

      const fullName = [s.first_name, s.other_names, s.last_name].filter(Boolean).join(' ');
      const photo = this._photoUrl(s.passport_photo);
      const genderLabel = s.gender ? s.gender.charAt(0).toUpperCase() + s.gender.slice(1) : '—';
      const dob = s.date_of_birth ? Admin.formatDate(s.date_of_birth) : '—';

      const avatarHtml = photo
        ? `<img src="${photo}" alt="Passport" class="profile-passport" onerror="this.classList.add('d-none'); this.nextElementSibling.classList.remove('d-none');">
           <div class="profile-avatar-placeholder d-none"><i class="bi bi-person-fill"></i></div>`
        : `<div class="profile-avatar-placeholder"><i class="bi bi-person-fill"></i></div>`;

      const appsHtml = apps.length
        ? `<div class="table-responsive"><table class="table table-sm table-hover mb-0">
            <thead><tr><th>Year</th><th>Zone</th><th>Status</th><th>Applied</th><th>Reviewed</th></tr></thead>
            <tbody>${apps.map(a => `
              <tr>
                <td>${a.application_year || '—'}</td>
                <td>${a.zone_name || '—'}</td>
                <td><span class="badge bg-secondary">${a.application_status}</span></td>
                <td>${Admin.formatDate(a.applied_at)}</td>
                <td>${Admin.formatDate(a.reviewed_at)}</td>
              </tr>`).join('')}
            </tbody></table></div>`
        : `<div class="text-muted small">No application records found.</div>`;

      this.content.innerHTML = `
        <div class="mb-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="Pages.load('${returnPage}')">
            <i class="bi bi-arrow-left"></i> Back
          </button>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="Pages.editStudent(${s.id})">
              <i class="bi bi-pencil"></i> Edit
            </button>
          </div>
        </div>

        <div class="profile-hero card table-card mb-3">
          <div class="card-body">
            <div class="d-flex flex-wrap gap-4 align-items-center">
              <div class="profile-photo-wrap">${avatarHtml}</div>
              <div class="flex-grow-1">
                <h4 class="mb-1">${fullName}</h4>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                  ${Admin.statusBadge(s.status)}
                  ${s.reg_no ? `<span class="badge bg-primary-subtle text-primary border">${s.reg_no}</span>` : ''}
                  ${s.set_number != null ? `<span class="badge bg-secondary-subtle text-secondary border">Set ${s.set_number}</span>` : ''}
                </div>
                <div class="text-muted small">
                  <i class="bi bi-geo-alt me-1"></i>${s.zone_name || '—'} ${s.zone_code ? '(' + s.zone_code + ')' : ''}
                  ${s.phone ? ` · <i class="bi bi-telephone me-1"></i>${s.phone}` : ''}
                  ${s.email ? ` · <i class="bi bi-envelope me-1"></i>${s.email}` : ''}
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card table-card h-100">
              <div class="card-header fw-semibold"><i class="bi bi-person me-2"></i>Personal</div>
              <div class="card-body profile-kv-grid">
                ${this._kv('Full name', fullName)}
                ${this._kv('Gender', genderLabel)}
                ${this._kv('Date of birth', dob)}
                ${this._kv('State of origin', s.state_of_origin)}
                ${this._kv('LGA', s.lga)}
                ${this._kv('Address', s.address)}
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card table-card h-100">
              <div class="card-header fw-semibold"><i class="bi bi-telephone me-2"></i>Contact</div>
              <div class="card-body profile-kv-grid">
                ${this._kv('Phone', s.phone)}
                ${this._kv('Email', s.email)}
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card table-card h-100">
              <div class="card-header fw-semibold"><i class="bi bi-mortarboard me-2"></i>Academic</div>
              <div class="card-body profile-kv-grid">
                ${this._kv('Status', s.status)}
                ${this._kv('Zone', (s.zone_name || '—') + (s.zone_code ? ' (' + s.zone_code + ')' : ''))}
                ${this._kv('Registration no.', s.reg_no)}
                ${this._kv('Set', s.set_number != null ? 'Set ' + s.set_number : null)}
                ${this._kv('Current conclave', s.current_conclave != null ? s.current_conclave : null)}
                ${this._kv('Admission year', s.admission_year)}
                ${this._kv('Application date', Admin.formatDate(s.application_date))}
                ${this._kv('Admission date', Admin.formatDate(s.admission_date))}
                ${this._kv('Graduation date', Admin.formatDate(s.graduation_date))}
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card table-card h-100">
              <div class="card-header fw-semibold"><i class="bi bi-file-earmark-text me-2"></i>Applications</div>
              <div class="card-body p-0">
                ${appsHtml}
              </div>
            </div>
          </div>
        </div>
      `;
    } catch (e) {
      this.content.innerHTML = `
        <button type="button" class="btn btn-outline-secondary btn-sm mb-3" onclick="Pages.load('${returnPage}')">
          <i class="bi bi-arrow-left"></i> Back
        </button>
        <div class="alert alert-danger">${e.message}</div>`;
    }
  },

  // -------------------- CONCLAVES --------------------
  // Status model: open | closed only. Admin+super can edit; only super can delete.
  async conclaves() {
    const res = await Admin.get('/conclaves/index.php');
    const rows = res.data || [];
    const isSuper = Admin.user && Admin.user.role === 'super_admin';

    let table = rows.map(r => {
      const open = r.status === 'open';
      const badge = open
        ? '<span class="badge bg-success">open</span>'
        : '<span class="badge bg-secondary">closed</span>';
      const toggleLabel = open ? 'Close' : 'Open';
      const toggleCls = open ? 'btn-outline-secondary' : 'btn-outline-success';
      const delBtn = isSuper
        ? `<button class="btn btn-outline-danger btn-sm" onclick="Pages.deleteConclave(${r.id})">Delete</button>`
        : '';
      return `
      <tr>
        <td>${r.title || 'Conclave ' + r.sequence}</td>
        <td>${r.sequence}</td>
        <td>${r.year}</td>
        <td>${badge}</td>
        <td>${Admin.formatDate(r.start_date)} – ${Admin.formatDate(r.end_date)}</td>
        <td class="text-nowrap">
          <button class="btn btn-outline-primary btn-sm me-1" onclick='Pages.showEditConclave(${JSON.stringify(r).replace(/'/g, "&#39;")})'>Edit</button>
          <button class="btn ${toggleCls} btn-sm me-1" onclick="Pages.toggleConclaveStatus(${r.id},'${open ? 'closed' : 'open'}')">${toggleLabel}</button>
          ${delBtn}
        </td>
      </tr>`;
    }).join('') || '<tr><td colspan="6" class="text-center text-muted">No conclaves yet</td></tr>';

    this.content.innerHTML = `
      <div class="d-flex justify-content-between mb-3">
        <div>
          <h6 class="mb-0">Conclaves</h6>
          <div class="small text-muted">Status is <strong>open</strong> or <strong>closed</strong> only — attendance &amp; assessments work only while open.</div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="Pages.showCreateConclave()"><i class="bi bi-plus"></i> New Conclave</button>
      </div>
      <div class="card table-card">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Title</th><th>Seq</th><th>Year</th><th>Status</th><th>Dates</th><th>Actions</th></tr></thead>
            <tbody>${table}</tbody>
          </table>
        </div>
      </div>
      <div id="conclaveFormArea"></div>
    `;
  },

  showCreateConclave() {
    this._renderConclaveForm(null);
  },

  showEditConclave(r) {
    this._renderConclaveForm(r);
  },

  _renderConclaveForm(r) {
    const isEdit = !!r;
    const area = document.getElementById('conclaveFormArea');
    if (!area) return;
    area.innerHTML = `
      <div class="card mt-3">
        <div class="card-header fw-semibold">${isEdit ? 'Edit' : 'Create'} Conclave</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-2">
              <label class="form-label">Sequence (1-6)</label>
              <input type="number" min="1" max="6" class="form-control" id="cSeq" value="${isEdit ? r.sequence : 1}">
            </div>
            <div class="col-md-2">
              <label class="form-label">Year</label>
              <input type="number" class="form-control" id="cYear" value="${isEdit ? r.year : new Date().getFullYear()}">
            </div>
            <div class="col-md-4">
              <label class="form-label">Title</label>
              <input type="text" class="form-control" id="cTitle" value="${isEdit ? (r.title || '').replace(/"/g, '&quot;') : ''}" placeholder="e.g. 2026 First Conclave">
            </div>
            <div class="col-md-2">
              <label class="form-label">Status</label>
              <select class="form-select" id="cStatus">
                <option value="open" ${isEdit && r.status === 'open' ? 'selected' : ''}>Open</option>
                <option value="closed" ${!isEdit || r.status !== 'open' ? 'selected' : ''}>Closed</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Start Date</label>
              <input type="date" class="form-control" id="cStart" value="${isEdit ? (r.start_date || '') : ''}">
            </div>
            <div class="col-md-3">
              <label class="form-label">End Date</label>
              <input type="date" class="form-control" id="cEnd" value="${isEdit ? (r.end_date || '') : ''}">
            </div>
            <div class="col-md-6">
              <label class="form-label">Notes</label>
              <input type="text" class="form-control" id="cNotes" value="${isEdit ? (r.notes || '').replace(/"/g, '&quot;') : ''}">
            </div>
          </div>
          <div class="mt-3">
            <button class="btn btn-primary" onclick="Pages.saveConclave(${isEdit ? r.id : 'null'})">Save</button>
            <button class="btn btn-outline-secondary ms-2" onclick="document.getElementById('conclaveFormArea').innerHTML=''">Cancel</button>
          </div>
        </div>
      </div>
    `;
  },

  async saveConclave(id) {
    const body = {
      sequence: parseInt(document.getElementById('cSeq').value),
      year: parseInt(document.getElementById('cYear').value),
      title: document.getElementById('cTitle').value,
      start_date: document.getElementById('cStart').value,
      end_date: document.getElementById('cEnd').value,
      status: document.getElementById('cStatus').value,
      notes: document.getElementById('cNotes').value
    };
    try {
      if (id) {
        await Admin.put(`/conclaves/index.php?id=${id}`, body);
        Admin.toast('Conclave updated');
      } else {
        await Admin.post('/conclaves/index.php', body);
        Admin.toast('Conclave created');
      }
      this.load('conclaves');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  async toggleConclaveStatus(id, status) {
    try {
      await Admin.put(`/conclaves/index.php?id=${id}`, { status });
      Admin.toast(status === 'open' ? 'Conclave opened — attendance & assessments enabled' : 'Conclave closed — recording locked');
      this.load('conclaves');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  async deleteConclave(id) {
    if (!confirm('Delete this conclave? Only possible if it has no attendance/assessment records.')) return;
    try {
      await Admin.delete(`/conclaves/index.php?id=${id}`);
      Admin.toast('Conclave deleted');
      this.load('conclaves');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  createConclave() {
    this.saveConclave(null);
  },

  // -------------------- ATTENDANCE --------------------
  async attendance() {
    const conclaves = (await Admin.get('/conclaves/index.php')).data || [];
    this._conclaveMap = {};
    conclaves.forEach(c => { this._conclaveMap[c.id] = c; });
    const options = conclaves.map(c => {
      const st = c.status === 'open' ? 'open' : 'closed';
      return `<option value="${c.id}" data-status="${st}">${c.title || 'Seq '+c.sequence} (${c.year}) — ${st}</option>`;
    }).join('');

    this.content.innerHTML = `
      <div class="card table-card">
        <div class="card-header bg-white fw-semibold">Attendance</div>
        <div class="card-body">
          <div class="alert alert-info small py-2">Recording is allowed only when the conclave status is <strong>open</strong>. Closed conclaves are viewable after load but cannot be saved until re-opened.</div>
          <div class="row g-3 align-items-end">
            <div class="col-md-4">
              <label class="form-label">Conclave</label>
              <select class="form-select" id="attConclave">${options || '<option value="">No conclaves</option>'}</select>
            </div>
            <div class="col-md-6 position-relative">
              <label class="form-label">Student (search by name)</label>
              <input type="text" class="form-control" id="attStudentName" placeholder="Type student name..." autocomplete="off">
              <input type="hidden" id="attStudentId">
              <div id="attSuggest" class="list-group position-absolute w-100 shadow-sm" style="z-index:50;display:none;max-height:220px;overflow-y:auto"></div>
            </div>
            <div class="col-md-2">
              <button class="btn btn-primary w-100" onclick="Pages.loadAttendanceForm()">Load</button>
            </div>
          </div>
          <div id="attForm" class="mt-4"></div>
        </div>
      </div>
    `;
    Admin.bindStudentSearch('attStudentName', 'attStudentId', 'attSuggest', () => {
      if (document.getElementById('attStudentId').value && document.getElementById('attConclave').value) {
        Pages.loadAttendanceForm();
      }
    });
  },

  async loadAttendanceForm() {
    const conclaveId = document.getElementById('attConclave').value;
    const studentId = document.getElementById('attStudentId').value;
    if (!conclaveId || !studentId) {
      Admin.toast('Select a conclave and choose a student from the suggestions', 'error');
      return;
    }

    const cMeta = this._conclaveMap?.[conclaveId];
    const isOpen = cMeta && cMeta.status === 'open';

    let existing = [];
    try {
      const res = await Admin.get(`/attendance/index.php?conclave_id=${conclaveId}&student_id=${studentId}`);
      existing = res.data || [];
    } catch {}

    const getVal = (day, session) => {
      const found = existing.find(e => e.day_number == day && e.session === session);
      return found ? !!found.is_present : false;
    };

    const studentName = document.getElementById('attStudentName').value;
    let html = `<p class="text-muted small mb-2">Attendance for <strong>${studentName}</strong>
      ${isOpen ? '' : ' <span class="badge bg-secondary">conclave closed — read only</span>'}</p>`;
    if (!isOpen) {
      html += `<div class="alert alert-warning py-2">This conclave is closed. Open it under <strong>Conclaves</strong> to edit attendance.</div>`;
    }
    html += '<div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Day</th><th>Morning</th><th>Evening</th></tr></thead><tbody>';
    for (let d = 1; d <= 3; d++) {
      const dis = isOpen ? '' : 'disabled';
      html += `<tr>
        <td>Day ${d}</td>
        <td><input type="checkbox" class="form-check-input att-check" data-day="${d}" data-session="morning" ${getVal(d,'morning') ? 'checked' : ''} ${dis}></td>
        <td><input type="checkbox" class="form-check-input att-check" data-day="${d}" data-session="evening" ${getVal(d,'evening') ? 'checked' : ''} ${dis}></td>
      </tr>`;
    }
    html += `</tbody></table>`;
    if (isOpen) {
      html += `<button class="btn btn-success" onclick="Pages.saveAttendance(${conclaveId},${studentId})">Save Attendance</button>`;
    }
    html += `</div>`;

    document.getElementById('attForm').innerHTML = html;
  },

  async saveAttendance(conclaveId, studentId) {
    const records = [];
    document.querySelectorAll('.att-check').forEach(cb => {
      records.push({
        day_number: parseInt(cb.dataset.day),
        session: cb.dataset.session,
        is_present: cb.checked
      });
    });
    try {
      await Admin.post('/attendance/index.php', { conclave_id: conclaveId, student_id: studentId, records });
      Admin.toast('Attendance saved');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  // -------------------- ASSESSMENTS --------------------
  async assessments() {
    const conclaves = (await Admin.get('/conclaves/index.php')).data || [];
    this._conclaveMap = {};
    conclaves.forEach(c => { this._conclaveMap[c.id] = c; });
    const options = conclaves.map(c => {
      const st = c.status === 'open' ? 'open' : 'closed';
      return `<option value="${c.id}" data-status="${st}">${c.title || 'Seq '+c.sequence} (${c.year}) — ${st}</option>`;
    }).join('');

    this.content.innerHTML = `
      <div class="card table-card">
        <div class="card-header bg-white fw-semibold">Record Assessments</div>
        <div class="card-body">
          <div class="alert alert-info small py-2">Select conclave then search student to prefill any existing marks. Saving is only allowed when the conclave is <strong>open</strong>.</div>
          <div class="row g-3 align-items-end">
            <div class="col-md-4">
              <label class="form-label">Conclave</label>
              <select class="form-select" id="asConclave">${options}</select>
            </div>
            <div class="col-md-6 position-relative">
              <label class="form-label">Student (search by name)</label>
              <input type="text" class="form-control" id="asStudentName" placeholder="Type student name..." autocomplete="off">
              <input type="hidden" id="asStudentId">
              <div id="asSuggest" class="list-group position-absolute w-100 shadow-sm" style="z-index:50;display:none;max-height:220px;overflow-y:auto"></div>
            </div>
            <div class="col-md-2">
              <button class="btn btn-outline-primary w-100" onclick="Pages.loadAssessmentScores()">Load scores</button>
            </div>
          </div>
          <div id="asClosedBanner" class="mt-3"></div>
          <div class="row g-3 mt-1">
            <div class="col-md-2"><label class="form-label">Summary (5)</label><input type="number" step="0.5" max="5" class="form-control as-field" id="asSummary"></div>
            <div class="col-md-2"><label class="form-label">Short (5)</label><input type="number" step="0.5" max="5" class="form-control as-field" id="asShort"></div>
            <div class="col-md-2"><label class="form-label">Long (10)</label><input type="number" step="0.5" max="10" class="form-control as-field" id="asLong"></div>
            <div class="col-md-2"><label class="form-label">Term (30)</label><input type="number" step="0.5" max="30" class="form-control as-field" id="asTerm"></div>
            <div class="col-md-2"><label class="form-label">Source Conclave ID</label><input type="number" class="form-control as-field" id="asSource" placeholder="for term paper"></div>
            <div class="col-md-2"><label class="form-label">Oversight (5)</label><input type="number" step="0.5" max="5" class="form-control as-field" id="asOversight" value="5"></div>
          </div>
          <button class="btn btn-primary mt-3" id="asSaveBtn" onclick="Pages.saveAssessments()">Save & Compute Result</button>
        </div>
      </div>
    `;
    Admin.bindStudentSearch('asStudentName', 'asStudentId', 'asSuggest', () => {
      // Auto-load when a student is picked and conclave is set
      if (document.getElementById('asStudentId').value && document.getElementById('asConclave').value) {
        Pages.loadAssessmentScores();
      }
    });
    document.getElementById('asConclave')?.addEventListener('change', () => Pages._applyAssessmentLock());
    this._applyAssessmentLock();
  },

  _applyAssessmentLock() {
    const cid = document.getElementById('asConclave')?.value;
    const cMeta = this._conclaveMap?.[cid];
    const isOpen = cMeta && cMeta.status === 'open';
    const banner = document.getElementById('asClosedBanner');
    const saveBtn = document.getElementById('asSaveBtn');
    document.querySelectorAll('.as-field').forEach(el => { el.disabled = !isOpen; });
    if (saveBtn) saveBtn.disabled = !isOpen;
    if (banner) {
      banner.innerHTML = isOpen
        ? ''
        : `<div class="alert alert-warning py-2 mb-0">This conclave is <strong>closed</strong>. Re-open it under Conclaves to edit scores.</div>`;
    }
  },

  async loadAssessmentScores() {
    const conclaveId = document.getElementById('asConclave').value;
    const studentId = document.getElementById('asStudentId').value;
    if (!conclaveId || !studentId) {
      Admin.toast('Select conclave and student first', 'error');
      return;
    }
    this._applyAssessmentLock();
    try {
      const [aRes, rRes] = await Promise.all([
        Admin.get(`/assessments/index.php?conclave_id=${conclaveId}&student_id=${studentId}`),
        Admin.get(`/assessments/results.php?conclave_id=${conclaveId}&student_id=${studentId}`).catch(() => ({ data: [] }))
      ]);
      const rows = aRes.data || [];
      const byType = {};
      rows.forEach(r => { byType[r.type] = r; });

      document.getElementById('asSummary').value = byType.summary ? byType.summary.score : '';
      document.getElementById('asShort').value = byType.short_paper ? byType.short_paper.score : '';
      document.getElementById('asLong').value = byType.long_paper ? byType.long_paper.score : '';
      document.getElementById('asTerm').value = byType.term_paper ? byType.term_paper.score : '';
      document.getElementById('asSource').value = byType.term_paper && byType.term_paper.source_conclave_id
        ? byType.term_paper.source_conclave_id : '';

      const result = (rRes.data || [])[0];
      if (result && result.oversight_score != null) {
        document.getElementById('asOversight').value = result.oversight_score;
      } else if (!document.getElementById('asOversight').value) {
        document.getElementById('asOversight').value = 5;
      }

      if (rows.length || result) {
        Admin.toast('Existing scores loaded');
      } else {
        Admin.toast('No scores recorded yet for this student/conclave');
      }
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  async saveAssessments() {
    const studentId = parseInt(document.getElementById('asStudentId').value);
    if (!studentId) {
      Admin.toast('Please select a student from the name suggestions', 'error');
      return;
    }
    const conclaveId = parseInt(document.getElementById('asConclave').value);
    const cMeta = this._conclaveMap?.[conclaveId];
    if (!cMeta || cMeta.status !== 'open') {
      Admin.toast('Conclave is closed. Re-open it before saving.', 'error');
      return;
    }
    const body = {
      conclave_id: conclaveId,
      student_id: studentId
    };
    const summary = document.getElementById('asSummary').value;
    const short = document.getElementById('asShort').value;
    const long = document.getElementById('asLong').value;
    const term = document.getElementById('asTerm').value;
    const source = document.getElementById('asSource').value;
    const oversight = document.getElementById('asOversight').value;

    const validateScore = (label, raw, min, max) => {
      if (raw === '') return null;
      const val = Number(raw);
      if (!Number.isFinite(val)) return `${label} must be a valid number`;
      if (val < min || val > max) return `${label} must be between ${min} and ${max}`;
      return null;
    };

    const validationError =
      validateScore('Summary score', summary, 0, 5) ||
      validateScore('Short paper score', short, 0, 5) ||
      validateScore('Long paper score', long, 0, 10) ||
      validateScore('Term paper score', term, 0, 30) ||
      validateScore('Oversight score', oversight, 0, 5);

    if (validationError) {
      Admin.toast(validationError, 'error');
      return;
    }

    if (summary !== '') body.summary = parseFloat(summary);
    if (short !== '') body.short_paper = parseFloat(short);
    if (long !== '') body.long_paper = parseFloat(long);
    if (term !== '') {
      body.term_paper = parseFloat(term);
      if (source) body.source_conclave_id = parseInt(source);
    }
    if (oversight !== '') body.oversight = parseFloat(oversight);

    try {
      await Admin.post('/assessments/index.php', body);
      Admin.toast('Assessments saved and result computed');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  // -------------------- RESULTS --------------------
  async results() {
    const conclaves = (await Admin.get('/conclaves/index.php')).data || [];
    const options = conclaves.map(c => `<option value="${c.id}">${c.title || 'Seq '+c.sequence} (${c.year})</option>`).join('');
    this._resultRows = [];

    this.content.innerHTML = `
      <div class="card table-card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span class="fw-semibold">Results</span>
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <select class="form-select form-select-sm" id="resConclave" style="width:auto">
              <option value="">All conclaves</option>
              ${options}
            </select>
            <button class="btn btn-sm btn-primary" onclick="Pages.loadResults()">Load</button>
            ${this.exportButtonsHtml('results')}
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Student</th><th>Conclave</th><th>Att (45)</th><th>Sum (5)</th><th>Short (5)</th>
                <th>Long (10)</th><th>Term (30)</th><th>Over (5)</th><th>Total</th>
              </tr>
            </thead>
            <tbody id="resultsBody"><tr><td colspan="9" class="text-center text-muted">Select a conclave and click Load</td></tr></tbody>
          </table>
        </div>
        <div id="resultsPager"></div>
      </div>
    `;

    this.setExportData('results', {
      title: 'Results',
      columns: [
        { key: 'name', label: 'Student' },
        { key: 'conclave', label: 'Conclave' },
        { key: 'attendance_score', label: 'Attendance (45)' },
        { key: 'summary_score', label: 'Summary (5)' },
        { key: 'short_paper_score', label: 'Short Paper (5)' },
        { key: 'long_paper_score', label: 'Long Paper (10)' },
        { key: 'term_paper_score', label: 'Term Paper (30)' },
        { key: 'oversight_score', label: 'Oversight (5)' },
        { key: 'total_score', label: 'Total' }
      ],
      getRows: () => (this._resultRows || []).map(r => ({
        name: `${r.first_name} ${r.last_name}`,
        conclave: r.conclave_title || ('Seq ' + r.sequence),
        attendance_score: r.attendance_score,
        summary_score: r.summary_score,
        short_paper_score: r.short_paper_score,
        long_paper_score: r.long_paper_score,
        term_paper_score: r.term_paper_score,
        oversight_score: r.oversight_score,
        total_score: r.total_score
      }))
    });
  },

  async loadResults() {
    const cid = document.getElementById('resConclave').value;
    const url = cid ? `/assessments/results.php?conclave_id=${cid}` : '/assessments/results.php';
    try {
      const res = await Admin.get(url);
      const rows = res.data || [];
      this._resultRows = rows;

      const rowHtml = (r) => `
        <tr>
          <td>${r.first_name} ${r.last_name}</td>
          <td>${r.conclave_title || 'Seq '+r.sequence}</td>
          <td>${r.attendance_score}</td>
          <td>${r.summary_score}</td>
          <td>${r.short_paper_score}</td>
          <td>${r.long_paper_score}</td>
          <td>${r.term_paper_score}</td>
          <td>${r.oversight_score}</td>
          <td><strong>${r.total_score}</strong></td>
        </tr>`;

      this.renderPagedTable({
        key: 'results',
        rows,
        rowHtml,
        tbodyId: 'resultsBody',
        pagerId: 'resultsPager',
        colspan: 9,
        emptyText: 'No results',
        page: 1
      });
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  // -------------------- PROBATION --------------------
  async probation() {
    this.content.innerHTML = `
      <div class="card table-card">
        <div class="card-header bg-white d-flex justify-content-between">
          <span class="fw-semibold">Probation Candidates</span>
          <button class="btn btn-sm btn-warning" onclick="Pages.runProbationCheck()"><i class="bi bi-search"></i> Run Check</button>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Student</th><th>Phone</th><th>Status</th><th>Zone</th><th>Details</th><th>Action</th></tr></thead>
            <tbody id="probBody"><tr><td colspan="6" class="text-center text-muted">Click "Run Check" to scan</td></tr></tbody>
          </table>
        </div>
      </div>
    `;
  },

  async runProbationCheck() {
    try {
      const res = await Admin.get('/students/status.php?action=check_probation');
      const rows = res.data || [];
      document.getElementById('probBody').innerHTML = rows.map(r => `
        <tr>
          <td>${r.full_name}</td>
          <td>${r.phone}</td>
          <td>${Admin.statusBadge(r.current_status)}</td>
          <td>${r.zone_name}</td>
          <td class="small">${r.recommendation}<br>
            ${r.missed_details.map(d => d.conclave + ': ' + 
              (d.missed_attendance ? 'no att ' : '') + 
              (d.missed_term_paper ? 'no term' : '')).join('<br>')}
          </td>
          <td>
            ${r.current_status !== 'probation' 
              ? `<button class="btn btn-warning btn-sm" onclick="Pages.changeStatus(${r.student_id},'set_probation')">Set Probation</button>` 
              : `
                <button class="btn btn-secondary btn-sm me-1" onclick="Pages.changeStatus(${r.student_id},'set_inactive')">Inactive</button>
                <button class="btn btn-danger btn-sm" onclick="Pages.changeStatus(${r.student_id},'set_withdrawn')">Withdraw</button>
              `}
          </td>
        </tr>
      `).join('') || '<tr><td colspan="6" class="text-center text-muted">No candidates found</td></tr>';
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  async changeStatus(studentId, action) {
    if (!confirm(`Confirm ${action.replace('set_', '')} for this student? SMS will be sent.`)) return;
    try {
      await Admin.post('/students/status.php', { student_id: studentId, action, send_sms: true });
      Admin.toast('Status updated & SMS logged');
      this.runProbationCheck();
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  // -------------------- ZONES --------------------
  async zones() {
    const res = await Admin.get('/zones/index.php');
    const rows = res.data || [];

    let table = rows.map(r => `
      <tr>
        <td>${r.name}</td>
        <td>${r.code}</td>
        <td>${r.description || '—'}</td>
        <td>${r.is_active == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</td>
        <td>
          <button class="btn btn-outline-primary btn-sm" onclick="Pages.editZone(${r.id},'${r.name}','${r.code}','${(r.description||'').replace(/'/g,"\\'")}','${r.is_active}')">Edit</button>
        </td>
      </tr>
    `).join('');

    this.content.innerHTML = `
      <div class="d-flex justify-content-between mb-3">
        <h6 class="mb-0">Zones</h6>
        <button class="btn btn-primary btn-sm" onclick="Pages.showZoneForm()"><i class="bi bi-plus"></i> Add Zone</button>
      </div>
      <div class="card table-card">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Name</th><th>Code</th><th>Description</th><th>Status</th><th></th></tr></thead>
            <tbody>${table}</tbody>
          </table>
        </div>
      </div>
      <div id="zoneFormArea"></div>
    `;
  },

  showZoneForm(id = null, name = '', code = '', desc = '', active = 1) {
    const isEdit = !!id;
    document.getElementById('zoneFormArea').innerHTML = `
      <div class="card mt-3">
        <div class="card-header fw-semibold">${isEdit ? 'Edit' : 'Add'} Zone</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Name</label><input class="form-control" id="zName" value="${name}"></div>
            <div class="col-md-3"><label class="form-label">Code</label><input class="form-control" id="zCode" value="${code}" ${isEdit ? 'readonly' : ''}></div>
            <div class="col-md-5"><label class="form-label">Description</label><input class="form-control" id="zDesc" value="${desc}"></div>
            ${isEdit ? `<div class="col-md-3"><label class="form-label">Active</label>
              <select class="form-select" id="zActive"><option value="1" ${active==1?'selected':''}>Yes</option><option value="0" ${active==0?'selected':''}>No</option></select>
            </div>` : ''}
          </div>
          <div class="mt-3">
            <button class="btn btn-primary" onclick="Pages.saveZone(${id || 'null'})">Save</button>
            <button class="btn btn-outline-secondary ms-2" onclick="document.getElementById('zoneFormArea').innerHTML=''">Cancel</button>
          </div>
        </div>
      </div>
    `;
  },

  editZone(id, name, code, desc, active) {
    this.showZoneForm(id, name, code, desc, active);
  },

  async saveZone(id) {
    const body = {
      name: document.getElementById('zName').value.trim(),
      code: document.getElementById('zCode').value.trim().toUpperCase(),
      description: document.getElementById('zDesc').value.trim()
    };
    try {
      if (id) {
        body.is_active = parseInt(document.getElementById('zActive').value);
        await Admin.put(`/zones/index.php?id=${id}`, body);
        Admin.toast('Zone updated');
      } else {
        await Admin.post('/zones/index.php', body);
        Admin.toast('Zone created');
      }
      this.load('settings', 'zones');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  // -------------------- SMS LOGS --------------------
  async sms() {
    const res = await Admin.get('/sms/logs.php?limit=100');
    const rows = res.data || [];

    let table = rows.map(r => `
      <tr>
        <td>${r.recipient_phone}</td>
        <td>${r.first_name ? r.first_name + ' ' + r.last_name : '—'}</td>
        <td><span class="badge bg-secondary">${r.purpose || '—'}</span></td>
        <td class="small" style="max-width:280px">${r.message}</td>
        <td>${r.status}</td>
        <td>${Admin.formatDate(r.sent_at || r.created_at)}</td>
      </tr>
    `).join('') || '<tr><td colspan="6" class="text-center text-muted">No SMS logs</td></tr>';

    this.content.innerHTML = `
      <div class="card table-card">
        <div class="card-header bg-white fw-semibold">SMS Logs</div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Phone</th><th>Student</th><th>Purpose</th><th>Message</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>${table}</tbody>
          </table>
        </div>
      </div>
    `;
  },

  // -------------------- SITE SETTINGS HUB (super_admin) --------------------
  async settings() {
    if (!Admin.user || Admin.user.role !== 'super_admin') {
      this.content.innerHTML = `<div class="alert alert-danger">Access denied. Super Admin only.</div>`;
      return;
    }

    const section = this._settingsSection || 'general';
    const tabs = [
      { id: 'general', label: 'General', icon: 'bi-sliders' },
      { id: 'zones', label: 'Zones', icon: 'bi-building' },
      { id: 'sets', label: 'Sets', icon: 'bi-layers' },
      { id: 'conclaves', label: 'Conclaves', icon: 'bi-calendar3' },
      { id: 'users', label: 'Users & Roles', icon: 'bi-person-gear' },
    ];

    const tabHtml = tabs.map(t => `
      <li class="nav-item">
        <button class="nav-link ${section === t.id ? 'active' : ''}" type="button"
          onclick="Pages._settingsSection='${t.id}'; Pages.load('settings')">
          <i class="bi ${t.icon} me-1"></i>${t.label}
        </button>
      </li>
    `).join('');

    this.content.innerHTML = `
      <div class="card table-card mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold"><i class="bi bi-gear me-1"></i> Site Settings</span>
          <span class="badge bg-primary">Super Admin only</span>
        </div>
        <div class="card-body pb-0">
          <ul class="nav nav-tabs">${tabHtml}</ul>
        </div>
      </div>
      <div id="settings-panel"><div class="text-center py-4"><div class="spinner-border text-primary"></div></div></div>
    `;

    const panel = document.getElementById('settings-panel');
    const prevContent = this.content;
    this.content = panel;

    try {
      if (section === 'general') await this.settingsGeneral();
      else if (section === 'zones') await this.zones();
      else if (section === 'sets') await this.sets();
      else if (section === 'conclaves') await this.conclaves();
      else if (section === 'users') await this.users();
      else await this.settingsGeneral();
    } finally {
      this.content = prevContent;
    }
  },

  async settingsGeneral() {
    const res = await Admin.get('/settings/index.php');
    const rows = res.data || [];
    const labels = {
      app_name: 'Application Name',
      sms_enabled: 'SMS Enabled (0 = off, 1 = on)',
      application_close_days: 'Application closes (days before resumption)',
      admission_sms_days: 'Admission SMS (days before resumption)'
    };
    const map = {};
    rows.forEach(r => { map[r.setting_key] = r; });
    const coreKeys = ['app_name', 'sms_enabled', 'application_close_days', 'admission_sms_days'];
    coreKeys.forEach(k => {
      if (!map[k]) map[k] = { setting_key: k, setting_value: '', description: labels[k] || '' };
    });

    let fields = Object.values(map).map(r => {
      const label = labels[r.setting_key] || r.setting_key;
      const isBool = r.setting_key === 'sms_enabled';
      return `
        <div class="mb-3">
          <label class="form-label fw-semibold">${label}</label>
          ${isBool ? `
            <select class="form-select setting-input" data-key="${r.setting_key}">
              <option value="0" ${r.setting_value == '0' ? 'selected' : ''}>Disabled (0)</option>
              <option value="1" ${r.setting_value == '1' ? 'selected' : ''}>Enabled (1)</option>
            </select>
          ` : `
            <input type="text" class="form-control setting-input" data-key="${r.setting_key}" value="${(r.setting_value || '').replace(/"/g, '&quot;')}">
          `}
          <div class="form-text">${r.description || ''}</div>
        </div>
      `;
    }).join('');

    this.content.innerHTML = `
      <div class="card table-card">
        <div class="card-body">
          <p class="text-muted small">Site settings are universal (no per-zone overrides). Changes apply to the entire system.</p>
          <form id="settingsForm">
            ${fields}
            <hr>
            <div class="mb-3">
              <label class="form-label fw-semibold">Add custom setting (optional)</label>
              <div class="row g-2">
                <div class="col-md-4"><input type="text" class="form-control" id="newKey" placeholder="setting_key"></div>
                <div class="col-md-4"><input type="text" class="form-control" id="newValue" placeholder="value"></div>
                <div class="col-md-4"><input type="text" class="form-control" id="newDesc" placeholder="description"></div>
              </div>
            </div>
            <button type="submit" class="btn btn-primary">Save Settings</button>
          </form>
        </div>
      </div>
    `;

    document.getElementById('settingsForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const settings = [];
      document.querySelectorAll('.setting-input').forEach(el => {
        settings.push({ key: el.dataset.key, value: el.value });
      });
      const newKey = document.getElementById('newKey').value.trim();
      if (newKey) {
        settings.push({
          key: newKey,
          value: document.getElementById('newValue').value,
          description: document.getElementById('newDesc').value
        });
      }
      try {
        await Admin.post('/settings/index.php', { settings });
        Admin.toast('Settings saved successfully');
        this._settingsSection = 'general';
        this.load('settings');
      } catch (err) {
        Admin.toast(err.message, 'error');
      }
    });
  },

  // -------------------- USERS & ROLES (super_admin) --------------------
  async users() {
    if (!Admin.user || Admin.user.role !== 'super_admin') {
      this.content.innerHTML = `<div class="alert alert-danger">Access denied.</div>`;
      return;
    }
    const [adminsRes, zonesRes] = await Promise.all([
      Admin.get('/admins/index.php'),
      Admin.get('/zones/index.php')
    ]);
    const rows = adminsRes.data || [];
    const zones = zonesRes.data || [];
    this._adminZones = zones;

    const table = rows.map(r => `
      <tr>
        <td>${r.full_name}</td>
        <td>${r.email}</td>
        <td><span class="badge ${r.role === 'super_admin' ? 'bg-primary' : 'bg-secondary'}">${r.role}</span></td>
        <td>${r.zone_name || (r.role === 'super_admin' ? 'All zones' : '—')}</td>
        <td>${r.is_active == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</td>
        <td class="small">${r.last_login ? Admin.formatDate(r.last_login) : '—'}</td>
        <td>
          <button class="btn btn-outline-primary btn-sm" onclick='Pages.showAdminForm(${JSON.stringify(r)})'>Edit</button>
          ${r.is_active == 1 && r.id !== Admin.user.id ? `<button class="btn btn-outline-danger btn-sm ms-1" onclick="Pages.deactivateAdmin(${r.id})">Deactivate</button>` : ''}
        </td>
      </tr>
    `).join('') || '<tr><td colspan="7" class="text-center text-muted">No admins</td></tr>';

    this.content.innerHTML = `
      <div class="d-flex justify-content-between mb-3">
        <div>
          <h6 class="mb-0">Users &amp; Roles</h6>
          <div class="small text-muted">Create admins and assign roles. Super admins can manage all zones; zone admins may be scoped.</div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="Pages.showAdminForm()"><i class="bi bi-plus"></i> Add User</button>
      </div>
      <div class="card table-card">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Zone</th><th>Status</th><th>Last login</th><th></th></tr></thead>
            <tbody>${table}</tbody>
          </table>
        </div>
      </div>
      <div id="adminFormArea" class="mt-3"></div>
    `;
  },

  showAdminForm(row = null) {
    const isEdit = !!(row && row.id);
    const zones = this._adminZones || [];
    const zoneOpts = zones.map(z => {
      const sel = isEdit && row.zone_id == z.id ? 'selected' : '';
      return `<option value="${z.id}" ${sel}>${z.name} (${z.code})</option>`;
    }).join('');
    const role = isEdit ? row.role : 'admin';
    const area = document.getElementById('adminFormArea');
    if (!area) return;
    area.innerHTML = `
      <div class="card">
        <div class="card-header fw-semibold">${isEdit ? 'Edit' : 'Add'} User</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full name *</label>
              <input class="form-control" id="aName" value="${isEdit ? (row.full_name || '').replace(/"/g, '&quot;') : ''}">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email *</label>
              <input type="email" class="form-control" id="aEmail" value="${isEdit ? (row.email || '') : ''}">
            </div>
            <div class="col-md-4">
              <label class="form-label">Phone</label>
              <input class="form-control" id="aPhone" value="${isEdit ? (row.phone || '') : ''}">
            </div>
            <div class="col-md-4">
              <label class="form-label">Role *</label>
              <select class="form-select" id="aRole">
                <option value="admin" ${role === 'admin' ? 'selected' : ''}>admin</option>
                <option value="super_admin" ${role === 'super_admin' ? 'selected' : ''}>super_admin</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Zone scope</label>
              <select class="form-select" id="aZone">
                <option value="">All zones / unrestricted</option>
                ${zoneOpts}
              </select>
              <div class="form-text">Ignored for super_admin (always all zones).</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">${isEdit ? 'New password (optional)' : 'Password *'}</label>
              <input type="password" class="form-control" id="aPassword" placeholder="${isEdit ? 'Leave blank to keep' : 'Min 8 characters'}">
            </div>
            ${isEdit ? `
            <div class="col-md-3">
              <label class="form-label">Active</label>
              <select class="form-select" id="aActive">
                <option value="1" ${row.is_active == 1 ? 'selected' : ''}>Yes</option>
                <option value="0" ${row.is_active == 0 ? 'selected' : ''}>No</option>
              </select>
            </div>` : ''}
          </div>
          <div class="mt-3">
            <button class="btn btn-primary" onclick="Pages.saveAdmin(${isEdit ? row.id : 'null'})">Save</button>
            <button class="btn btn-outline-secondary ms-2" onclick="document.getElementById('adminFormArea').innerHTML=''">Cancel</button>
          </div>
        </div>
      </div>
    `;
  },

  async saveAdmin(id) {
    const body = {
      full_name: document.getElementById('aName').value.trim(),
      email: document.getElementById('aEmail').value.trim(),
      phone: document.getElementById('aPhone').value.trim() || null,
      role: document.getElementById('aRole').value,
      zone_id: document.getElementById('aZone').value || null,
    };
    const pw = document.getElementById('aPassword').value;
    if (pw) body.password = pw;
    if (id) {
      body.is_active = parseInt(document.getElementById('aActive').value, 10);
    } else if (!pw) {
      Admin.toast('Password is required for new users', 'error');
      return;
    } else {
      body.password = pw;
    }
    try {
      if (id) {
        await Admin.put(`/admins/index.php?id=${id}`, body);
        Admin.toast('User updated');
      } else {
        await Admin.post('/admins/index.php', body);
        Admin.toast('User created');
      }
      this._settingsSection = 'users';
      this.load('settings');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  async deactivateAdmin(id) {
    if (!confirm('Deactivate this admin user?')) return;
    try {
      await Admin.request(`/admins/index.php?id=${id}`, { method: 'DELETE' });
      Admin.toast('User deactivated');
      this._settingsSection = 'users';
      this.load('settings');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  },

  // -------------------- SETS (global Senior / Junior) --------------------
  async sets() {
    let overview = { data: {} };
    let list = { data: [] };
    try {
      overview = await Admin.get('/sets/index.php?overview=1');
      list = await Admin.get('/sets/index.php');
    } catch (err) {
      this.content.innerHTML = `<div class="alert alert-warning">${err.message || 'Unable to load sets'}</div>`;
      return;
    }

    const ov = overview.data || {};
    const junior = ov.junior;
    const senior = ov.senior;
    const canOpen = !!ov.can_open_new;
    const nextSet = ov.next_set;
    const isSuper = !!ov.is_super || (Admin.user && Admin.user.role === 'super_admin');

    const card = (label, s, color) => {
      if (!s) {
        return `
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="text-muted small">${label}</div>
                <div class="fs-5 text-muted">None active</div>
              </div>
            </div>
          </div>`;
      }
      return `
        <div class="col-md-6">
          <div class="card border-0 shadow-sm h-100 border-start border-4 border-${color}">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="text-muted small">${label} <span class="text-muted">(all zones)</span></div>
                  <div class="fs-4 fw-bold">Set ${s.set_number}</div>
                  <div class="small text-muted mt-1">
                    Status: <span class="badge bg-secondary">${s.status}</span>
                    · Students (all zones): ${s.student_count ?? '—'}
                    · Sequence: ${s.current_sequence ?? 0}
                    · Max student conclave: ${s.max_conclave ?? 0}
                  </div>
                </div>
                ${isSuper && s.status !== 'graduated' ? `
                  <button class="btn btn-sm btn-outline-success" onclick="Pages.graduateSet(${s.id}, ${s.set_number})">
                    Graduate
                  </button>` : ''}
              </div>
            </div>
          </div>
        </div>`;
    };

    this._setsList = list.data || [];

    const rows = this._setsList.map(r => `
      <tr>
        <td>Set ${r.set_number}</td>
        <td><span class="badge bg-secondary">${r.status}</span></td>
        <td>${r.started_year || '—'}</td>
        <td>${r.current_sequence ?? 0}</td>
        <td>${r.student_count ?? 0}</td>
        <td>${r.graduated_at || '—'}</td>
        <td>
          ${isSuper ? `<button class="btn btn-sm btn-outline-secondary" onclick="Pages.openEditSetModal(${r.id})">Edit</button>` : '—'}
        </td>
      </tr>
    `).join('') || '<tr><td colspan="7" class="text-center text-muted">No sets yet</td></tr>';

    this.content.innerHTML = `
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h6 class="mb-0">Sets (Senior / Junior) — Global</h6>
          <div class="small text-muted">Same sets run in every zone. Senior always has a higher conclave than junior. New set cannot open until the senior graduates. Only super admin can open / edit / graduate.</div>
        </div>
        ${isSuper ? `
          <button class="btn btn-primary btn-sm" ${canOpen ? '' : 'disabled'} onclick="Pages.openNewSet()">
            <i class="bi bi-plus"></i> Open Set ${nextSet || ''}
          </button>` : ''}
      </div>
      ${!canOpen && ov.reason ? `<div class="alert alert-warning py-2 small">${ov.reason}</div>` : ''}
      ${!isSuper ? `<div class="alert alert-info py-2 small">View only — set management is restricted to super admin.</div>` : ''}
      <div class="row g-3 mb-4">
        ${card('Senior Set', senior, 'primary')}
        ${card('Junior Set', junior, 'info')}
      </div>
      <div class="card table-card">
        <div class="card-header bg-white fw-semibold">All Sets</div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Set</th><th>Status</th><th>Started Year</th><th>Sequence</th><th>Students</th><th>Graduated</th><th></th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>

      <!-- Edit Set Modal -->
      <div class="modal fade" id="editSetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Edit Set</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" id="editSetId">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Set number</label>
                  <input type="number" class="form-control" id="editSetNumber" min="1" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Status</label>
                  <select class="form-select" id="editSetStatus">
                    <option value="planned">planned</option>
                    <option value="active_junior">active_junior</option>
                    <option value="active_senior">active_senior</option>
                    <option value="graduated">graduated</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Started year</label>
                  <input type="number" class="form-control" id="editSetStartedYear" min="2000" max="2100" placeholder="e.g. 2025">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Current sequence (0–6)</label>
                  <input type="number" class="form-control" id="editSetSequence" min="0" max="6">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Graduated at</label>
                  <input type="date" class="form-control" id="editSetGraduatedAt">
                </div>
                <div class="col-12">
                  <label class="form-label">Notes</label>
                  <textarea class="form-control" id="editSetNotes" rows="3" placeholder="Optional notes"></textarea>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" onclick="Pages.saveEditSet()">Save changes</button>
            </div>
          </div>
        </div>
      </div>
    `;
  },

  async openNewSet() {
    if (!confirm('Open the next junior set globally (all zones)? Existing junior (if any) will become senior.')) return;
    try {
      const res = await Admin.post('/sets/index.php', {});
      Admin.toast(res.message || 'Set opened');
      this.load('settings', 'sets');
    } catch (err) {
      Admin.toast(err.message, 'error');
    }
  },

  async graduateSet(id, setNumber) {
    if (!confirm(`Graduate Set ${setNumber} globally? This unlocks opening of the next set. Optionally mark students as graduated.`)) return;
    const alsoStudents = confirm('Also mark all active students in this set (all zones) as graduated?');
    try {
      await Admin.put(`/sets/index.php?id=${id}`, {
        action: 'graduate',
        graduate_students: alsoStudents
      });
      Admin.toast(`Set ${setNumber} graduated`);
      this.load('settings', 'sets');
    } catch (err) {
      Admin.toast(err.message, 'error');
    }
  },

  openEditSetModal(id) {
    const row = (this._setsList || []).find(r => Number(r.id) === Number(id));
    if (!row) {
      Admin.toast('Set not found in list', 'error');
      return;
    }
    document.getElementById('editSetId').value = row.id;
    document.getElementById('editSetNumber').value = row.set_number ?? '';
    document.getElementById('editSetStatus').value = row.status || 'planned';
    document.getElementById('editSetStartedYear').value = row.started_year || '';
    document.getElementById('editSetSequence').value = row.current_sequence ?? 0;
    document.getElementById('editSetGraduatedAt').value = row.graduated_at ? String(row.graduated_at).substring(0, 10) : '';
    document.getElementById('editSetNotes').value = row.notes || '';

    const el = document.getElementById('editSetModal');
    if (window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(el).show();
    } else {
      // Fallback if bootstrap JS not loaded as module
      el.classList.add('show');
      el.style.display = 'block';
      el.removeAttribute('aria-hidden');
      document.body.classList.add('modal-open');
    }
  },

  async saveEditSet() {
    const id = parseInt(document.getElementById('editSetId').value, 10);
    const body = {
      set_number: parseInt(document.getElementById('editSetNumber').value, 10),
      status: document.getElementById('editSetStatus').value,
      started_year: document.getElementById('editSetStartedYear').value || null,
      current_sequence: parseInt(document.getElementById('editSetSequence').value, 10) || 0,
      graduated_at: document.getElementById('editSetGraduatedAt').value || null,
      notes: document.getElementById('editSetNotes').value || null
    };
    try {
      await Admin.put(`/sets/index.php?id=${id}`, body);
      Admin.toast('Set updated');
      const el = document.getElementById('editSetModal');
      if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el).hide();
      } else {
        el.classList.remove('show');
        el.style.display = 'none';
      }
      this.load('settings', 'sets');
    } catch (err) {
      Admin.toast(err.message, 'error');
    }
  },

  // -------------------- CHANGE PASSWORD --------------------
  showChangePassword() {
    document.getElementById('changePasswordModal')?.remove();
    const html = `
      <div class="modal fade show" id="changePasswordModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.4)">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Change Password</h5>
              <button type="button" class="btn-close" onclick="document.getElementById('changePasswordModal').remove()"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label">Current password</label>
                <input type="password" class="form-control" id="cpCurrent" autocomplete="current-password">
              </div>
              <div class="mb-3">
                <label class="form-label">New password (min 8 characters)</label>
                <input type="password" class="form-control" id="cpNew" autocomplete="new-password">
              </div>
              <div class="mb-3">
                <label class="form-label">Confirm new password</label>
                <input type="password" class="form-control" id="cpConfirm" autocomplete="new-password">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('changePasswordModal').remove()">Cancel</button>
              <button type="button" class="btn btn-primary" onclick="Pages.submitChangePassword()">Update password</button>
            </div>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
  },

  async submitChangePassword() {
    const current_password = document.getElementById('cpCurrent')?.value || '';
    const new_password = document.getElementById('cpNew')?.value || '';
    const confirm_password = document.getElementById('cpConfirm')?.value || '';
    try {
      await Admin.post('/auth/change_password.php', { current_password, new_password, confirm_password });
      document.getElementById('changePasswordModal')?.remove();
      Admin.toast('Password changed successfully');
    } catch (e) {
      Admin.toast(e.message, 'error');
    }
  }
};
