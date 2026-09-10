/**
 * Central API configuration
 * ---------------------------------------------------------
 * Change ONLY the active line below when switching environments.
 * No other frontend file needs to hard-code the API URL.
 * ---------------------------------------------------------
 */

// DEV / LIVE auto-detection
(function () {
  const host = (window.location.hostname || '').toLowerCase();
  const isLocal = host === 'localhost' || host === '127.0.0.1';
  window.API_BASE = isLocal
    ? 'http://localhost/mitre2/api'
    : 'https://api.leadstar.com.ng/mitre';
})();

// Helper: apiUrl('/students/register.php') → full URL
window.apiUrl = function (path) {
  const base = (window.API_BASE || '').replace(/\/$/, '');
  const p = path.startsWith('/') ? path : '/' + path;
  return base + p;
};
