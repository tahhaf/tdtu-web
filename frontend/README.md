# NoteMate Frontend

Frontend cua NoteMate, mot ung dung quan ly ghi chu ca nhan. Phan nay hien thi giao dien nguoi dung, goi API den backend, nhan cap nhat realtime qua WebSocket va ho tro offline/PWA.

## Tech Stack

- React JavaScript
- Vite
- Bootstrap
- React Router
- IndexedDB
- Service Worker
- WebSocket

## Yeu Cau Moi Truong

- Node.js 18 tro len
- npm
- Backend API dang chay
- WebSocket server dang chay neu can realtime collaboration

Kiem tra:

```bash
node -v
npm -v
```

## Cau Hinh

Tao file `.env` trong thu muc `frontend/` dua tren `.env.example`.

Local example:

```env
VITE_API_BASE_URL=http://localhost:8000/api
VITE_DEV_API_TARGET=http://localhost:8000
VITE_WS_URL=ws://localhost:8081
VITE_WS_PORT=8081
```

Production example:

```env
VITE_API_BASE_URL=https://your-railway-backend-domain/api
VITE_WS_URL=wss://your-railway-websocket-domain
```

## Cai Dat

```bash
npm install
```

## Chay Local

```bash
npm run dev
```

Mac dinh frontend chay tai:

```text
http://localhost:5173
```

Neu can truy cap tu dien thoai hoac thiet bi khac trong cung Wi-Fi, chay:

```bash
npm run dev:host
```

Sau do mo frontend bang dia chi LAN cua may tinh, vi du:

```text
http://192.168.1.10:5173
```

## Build

```bash
npm run build
```

Thu muc build output:

```text
dist/
```

## Deploy

Frontend duoc deploy tren Vercel. File cau hinh deploy:

```text
vercel.json
```

Can cau hinh environment variables tren Vercel:

```env
VITE_API_BASE_URL=https://your-railway-backend-domain/api
VITE_WS_URL=wss://your-railway-websocket-domain
```

## Cau Truc Thu Muc

```text
frontend/
+-- public/
+-- src/
|   +-- components/
|   +-- context/
|   +-- hooks/
|   +-- pages/
|   +-- services/
|   +-- styles/
+-- .env.example
+-- package.json
+-- package-lock.json
+-- vercel.json
+-- vite.config.js
```
