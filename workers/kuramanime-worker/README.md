# Kuramanime Playwright Worker

Resolves Indonesian hard-subtitled MP4 streams from Kuramanime by running a headless Chromium page so `leviathan.js` and its dynamic session tokens execute naturally, then returns direct `kuramadrive` video URLs to Laravel.

## Setup

```bash
cd workers/kuramanime-worker
npm install
npx playwright install chromium
cp .env.example .env
npm start
```

The server listens on `http://127.0.0.1:4100` by default.

In the root Laravel `.env`:

```env
KURAMANIME_WORKER_URL=http://127.0.0.1:4100
# KURAMANIME_WORKER_KEY=   # must match WORKER_API_KEY if set
```

## Endpoints

- `GET /health` — reports active Kuramanime mirror URL and Chromium status.
- `GET /stream?url=https://v20.kuramanime.ing/anime/2213/sousou-no-frieren-zQFI1c5G/episode/1` — returns `{ ok, kind, sources, referer, server, resolvedAt }`.
- `POST /stream` — JSON `{ "url": "..." }` (or `{ "animeId": 2213, "slug": "...", "episode": 1 }`).
