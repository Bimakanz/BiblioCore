import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
loadEnvFile(path.join(__dirname, '.env'));

export const PORT = Number(process.env.PORT || 4100);
export const HOST = process.env.HOST || '127.0.0.1';

const API_KEY = (process.env.WORKER_API_KEY || '').trim();
const CONFIGURED_BASE = normalizeUrl(process.env.KURAMANIME_URL || '');
const HEADLESS = (process.env.PLAYWRIGHT_HEADLESS || 'true').toLowerCase() !== 'false';
const CACHE_TTL_MS = Math.max(60_000, Number(process.env.STREAM_CACHE_TTL_MS || 1_800_000));
const MAX_CONCURRENT = Math.max(1, Number(process.env.MAX_CONCURRENT_PAGES || 2));
const NAVIGATION_TIMEOUT_MS = Math.max(5_000, Number(process.env.NAVIGATION_TIMEOUT_MS || 25_000));
const PLAYER_WAIT_TIMEOUT_MS = Math.max(5_000, Number(process.env.PLAYER_WAIT_TIMEOUT_MS || 18_000));
const USER_AGENT =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

const FALLBACK_BASE = 'https://v20.kuramanime.ing';
const BASE_TTL_MS = 6 * 60 * 60 * 1000;

let cachedBase = CONFIGURED_BASE ? { url: CONFIGURED_BASE, expiresAt: Number.MAX_SAFE_INTEGER } : null;
let browserPromise = null;
let contextPromise = null;
let activePages = 0;
const waitQueue = [];
const streamCache = new Map();
const inflight = new Map();

function loadEnvFile(filePath) {
    if (!fs.existsSync(filePath)) return;
    const raw = fs.readFileSync(filePath, 'utf8');
    for (const line of raw.split(/\r?\n/)) {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#')) continue;
        const eq = trimmed.indexOf('=');
        if (eq <= 0) continue;
        const key = trimmed.slice(0, eq).trim();
        let value = trimmed.slice(eq + 1).trim();
        if (
            (value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))
        ) {
            value = value.slice(1, -1);
        }
        if (!(key in process.env)) {
            process.env[key] = value;
        }
    }
}

function normalizeUrl(raw) {
    const trimmed = String(raw || '').trim();
    if (!trimmed) return null;
    try {
        const u = new URL(trimmed);
        if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
        return u.origin;
    } catch {
        return null;
    }
}

function findLocalExecutablePath() {
    const explicit = (process.env.PLAYWRIGHT_EXECUTABLE_PATH || '').trim();
    if (explicit && fs.existsSync(explicit)) return explicit;

    const candidates = [];
    if (process.platform === 'win32') {
        const localApp = process.env.LOCALAPPDATA || '';
        const msPlaywrightDir = path.join(localApp, 'ms-playwright');
        if (fs.existsSync(msPlaywrightDir)) {
            for (const entry of fs.readdirSync(msPlaywrightDir)) {
                if (entry.startsWith('chromium-')) {
                    candidates.push(path.join(msPlaywrightDir, entry, 'chrome-win64', 'chrome.exe'));
                }
            }
        }
        candidates.push(
            'C:/Program Files/Google/Chrome/Application/chrome.exe',
            'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
            'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
            'C:/Program Files/Microsoft/Edge/Application/msedge.exe'
        );
    } else if (process.platform === 'darwin') {
        candidates.push(
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge'
        );
    } else {
        candidates.push(
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable'
        );
    }

    return candidates.find((p) => p && fs.existsSync(p)) || null;
}

async function resolveLaunchConfig() {
    const localExe = findLocalExecutablePath();
    if (localExe && !process.env.VERCEL) {
        return {
            executablePath: localExe,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-blink-features=AutomationControlled',
            ],
        };
    }

    // Serverless Linux (Vercel) or Linux without a system Chrome binary: use @sparticuz/chromium.
    if (process.env.VERCEL || process.platform === 'linux') {
        const { default: sparticuz } = await import('@sparticuz/chromium');
        const executablePath = await sparticuz.executablePath();
        const filteredArgs = sparticuz.args.filter((arg) => !arg.startsWith('--headless'));
        return {
            executablePath,
            args: [...filteredArgs, '--disable-blink-features=AutomationControlled'],
        };
    }

    throw new Error(
        'No Chromium/Chrome executable found. Set PLAYWRIGHT_EXECUTABLE_PATH or install Chrome/Edge.'
    );
}

