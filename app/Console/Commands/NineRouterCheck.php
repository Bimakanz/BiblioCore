<?php

namespace App\Console\Commands;

use App\Services\NineRouter\NineRouterClient;
use Illuminate\Console\Command;

class NineRouterCheck extends Command
{
    protected $signature = 'ninerouter:check {--models : Print every available chat model id}';

    protected $description = 'Check the 9Router gateway connection and configured model';

    public function handle(NineRouterClient $client): int
    {
        if (! $client->enabled()) {
            $this->error('NINEROUTER_URL is empty. Set it in .env.');

            return self::FAILURE;
        }

        $this->line('Gateway: ' . (string) config('ninerouter.url'));

        $health = $client->health();
        if (empty($health['ok'])) {
            $this->error('Health check failed — is the gateway running?');

            return self::FAILURE;
        }

        $this->info('Health: ok');

        $models = $client->models();
        $this->line('Chat models: ' . count($models));

        $model = (string) config('ninerouter.model');
        $ids = array_column($models, 'id');
        if (empty($ids) || in_array($model, $ids, true)) {
            $this->info('Model "'.$model.'": ready');
        } else {
            $this->warn('Model "'.$model.'" is not in /v1/models. Pick a valid NINEROUTER_MODEL.');
        }

        $this->line('Subtitle translation fallback: ' . (config('ninerouter.translation.enabled') ? 'on' : 'off'));
        $this->line('Translation model: ' . (string) config('ninerouter.translation.model'));

        if ($this->option('models')) {
            $this->table(['id', 'owned_by'], array_map(
                fn (array $m) => [$m['id'] ?? '', $m['owned_by'] ?? ''],
                $models
            ));
        }

        return self::SUCCESS;
    }
}
