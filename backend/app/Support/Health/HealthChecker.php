<?php

namespace App\Support\Health;

use App\Models\CandidateDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verifica a saúde das dependências de infraestrutura da aplicação.
 *
 * Cada verificação retorna "ok" ou "fail" e o tempo gasto em milissegundos.
 * Detalhes de erro só são expostos quando APP_DEBUG está ativo, para não
 * vazar informações internas em produção.
 */
class HealthChecker
{
    /**
     * @return array{status: string, checks: array<string, array<string, mixed>>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->measure(fn () => $this->checkDatabase()),
            'redis' => $this->measure(fn () => $this->checkRedis()),
            'cache' => $this->measure(fn () => $this->checkCache()),
            'storage' => $this->measure(fn () => $this->checkStorage()),
        ];

        $healthy = collect($checks)->every(fn (array $check) => $check['status'] === 'ok');

        return [
            'status' => $healthy ? 'ok' : 'fail',
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function measure(callable $check): array
    {
        $start = hrtime(true);

        try {
            $details = $check() ?? [];
            $result = ['status' => 'ok'] + $details;
        } catch (Throwable $e) {
            report($e);
            $result = ['status' => 'fail'];

            if (config('app.debug')) {
                $result['error'] = $e->getMessage();
            }
        }

        $result['duration_ms'] = round((hrtime(true) - $start) / 1_000_000, 2);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        $connection = DB::connection();
        $connection->select('select 1');

        return ['driver' => $connection->getDriverName()];
    }

    private function checkRedis(): void
    {
        $pong = Redis::connection()->ping();

        // phpredis retorna true; predis retorna um objeto Status("PONG").
        if ($pong !== true && strtoupper((string) $pong) !== 'PONG' && strtoupper((string) $pong) !== '+PONG') {
            throw new \RuntimeException('Redis não respondeu ao PING.');
        }
    }

    private function checkCache(): void
    {
        $key = 'health:'.Str::uuid();
        Cache::put($key, 'ok', 10);
        $value = Cache::pull($key);

        if ($value !== 'ok') {
            throw new \RuntimeException('Cache não persistiu o valor de teste.');
        }
    }

    private function checkStorage(): void
    {
        // Disco dos documentos: o volume privado ou o bucket S3.
        $disk = CandidateDocument::storage();
        $path = '.health/'.Str::uuid().'.txt';

        $disk->put($path, 'ok');
        $content = $disk->get($path);
        $disk->delete($path);

        if ($content !== 'ok') {
            throw new \RuntimeException('Armazenamento de documentos ('.CandidateDocument::diskName().') não está gravável.');
        }
    }
}