async function resolveBaseUrl() {
    if (CONFIGURED_BASE) return CONFIGURED_BASE;
    if (cachedBase && cachedBase.expiresAt > Date.now()) {
        return cachedBase.url;
    }

    for (const portal of ['https://kuramalink.app/', 'https://kuramalink.me/']) {
        try {
            const res = await fetch(portal, {
                method: 'GET',
                redirect: 'follow',
                headers: { 'User-Agent': USER_AGENT },
                signal: AbortSignal.timeout(8_000),
            });
            const origin = normalizeUrl(res.url);
            if (origin && origin.includes('kuramanime')) {
                cachedBase = { url: origin, expiresAt: Date.now() + BASE_TTL_MS };
                return origin;
            }
        } catch {
            // Try next mirror portal.
        }
    }

    cachedBase = { url: FALLBACK_BASE, expiresAt: Date.now() + 300_000 };
    return FALLBACK_BASE;
}

async function getContext() {
    if (contextPromise) return contextPromise;

    contextPromise = (async () => {
        const { executablePath, args } = await resolveLaunchConfig();
        browserPromise = chromium.launch({
            headless: HEADLESS,
            executablePath,
            args,
        });
        const browser = await browserPromise;
        browser.on('disconnected', () => {
            browserPromise = null;
            contextPromise = null;
        });

        return browser.newContext({
            userAgent: USER_AGENT,
            locale: 'id-ID',
            viewport: { width: 1280, height: 720 },
        });
    })().catch((err) => {
        browserPromise = null;
        contextPromise = null;
        throw err;
    });

    return contextPromise;
}

async function withPageSlot(fn) {
    if (activePages >= MAX_CONCURRENT) {
        await new Promise((resolve) => waitQueue.push(resolve));
    }
    activePages++;
    try {
        return await fn();
    } finally {
        activePages--;
        const next = waitQueue.shift();
        if (next) next();
    }
}

function isAllowedTarget(targetUrl, activeBase) {
    try {
        const u = new URL(targetUrl);
        if (u.protocol !== 'https:' && u.protocol !== 'http:') return false;
        const host = u.hostname.toLowerCase();
        const baseHost = new URL(activeBase).hostname.toLowerCase();
        return host === baseHost || /(?:^|\.)kuramanime\.[a-z0-9-]+$/i.test(host);
    } catch {
        return false;
    }
}

function qualityRank(quality) {
    const q = String(quality || '').toLowerCase();
    if (q.includes('1080')) return 4;
    if (q.includes('720')) return 3;
    if (q.includes('480')) return 2;
    if (q.includes('360')) return 1;
    return 0;
}

function formatQuality(size) {
    const clean = String(size || '').trim();
    if (/^\d{3,4}$/.test(clean)) return `${clean}p`;
    if (/^\d{3,4}p$/i.test(clean)) return clean.toLowerCase();
    return 'auto';
}

