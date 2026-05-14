# NoteMate Backend

Backend cua NoteMate, mot ung dung quan ly ghi chu ca nhan. Phan nay xu ly xac thuc, phan quyen, nghiep vu ghi chu, database, email, RESTful API va WebSocket server cho realtime collaboration.

## Tech Stack

- PHP 8.2+
- MySQL
- PDO MySQL
- Composer
- PHPMailer
- Ratchet WebSocket
- Railway

## Yeu Cau Moi Truong

- PHP 8.2 tro len
- Composer
- MySQL
- PHP extensions: `pdo`, `pdo_mysql`

Kiem tra:

```bash
php -v
composer -V
```

## Cau Hinh

Tao file `.env` trong thu muc `backend/`.

Local example:

```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
APP_DEBUG=true

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=notes_app
DB_USER=root
DB_PASS=

SESSION_NAME=notemate_session
SESSION_SECURE=false
SESSION_SAMESITE=Lax
CORS_ALLOWED_ORIGIN=http://localhost:5173

MAIL_MODE=log
MAIL_FROM=noreply@notemate.local
MAIL_FROM_NAME=NoteMate
MAIL_LOG_FILE=emails.log

SOCKET_PORT=8081
```

## Cai Dat

```bash
composer install
```

## Tao Database

Tao database MySQL:

```sql
CREATE DATABASE notes_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Backend se tu tao cac bang can thiet khi khoi dong. Co the khoi tao rieng bang:

```bash
php config/init.php
```

## Chay API Local

```bash
php -S localhost:8000 index.php
```

Health check:

```text
http://localhost:8000/api/health
```

## Chay WebSocket Local

Mo terminal khac trong thu muc `backend/`:

```bash
php config/socket-server.php
```

Mac dinh WebSocket server chay tai:

```text
ws://localhost:8081
```

## Deploy

Backend duoc deploy tren Railway. File cau hinh deploy:

```text
railway.json
```

Can cau hinh environment variables tren Railway cho database, frontend URL, CORS, mail va socket port.

## Cau Truc Thu Muc

```text
backend/
+-- config/
+-- controllers/
+-- core/
+-- middleware/
+-- models/
+-- routes/
+-- services/
+-- composer.json
+-- composer.lock
+-- index.php
+-- railway.json
```