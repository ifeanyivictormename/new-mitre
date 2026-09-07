/**
 * Central API configuration
 * ---------------------------------------------------------
 * Change ONLY the active line below when switching environments.
 * No other frontend file needs to hard-code the API URL.
 * ---------------------------------------------------------
 */

// DEV (XAMPP / local folder under htdocs)
window.API_BASE = 'http://localhost/mitre2/api';

// LIVE – comment the line above and uncomment this when you deploy
// window.API_BASE = 'https://api.yourdomain.com';

// Helper: apiUrl('/students/register.php') → full URL
window.apiUrl = function (path) {
  const base = (window.API_BASE || '').replace(/\/$/, '');
  const p = path.startsWith('/') ? path : '/' + path;
  return base + p;
};