function normalizeExtracted(raw) {
    const mp4Sources = [];
    const seen = new Set();

    for (const item of raw.sources || []) {
        const url = String(item?.src || '').trim();
        if (!/^https?:\/\//i.test(url) || seen.has(url)) continue;
        if (!url.toLowerCase().includes('.mp4') && String(item?.type || '').toLowerCase() !== 'video/mp4') {
            continue;
        }
        seen.add(url);
        const quality = formatQuality(item.size || (url.match(/(\d{3,4})p/i)?.[1] ?? ''));
        mp4Sources.push({
            url,
            quality,
            isM3U8: false,
            _rank: qualityRank(quality),
        });
    }

    mp4Sources.sort((a, b) => b._rank - a._rank);
    const cleanMp4 = mp4Sources.map(({ _rank, ...rest }) => rest);

    if (cleanMp4.length > 0) {
        return {
            kind: 'mp4',
            sources: cleanMp4,
            server: raw.server || 'kuramadrive',
        };
    }

    const hlsUrl = String(raw.hlsSrc || '').trim();
    if (/^https?:\/\//i.test(hlsUrl) && hlsUrl.includes('.m3u8')) {
        return {
            kind: 'hls',
            sources: [{ url: hlsUrl, quality: 'auto', isM3U8: true }],
            server: raw.server || 'kuramadrive',
        };
    }

    return null;
}

async function readPlayerState(page) {
    return page.evaluate(() => {
        const video = document.querySelector('#player, video');
        const sources = Array.from(document.querySelectorAll('#player source, video source')).map((s) => ({
            src: s.getAttribute('src') || '',
            size: s.getAttribute('size') || '',
            type: s.getAttribute('type') || '',
        }));
        const hlsSrc = video ? video.getAttribute('data-hls-src') || video.dataset?.hlsSrc || '' : '';
        const serverEl = document.querySelector('#changeServer');
        const server = serverEl ? serverEl.value : null;
        const availableServers = serverEl
            ? Array.from(serverEl.querySelectorAll('option')).map((o) => o.value)
            : [];
        const errEl = document.querySelector('#tokenErrorText');
        const hasTokenError = Boolean(errEl && getComputedStyle(errEl).display !== 'none');

        return {
            title: document.title || '',
            href: location.href,
            hasPlayer: Boolean(video),
            sources,
            hlsSrc,
            server,
            availableServers,
            hasTokenError,
        };
    });
}

async function resolveEpisodeInBrowser(episodeUrl, includeDebug = false) {
    const context = await getContext();
    const page = await context.newPage();

    try {
        await page.route('**/*', (route) => {
            const type = route.request().resourceType();
            const u = route.request().url();
            if (
                type === 'image' ||
                type === 'font' ||
                type === 'media' ||
                u.includes('googletagmanager.com') ||
                u.includes('telegram.org') ||
                u.includes('arc.io') ||
                u.includes('doubleclick.net')
            ) {
                return route.abort();
            }
            return route.continue();
        });

        const response = await page.goto(episodeUrl, {
            waitUntil: 'domcontentloaded',
            timeout: NAVIGATION_TIMEOUT_MS,
        });
        const navStatus = response ? response.status() : null;

        await page
            .waitForFunction(
                () => {
                    const hasSource = document.querySelector('#player source[src], video#player[data-hls-src]');
                    const errEl = document.querySelector('#tokenErrorText');
                    const hasErr = Boolean(errEl && getComputedStyle(errEl).display !== 'none');
                    return Boolean(hasSource || hasErr);
                },
                null,
                { timeout: PLAYER_WAIT_TIMEOUT_MS }
            )
            .catch(() => {});

        let state = await readPlayerState(page);
        let extracted = normalizeExtracted(state);

        if (
            (!extracted || extracted.kind !== 'mp4') &&
            state.availableServers.includes('kuramadrive-v2') &&
            state.server !== 'kuramadrive-v2'
        ) {
            await page.evaluate(() => {
                const sel = document.querySelector('#changeServer');
                if (!sel) return;
                sel.value = 'kuramadrive-v2';
                if (typeof window.$ === 'function') {
                    window.$(sel).trigger('change');
                } else {
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });

            await page
                .waitForFunction(
                    (prevFirst) => {
                        const first = document.querySelector('#player source[src]')?.getAttribute('src') || '';
                        const hls = document.querySelector('video#player')?.getAttribute('data-hls-src') || '';
                        return (first && first !== prevFirst) || Boolean(hls);
                    },
                    state.sources[0]?.src || '',
                    { timeout: 10_000 }
                )
                .catch(() => {});

            state = await readPlayerState(page);
            const v2 = normalizeExtracted(state);
            if (v2 && (!extracted || v2.kind === 'mp4')) {
                extracted = v2;
            }
        }

        if (!extracted) {
            if (includeDebug) {
                return {
                    ok: false,
                    error: 'No playable stream found on episode page',
                    debug: {
                        navStatus,
                        title: state.title,
                        href: state.href,
                        hasPlayer: state.hasPlayer,
                        hasTokenError: state.hasTokenError,
                        server: state.server,
                        availableServers: state.availableServers,
                        sourcesCount: state.sources.length,
                        hlsSrc: state.hlsSrc,
                    },
                };
            }
            return null;
        }

        return {
            ok: true,
            kind: extracted.kind,
            sources: extracted.sources,
            referer: episodeUrl,
            server: extracted.server,
            resolvedAt: new Date().toISOString(),
        };
    } finally {
        await page.close().catch(() => {});
    }
}

async function resolveStream(episodeUrl, includeDebug = false) {
    const cached = streamCache.get(episodeUrl);
    if (cached && cached.expiresAt > Date.now()) {
        return cached.payload;
    }
    if (cached) {
        streamCache.delete(episodeUrl);
    }

    if (!includeDebug && inflight.has(episodeUrl)) {
        return inflight.get(episodeUrl);
    }

    const promise = withPageSlot(async () => {
        const result = await resolveEpisodeInBrowser(episodeUrl, includeDebug);
        if (result && result.ok) {
            streamCache.set(episodeUrl, {
                payload: result,
                expiresAt: Date.now() + CACHE_TTL_MS,
            });
        }
        return result;
    }).finally(() => {
        inflight.delete(episodeUrl);
    });

    if (!includeDebug) {
        inflight.set(episodeUrl, promise);
    }
    return promise;
}

function sendJson(res, status, data) {
    const body = JSON.stringify(data);
    res.writeHead(status, {
        'Content-Type': 'application/json; charset=utf-8',
        'Content-Length': Buffer.byteLength(body),
        'Cache-Control': 'no-store',
    });
    res.end(body);
}

async function readJsonBody(req) {
    if (req.body && typeof req.body === 'object') {
        return req.body;
    }
    if (typeof req.body === 'string' && req.body.trim() !== '') {
        return JSON.parse(req.body);
    }
    const chunks = [];
    let size = 0;
    for await (const chunk of req) {
        size += chunk.length;
        if (size > 64 * 1024) {
            throw new Error('Request body too large');
        }
        chunks.push(chunk);
    }
    if (chunks.length === 0) return {};
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}

function checkAuth(req) {
    if (!API_KEY) return true;
    const provided = String(req.headers['x-api-key'] || '').trim();
    return provided === API_KEY;
}

export async function handleRequest(req, res) {
    try {
        const reqUrl = new URL(req.url || '/', `http://${req.headers.host || `${HOST}:${PORT}`}`);

        if (req.method === 'GET' && (reqUrl.pathname === '/' || reqUrl.pathname === '/health')) {
            const baseUrl = await resolveBaseUrl();
            let browserConnected = false;
            if (browserPromise) {
                try {
                    const b = await browserPromise;
                    browserConnected = b.isConnected();
                } catch {
                    browserConnected = false;
                }
            }
            return sendJson(res, 200, {
                ok: true,
                service: 'kuramanime-worker',
                baseUrl,
                browserConnected,
                activePages,
                cacheSize: streamCache.size,
                uptimeSeconds: Math.floor(process.uptime()),
            });
        }

        if ((req.method === 'GET' || req.method === 'POST') && reqUrl.pathname === '/stream') {
            if (!checkAuth(req)) {
                return sendJson(res, 401, { ok: false, error: 'Unauthorized' });
            }

            const body = req.method === 'POST' ? await readJsonBody(req).catch(() => ({})) : {};
            const rawUrl = String(reqUrl.searchParams.get('url') || body.url || '').trim();
            const rawPath = String(reqUrl.searchParams.get('path') || body.path || '').trim();
            const animeId = String(reqUrl.searchParams.get('animeId') || body.animeId || '').trim();
            const slug = String(reqUrl.searchParams.get('slug') || body.slug || '').trim();
            const episode = String(reqUrl.searchParams.get('episode') || body.episode || '').trim();

            const baseUrl = await resolveBaseUrl();
            let targetUrl = '';

            if (rawUrl) {
                targetUrl = rawUrl;
            } else if (rawPath) {
                targetUrl = `${baseUrl}/${rawPath.replace(/^\/+/, '')}`;
            } else if (/^\d+$/.test(animeId) && slug && /^\d+$/.test(episode)) {
                targetUrl = `${baseUrl}/anime/${animeId}/${encodeURIComponent(slug)}/episode/${episode}`;
            }

            if (!targetUrl || !isAllowedTarget(targetUrl, baseUrl)) {
                return sendJson(res, 400, { ok: false, error: 'Invalid or disallowed Kuramanime URL' });
            }

            const includeDebug = reqUrl.searchParams.get('debug') === '1';
            const payload = await resolveStream(targetUrl, includeDebug);
            if (!payload || !payload.ok) {
                return sendJson(
                    res,
                    404,
                    payload && !payload.ok
                        ? payload
                        : { ok: false, error: 'No playable stream found on episode page' }
                );
            }

            return sendJson(res, 200, payload);
        }

        return sendJson(res, 404, { ok: false, error: 'Not found' });
    } catch (err) {
        console.error('[kuramanime-worker]', err);
        return sendJson(res, 502, { ok: false, error: err?.message || 'Worker failure' });
    }
}

export async function closeBrowser() {
    if (browserPromise) {
        try {
            const browser = await browserPromise;
            await browser.close();
        } catch {
            // Ignore shutdown errors.
        }
    }
}
