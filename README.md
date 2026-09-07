# MITRE – Minister Improvement and Training Retreat

Backend (`api/`) and frontend (`public/`) are separate so they can live on different hosts later.

```
mitre2/
├── api/                 ← Backend (PHP). On live, point api.yourdomain.com here.
│   ├── config/
│   ├── includes/
│   ├── uploads/
│   ├── auth/, students/, zones/, …
│   └── health.php
├── public/              ← Frontend only. On live, point your main site here.
│   ├── assets/js/config.js   ← SINGLE place to set the API URL
│   ├── admin/
│   ├── student/
│   ├── registration/
│   ├── alumni/
│   └── instructors/
├── sql/
│   ├── schema.sql
│   └── seed_set18_inserts.sql
├── README.md
└── deploy.md
```

## Local (XAMPP-style) – your current setup

Assume the project sits at `htdocs/mitre2/`:

| What | URL |
|------|-----|
| Frontend | `http://localhost/mitre2/public/` |
| API | `http://localhost/mitre2/api/` |
| Health check | `http://localhost/mitre2/api/health.php` |
| Admin login | `http://localhost/mitre2/public/admin/login.html` |

1. Import schema once:
   ```bash
   mysql -u root -p -e "CREATE DATABASE training_school CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p training_school < sql/schema.sql
   ```

2. Edit `api/config/config.php` if your DB user/password is not root/empty.

3. Defaults already set for local:
   - `public/assets/js/config.js` → `window.API_BASE = 'http://localhost/mitre2/api'`
   - `api/config/config.php` → `APP_URL = 'http://localhost/mitre2/api'`

4. First login: `admin@example.com` / `admin123` → change immediately.

## Going live (only two places to edit)

| File | Change |
|------|--------|
| `public/assets/js/config.js` | `window.API_BASE = 'https://api.yourdomain.com'` |
| `api/config/config.php` | `APP_URL`, `FRONTEND_URL`, DB credentials, turn off `display_errors` |

Full checklist is in `deploy.md`.
