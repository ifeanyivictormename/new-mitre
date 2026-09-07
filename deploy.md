# Deployment Checklist

## Local (what you use now)
- Frontend: `http://localhost/mitre2/public/`
- API: `http://localhost/mitre2/api/`
- Already configured in `config.js` and `api/config/config.php`

## Live – two hosts (recommended)

### 1. Database
```bash
CREATE DATABASE training_school CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
mysql -u USER -p training_school < sql/schema.sql
```

### 2. Backend
Point `https://api.yourdomain.com` document root at the `api/` folder.

Edit `api/config/config.php`:
```php
define('APP_URL', 'https://api.yourdomain.com');
define('FRONTEND_URL', 'https://yourdomain.com');   // origin only, no path
// set DB_* credentials
// ini_set('display_errors', 0);
```

Make `api/uploads/` writable by the web server.

### 3. Frontend
Point `https://yourdomain.com` document root at the `public/` folder.

Edit **only** `public/assets/js/config.js`:
```js
window.API_BASE = 'https://api.yourdomain.com';
```

### 4. Security
- [ ] Change default admin password
- [ ] HTTPS on both hosts
- [ ] `display_errors = 0`
- [ ] `FRONTEND_URL` set to your real frontend origin
- [ ] Database backups

Default admin: `admin@example.com` / `admin123`
