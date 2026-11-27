<?php

namespace App\Services\NineRouter;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for a 9Router gateway: an OpenAI-compatible REST API that
 * fronts many model providers behind one key.
 *
 * Everything fails soft. The app must keep working (and keep serving anime)
 * when the local gateway is down, the key is rotated or the model is missing.
 */
class NineRouterClient
{
    public function enabled(): bool
    {
        return trim((string) config('ninerouter.url', '')) !== '';
    }

    /** GET /api/health → {"ok": true} when the gateway is up. */
    public function health(): ?array
    {
        return $this->request('GET', '/api/health');
    }

    /**
     * Available model ids. $kind can be "image", "tts", "embedding", "web",
     * "stt" or "image-to-text"; empty means chat/LLM models.
     *
     * @return array<int, array<string, mixed>>
     */
    public function models(string $kind = ''): array
    {
        $path = '/v1/models' . ($kind !== '' ? '/' . rawurlencode($kind) : '');
        $data = $this->request('GET', $path);

        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    /**
     * Chat completion. Returns the assistant text, or null on any failure.
     *
     * Some gateway builds stream the reply even when stream=false is absent,
     * so both a normal JSON completion and SSE `data:` chunks are accepted.
     *
     * @param  array<int, array<string, string>>  $messages
     * @param  array<string, mixed>  $options  extra body fields (temperature, etc.)
     */
    public function chat(array $messages, ?string $model = null, array $options = []): ?string
    {
        $body = array_merge([
            'model' => $model ?: (string) config('ninerouter.model'),
            'messages' => $messages,
            'stream' => false,
        ], $options);

        $raw = $this->requestRaw('POST', '/v1/chat/completions', $body);
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        if (is_array($data)) {
            $content = $data['choices'][0]['message']['content'] ?? null;

            return is_string($content) ? $content : null;
        }

        // SSE fallback: concatenate every assistant delta in order.
        $content = '';
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, 'data:')) {
                continue;
            }
            $chunk = json_decode(trim(substr($line, 5)), true);
            if (! is_array($chunk)) {
                continue;
            }
            $delta = $chunk['choices'][0]['delta']['content'] ?? null;
            $message = $chunk['choices'][0]['message']['content'] ?? null;
            if (is_string($delta)) {
                $content .= $delta;
            } elseif (is_string($message)) {
                $content .= $message;
            }
        }

        return $content !== '' ? $content : null;
    }

    /** True when the gateway answers the health endpoint right now. */
    public function reachable(): bool
    {
        return ! empty($this->health()['ok']);
    }

    protected function request(string $method, string $path, array $payload = []): ?array
    {
        $raw = $this->requestRaw($method, $path, $payload);
        if ($raw === null) {
            return null;
        }

        $json = json_decode($raw, true);

        return is_array($json) ? $json : null;
    }

    protected function requestRaw(string $method, string $path, array $payload = []): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $base = rtrim((string) config('ninerouter.url'), '/');
        try {
            $request = Http::timeout((int) config('ninerouter.timeout', 25))
                ->retry(1, 250)
                ->acceptJson();
            $key = config('ninerouter.key');
            if (is_string($key) && $key !== '') {
                $request = $request->withToken($key);
            }

            $response = $method === 'POST'
                ? $request->post($base . $path, $payload)
                : $request->get($base . $path);

            if ($response->failed()) {
                Log::warning('9Router request failed', [
                    'status' => $response->status(),
                    'path' => $path,
                ]);

                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::debug('9Router request error: ' . $e->getMessage(), ['path' => $path]);

            return null;
        }
    }
}
