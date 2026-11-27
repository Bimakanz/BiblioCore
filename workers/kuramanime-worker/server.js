import http from 'node:http';
import { HOST, PORT, closeBrowser, handleRequest } from './resolver.js';

const server = http.createServer((req, res) => {
    void handleRequest(req, res);
});

async function shutdown() {
    server.close();
    await closeBrowser();
    process.exit(0);
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

server.listen(PORT, HOST, () => {
    console.log(`[kuramanime-worker] listening on http://${HOST}:${PORT}`);
});
