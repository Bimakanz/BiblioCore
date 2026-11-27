# Kuramanime Playwright Worker

Resolves Indonesian hard-subtitled MP4 streams from Kuramanime by running headless Chromium so `leviathan.js` and its dynamic session tokens execute naturally, then returns direct `kuramadrive` video URLs to Laravel.

## Local Setup

```bash
cd workers/kuramanime-worker
npm install
cp .env.example .env
npm start
```

The server listens on `http://127.0.0.1:4100` by default and automatically uses your local Chromium (`ms-playwright`), Google Chrome, or Microsoft Edge installation.

## Deploy to Vercel

1. Import `Bimakanz/BiblioCore` as a separate Vercel project with **Root Directory** set to `workers/kuramanime-worker`.
2. Leave the **Node** preset as-is (`vercel.json` routes requests to `api/index.js` using `@sparticuz/chromium` + `playwright-core`).
3. Optional: set `WORKER_API_KEY` in the worker project and set `KURAMANIME_WORKER_URL` + `KURAMANIME_WORKER_KEY` in the main BiblioCore Vercel project.

## Endpoints

- `GET /health` — reports active Kuramanime mirror URL and Chromium status.
- `GET /stream?url=https://v20.kuramanime.ing/anime/2213/sousou-no-frieren-zQFI1c5G/episode/1` — returns `{ ok, kind, sources, referer, server, resolvedAt }`.
- `POST /stream` — JSON `{ "url": "..." }` (or `{ "animeId": 2213, "slug": "...", "episode": 1 }`).
