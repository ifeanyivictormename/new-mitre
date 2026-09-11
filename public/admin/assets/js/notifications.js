/**
 * Admin Notification System
 * - Bell + badge in topbar
 * - Modal list + compose
 * - Poll every 5 min, immediate on load
 * - Pause when tab hidden; no overlapping requests
 * - Incremental fetch via since_id
 */
const AdminNotifications = {
  POLL_MS: 300000, // 5 minutes
  maxId: 0,
  unreadCount: 0,
  pollTimer: null,
  inFlight: false,
  modal: null,

  init() {
    const btn = document.getElementById('btn-notifications');
    if (!btn) return;

    const modalEl = document.getElementById('notificationsModal');
    if (modalEl && window.bootstrap) {
      this.modal = new bootstrap.Modal(modalEl);
    }

    btn.addEventListener('click', () => this.openModal());

    document.getElementById('notif-mark-all-btn')?.addEventListener('click', () => this.markAllRead());
    document.getElementById('notif-compose-btn')?.addEventListener('click', () => this.showCompose(true));
    document.getElementById('notif-compose-cancel')?.addEventListener('click', () => this.showCompose(false));
    document.getElementById('notif-compose-form')?.addEventListener('submit', (e) => {
      e.preventDefault();
      this.sendMessage();
    });

    // Visibility: pause / resume polling
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        this.stopPolling();
      } else {
        this.poll(true); // immediate on return
        this.startPolling();
      }
    });

    // Immediate check + start interval
    this.poll(true);
    this.startPolling();
  },

  startPolling() {
    this.stopPolling();
    this.pollTimer = setInterval(() => this.poll(false), this.POLL_MS);
  },

  stopPolling() {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  },

  async poll(forceFull = false) {
    if (this.inFlight || document.hidden) return;
    this.inFlight = true;
    try {
      let url = '/notifications/index.php?limit=20';
      if (!forceFull && this.maxId > 0) {
        url += `&since_id=${this.maxId}`;
      }
      const res = await Admin.get(url);
      const data = res.data || {};
      const list = data.notifications || [];
      const prevUnread = this.unreadCount;
      this.unreadCount = data.unread_count ?? this.unreadCount;

      if (data.max_id && data.max_id > this.maxId) {
        this.maxId = data.max_id;
      }

      this.updateBadge();

      // Subtle indication when new items arrive after the first load
      if (!forceFull && list.length > 0 && this.unreadCount > prevUnread) {
        this.flashBell();
        if (typeof Admin.toast === 'function') {
          Admin.toast(`You have ${this.unreadCount} unread notification${this.unreadCount === 1 ? '' : 's'}`, 'info');
        }
      }

      // If modal is open, refresh list
      const modalEl = document.getElementById('notificationsModal');
      if (modalEl && modalEl.classList.contains('show') && (forceFull || list.length > 0)) {
        await this.loadList();
      }
    } catch (e) {
      // Silent on poll failures – keep UI resilient
      console.warn('Notification poll failed:', e.message || e);
    } finally {
      this.inFlight = false;
    }
  },

  updateBadge() {
    const badge = document.getElementById('notif-badge');
    if (!badge) return;
    const n = this.unreadCount || 0;
    badge.textContent = n > 99 ? '99+' : String(n);
    badge.classList.toggle('d-none', n <= 0);
  },

  flashBell() {
    const btn = document.getElementById('btn-notifications');
    if (!btn) return;
    btn.classList.add('notif-bell-flash');
    setTimeout(() => btn.classList.remove('notif-bell-flash'), 1200);
  },

  openModal() {
    this.showCompose(false);
    this.loadList();
    this.modal?.show();
  },

  async loadList() {
    const container = document.getElementById('notif-list');
    if (!container) return;
    container.innerHTML = '<div class="text-center text-muted py-4 small"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';

    try {
      const res = await Admin.get('/notifications/index.php?limit=30');
      const data = res.data || {};
      const list = data.notifications || [];
      this.unreadCount = data.unread_count ?? 0;
      if (data.max_id && data.max_id > this.maxId) this.maxId = data.max_id;
      this.updateBadge();

      if (!list.length) {
        container.innerHTML = `
          <div class="text-center text-muted py-5">
            <i class="bi bi-bell-slash fs-3 d-block mb-2 opacity-50"></i>
            <div class="small">No notifications yet</div>
          </div>`;
        return;
      }

      container.innerHTML = list.map(n => this.renderItem(n)).join('');

      container.querySelectorAll('[data-mark-read]').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const id = parseInt(btn.dataset.markRead, 10);
          if (id) this.markRead(id, btn.closest('.list-group-item'));
        });
      });

      container.querySelectorAll('[data-edit-notif]').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const id = parseInt(btn.dataset.editNotif, 10);
          if (!id) return;
          const currentMessage = btn.dataset.message || '';
          this.editMessage(id, currentMessage);
        });
      });

      container.querySelectorAll('[data-delete-notif]').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const id = parseInt(btn.dataset.deleteNotif, 10);
          if (id) this.deleteMessage(id);
        });
      });
    } catch (e) {
      container.innerHTML = `
        <div class="alert alert-danger m-3 mb-0 small">
          <i class="bi bi-exclamation-triangle me-1"></i>
          ${e.message || 'Failed to load notifications'}
        </div>`;
    }
  },

  renderItem(n) {
    const myId = Number(Admin.user?.id || 0);
    const isSender = Number(n.sender_id) === myId;
    const isRecipient = Number(n.recipient_id) === myId;
    const unread = isRecipient && !n.is_read;
    const when = this.formatWhen(n.created_at);
    const sender = n.sender_name || 'Admin';
    const msg = (n.message || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const safeMessageAttr = (n.message || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
    const senderActions = isSender ? `
      <button type="button" class="btn btn-sm btn-outline-primary flex-shrink-0" data-edit-notif="${n.id}" data-message="${safeMessageAttr}" title="Edit notification">
        <i class="bi bi-pencil"></i>
      </button>
      <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" data-delete-notif="${n.id}" title="Delete notification">
        <i class="bi bi-trash"></i>
      </button>` : '';
    const readAction = unread ? `<button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" data-mark-read="${n.id}" title="Mark as read"><i class="bi bi-check2"></i></button>` : '';
    return `
      <div class="list-group-item notif-item ${unread ? 'notif-unread' : ''}" data-id="${n.id}">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex align-items-center gap-2 mb-1">
              <strong class="small text-truncate">${sender}</strong>
              ${isSender ? '<span class="badge text-bg-light border">Sent by you</span>' : ''}
              ${unread ? '<span class="badge bg-primary rounded-pill" style="font-size:.6rem">New</span>' : ''}
            </div>
            <div class="small text-break">${msg}</div>
            <div class="text-muted mt-1" style="font-size:.7rem">${when}</div>
          </div>
          <div class="d-flex align-items-start gap-1 flex-shrink-0">
            ${readAction}
            ${senderActions}
          </div>
        </div>
      </div>`;
  },

  formatWhen(dt) {
    if (!dt) return '';
    try {
      const d = new Date(dt);
      const now = new Date();
      const diff = (now - d) / 1000;
      if (diff < 60) return 'Just now';
      if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
      if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
      return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
    } catch {
      return dt;
    }
  },

  async markRead(id, el) {
    try {
      await Admin.post('/notifications/index.php?action=mark_read', { id });
      if (el) {
        el.classList.remove('notif-unread');
        el.querySelector('[data-mark-read]')?.remove();
        el.querySelector('.badge.bg-primary')?.remove();
      }
      this.unreadCount = Math.max(0, this.unreadCount - 1);
      this.updateBadge();
    } catch (e) {
      Admin.toast(e.message || 'Could not mark as read', 'error');
    }
  },

  async markAllRead() {
    try {
      await Admin.post('/notifications/index.php?action=mark_all_read', {});
      this.unreadCount = 0;
      this.updateBadge();
      await this.loadList();
      Admin.toast('All notifications marked as read');
    } catch (e) {
      Admin.toast(e.message || 'Could not mark all as read', 'error');
    }
  },

  async editMessage(id, currentMessage) {
    const updated = window.prompt('Edit notification message:', currentMessage || '');
    if (updated === null) return;

    const message = updated.trim();
    if (!message) {
      Admin.toast('Message cannot be empty', 'error');
      return;
    }

    try {
      await Admin.post('/notifications/index.php?action=edit', { id, message });
      Admin.toast('Notification updated');
      await this.loadList();
    } catch (e) {
      Admin.toast(e.message || 'Failed to update notification', 'error');
    }
  },

  async deleteMessage(id) {
    const ok = window.confirm('Delete this notification? This cannot be undone.');
    if (!ok) return;

    try {
      await Admin.post('/notifications/index.php?action=delete', { id });
      Admin.toast('Notification deleted');
      await this.loadList();
    } catch (e) {
      Admin.toast(e.message || 'Failed to delete notification', 'error');
    }
  },

  showCompose(show) {
    const panel = document.getElementById('notif-compose-panel');
    if (!panel) return;
    panel.classList.toggle('d-none', !show);
    if (show) {
      document.getElementById('notif-message').value = '';
    }
  },

  async sendMessage() {
    const message = (document.getElementById('notif-message')?.value || '').trim();
    if (!message) {
      Admin.toast('Enter a message', 'error');
      return;
    }
    const btn = document.getElementById('notif-send-btn');
    if (btn) btn.disabled = true;
    try {
      await Admin.post('/notifications/index.php', {
        message
      });
      Admin.toast('Notification sent to all admins');
      this.showCompose(false);
    } catch (e) {
      Admin.toast(e.message || 'Failed to send', 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  }
};
