/**
 * Admin Core – API helper, auth, zone management
 */
// API_BASE comes from /assets/js/config.js (load that script first)
const API_BASE = (window.API_BASE || '').replace(/\/$/, '');
if (!API_BASE) {
  console.error('window.API_BASE is not set. Load /assets/js/config.js before admin.js');
}

const Admin = {
  user: null,
  currentZone: null,

  async request(endpoint, options = {}) {
    const { redirectOnUnauthorized = true, ...fetchOptions } = options;
    const url = endpoint.startsWith('http') ? endpoint : `${API_BASE}${endpoint}`;
    const config = {
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(fetchOptions.headers || {})
      },
      ...fetchOptions
    };
    if (config.body && typeof config.body === 'object') {
      config.body = JSON.stringify(config.body);
    }

    const res = await fetch(url, config);
    const data = await res.json().catch(() => ({}));

    if (res.status === 401 && redirectOnUnauthorized) {
      window.location.href = 'login.html';
      throw new Error('Unauthorized');
    }
    if (res.status === 401) {
      throw new Error(data.message || 'Unauthorized');
    }
    if (!data.success) {
      if (!data.message) {
        throw new Error(`The server returned an invalid response (HTTP ${res.status}). Check the Apache error log.`);
      }
      throw new Error(data.message);
    }
    return data;
  },

  async get(endpoint) {
    return this.request(endpoint, { method: 'GET' });
  },

  async post(endpoint, body) {
    return this.request(endpoint, { method: 'POST', body });
  },

  async put(endpoint, body) {
    return this.request(endpoint, { method: 'PUT', body });
  },

  async delete(endpoint, body = null) {
    const opts = { method: 'DELETE' };
    if (body != null) opts.body = body;
    return this.request(endpoint, opts);
  },

  async checkAuth() {
    try {
      const res = await this.request('/auth/me.php', {
        method: 'GET',
        redirectOnUnauthorized: false
      });
      this.user = res.data.user;
      this.currentZone = res.data.current_zone;
      return true;
    } catch {
      return false;
    }
  },

  async login(email, password) {
    const res = await this.request('/auth/admin_login.php', {
      method: 'POST',
      body: { email, password },
      redirectOnUnauthorized: false
    });

    // Confirm that the browser received the session cookie before leaving the
    // login page. This prevents an opaque redirect loop if a server or browser
    // rejects the cookie configuration.
    const authenticated = await this.checkAuth();
    if (!authenticated) {
      throw new Error('Login succeeded, but the session could not be saved. Check the site cookie settings.');
    }
    return res;
  },

  async logout() {
    await this.post('/auth/admin_logout.php', {});
    window.location.href = 'login.html';
  },

  async switchZone(zoneId) {
    const res = await this.post('/auth/switch_zone.php', { zone_id: zoneId });
    this.currentZone = res.data;
    this.updateZoneBadge();
    return res;
  },

  async loadZones() {
    const res = await this.get('/zones/index.php?active=1');
    return res.data || [];
  },

  updateZoneBadge() {
    const el = document.getElementById('current-zone-badge');
    if (!el) return;
    if (this.currentZone) {
      el.innerHTML = `<i class="bi bi-geo-alt-fill me-1"></i> <strong>${this.currentZone.name}</strong> <small class="opacity-75">(${this.currentZone.code})</small>`;
    } else {
      el.innerHTML = `<i class="bi bi-geo-alt me-1"></i> No zone selected`;
    }
  },

  updateUserInfo() {
    const nameEl = document.getElementById('admin-name');
    const roleEl = document.getElementById('admin-role');
    if (nameEl) nameEl.textContent = this.user?.full_name || 'Admin';
    if (roleEl) roleEl.textContent = this.user?.role === 'super_admin' ? 'Super Admin' : 'Admin';
  },

  async refreshApplicationsBadge() {
    const badge = document.getElementById('nav-app-count');
    if (!badge) return;
    try {
      const res = await this.get('/students/applications.php?status=pending');
      const count = Array.isArray(res.data) ? res.data.length : 0;
      badge.textContent = String(count);
      badge.classList.toggle('d-none', count <= 0);
    } catch (_) {
      // Keep UI resilient if this request fails.
      badge.classList.add('d-none');
    }
  },

  toast(message, type = 'success') {
    const container = document.getElementById('toast-container') || document.body;
    const id = 'toast-' + Date.now();
    const bg = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-primary';
    const html = `
      <div id="${id}" class="toast align-items-center text-white ${bg} border-0 show" role="alert" style="position:fixed;bottom:20px;right:20px;z-index:9999;min-width:280px">
        <div class="d-flex">
          <div class="toast-body">${message}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button>
        </div>
      </div>`;
    container.insertAdjacentHTML('beforeend', html);
    setTimeout(() => document.getElementById(id)?.remove(), 4000);
  },

  formatDate(dt) {
    if (!dt) return '—';
    return new Date(dt).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
  },

  statusBadge(status) {
    const map = {
      applicant: 'badge-status-applicant',
      admitted: 'badge-status-admitted',
      active: 'badge-status-active',
      probation: 'badge-status-probation',
      inactive: 'badge-status-inactive',
      withdrawn: 'badge-status-withdrawn',
      graduated: 'badge-status-graduated'
    };
    const cls = map[status] || 'bg-secondary';
    return `<span class="badge ${cls}">${status}</span>`;
  },

  /**
   * Autocomplete student search by name/phone.
   * @param {string} inputId   – visible text input
   * @param {string} hiddenId  – hidden input that stores student id
   * @param {string} suggestId – container for suggestion list
   * @param {function} [onPick] – optional callback after a student is selected
   */
  bindStudentSearch(inputId, hiddenId, suggestId, onPick) {
    const input = document.getElementById(inputId);
    const hidden = document.getElementById(hiddenId);
    const box = document.getElementById(suggestId);
    if (!input || !hidden || !box) return;

    let timer = null;
    let lastQ = '';

    const hide = () => {
      box.style.display = 'none';
      box.innerHTML = '';
    };

    const pick = (id, label) => {
      hidden.value = id;
      input.value = label;
      hide();
      if (typeof onPick === 'function') {
        try { onPick(id, label); } catch (e) { /* ignore */ }
      }
    };

    input.addEventListener('input', () => {
      hidden.value = '';
      const q = input.value.trim();
      if (q.length < 2) {
        hide();
        return;
      }
      clearTimeout(timer);
      timer = setTimeout(async () => {
        if (q === lastQ) return;
        lastQ = q;
        try {
          const res = await this.get(`/students/search.php?q=${encodeURIComponent(q)}`);
          const rows = res.data || [];
          if (!rows.length) {
            box.innerHTML = '<div class="list-group-item text-muted small">No matches</div>';
            box.style.display = 'block';
            return;
          }
          box.innerHTML = rows.map(r => {
            const name = r.display_name || `${r.first_name} ${r.last_name}`;
            const label = `${name} (${r.phone})`;
            const extra = [
              r.reg_no || null,
              r.set_number != null ? ('Set ' + r.set_number) : null,
              r.status
            ].filter(Boolean).join(' · ');
            return `<button type="button" class="list-group-item list-group-item-action py-2"
                      data-id="${r.id}" data-label="${String(label).replace(/"/g, '&quot;')}">
                      <div class="fw-semibold">${name}</div>
                      <div class="small text-muted">${r.phone}${extra ? ' · ' + extra : ''}</div>
                    </button>`;
          }).join('');
          box.style.display = 'block';
          box.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => pick(btn.dataset.id, btn.dataset.label));
          });
        } catch (e) {
          box.innerHTML = `<div class="list-group-item text-danger small">${e.message || 'Search failed'}</div>`;
          box.style.display = 'block';
        }
      }, 250);
    });

    input.addEventListener('blur', () => {
      // Delay so click on suggestion can register
      setTimeout(hide, 200);
    });

    input.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') hide();
    });
  }
};
