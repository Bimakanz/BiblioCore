<?php

namespace Tests\Feature;

use App\Services\Anime\SubtitleTranslator;
use App\Services\NineRouter\NineRouterClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NineRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ninerouter.url' => 'http://127.0.0.1:20128',
            'ninerouter.key' => 'sk-test',
            'ninerouter.model' => 'oc/deepseek-v4.1-flash-free',
            'ninerouter.timeout' => 5,
            'ninerouter.translation.enabled' => true,
            'ninerouter.translation.model' => 'oc/deepseek-v4.1-flash-free',
        ]);
    }

    public function test_it_reads_the_gateway_health(): void
    {
        Http::fake([
            '127.0.0.1:20128/api/health' => Http::response(['ok' => true]),
        ]);

        $client = app(NineRouterClient::class);

        $this->assertTrue($client->enabled());
        $this->assertTrue($client->reachable());
        $this->assertSame(['ok' => true], $client->health());
    }

    public function test_it_lists_models_and_parses_a_chat_reply(): void
    {
        Http::fake([
            '127.0.0.1:20128/v1/models' => Http::response([
                'object' => 'list',
                'data' => [['id' => 'oc/deepseek-v4.1-flash-free', 'owned_by' => 'oc']],
            ]),
            '127.0.0.1:20128/v1/chat/completions' => function ($request) {
                $this->assertSame('Bearer sk-test', $request->header('Authorization')[0]);
                $body = $request->data();
                $this->assertSame('oc/deepseek-v4.1-flash-free', $body['model']);
                $this->assertSame('Hello', $body['messages'][0]['content']);

                return Http::response([
                    'choices' => [['message' => ['content' => 'Halo']]],
                ]);
            },
        ]);

        $client = app(NineRouterClient::class);
        $this->assertSame('oc/deepseek-v4.1-flash-free', $client->models()[0]['id']);
        $this->assertSame('Halo', $client->chat([['role' => 'user', 'content' => 'Hello']]));
    }

    public function test_it_fails_soft_when_the_gateway_is_down(): void
    {
        Http::fake([
            '127.0.0.1:20128/*' => Http::response('down', 503),
        ]);

        $client = app(NineRouterClient::class);

        $this->assertNull($client->health());
        $this->assertFalse($client->reachable());
        $this->assertSame([], $client->models());
        $this->assertNull($client->chat([['role' => 'user', 'content' => 'Hi']]));
    }

    public function test_subtitle_translation_can_fall_back_to_ninerouter(): void
    {
        Http::fake([
            '127.0.0.1:20128/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => "```json\n[\"Halo dunia\", \"Selamat pagi\"]\n```"],
                ]],
            ]),
        ]);

        $translator = new class extends SubtitleTranslator
        {
            /** @param array<int, string> $lines */
            public function translate(array $lines): ?array
            {
                return $this->translateChunkWithNineRouter($lines, 'en', 'id');
            }
        };

        $this->assertSame(
            ['Halo dunia', 'Selamat pagi'],
            $translator->translate(['Hello world', 'Good morning'])
        );
    }

    public function test_subtitle_translation_rejects_misaligned_ai_output(): void
    {
        Http::fake([
            '127.0.0.1:20128/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '["only one line"]']]],
            ]),
        ]);

        $translator = new class extends SubtitleTranslator
        {
            /** @param array<int, string> $lines */
            public function translate(array $lines): ?array
            {
                return $this->translateChunkWithNineRouter($lines, 'en', 'id');
            }
        };

        $this->assertNull($translator->translate(['Line one', 'Line two']));
    }

    public function test_subtitle_translation_honours_the_opt_out(): void
    {
        config(['ninerouter.translation.enabled' => false]);

        Http::fake();

        $translator = new class extends SubtitleTranslator
        {
            /** @param array<int, string> $lines */
            public function translate(array $lines): ?array
            {
                return $this->translateChunkWithNineRouter($lines, 'en', 'id');
            }
        };

        $this->assertNull($translator->translate(['Hello']));
        Http::assertNothingSent();
    }
}
