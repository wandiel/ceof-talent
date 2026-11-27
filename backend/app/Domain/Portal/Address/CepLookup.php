<?php

namespace App\Domain\Portal\Address;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consulta de endereço pelo CEP, usada para preencher cidade, UF e bairro nos formulários.
 *
 * Consulta o ViaCEP e, se ele falhar, a BrasilAPI. O navegador nunca fala com esses serviços
 * diretamente (a política de segurança só permite chamadas ao próprio sistema). O resultado
 * fica em cache: CEPs não mudam com frequência e isso poupa os serviços externos.
 */
class CepLookup
{
    /** @return array{cep: string, street: ?string, neighborhood: ?string, city: string, state: string}|null */
    public function find(string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8 || ! config('services.cep.enabled')) {
            return null;
        }

        $cached = Cache::get("cep:{$cep}");
        if (is_array($cached)) {
            return $cached['found'] ? $cached['address'] : null;
        }

        $address = null;
        $answered = false;
        foreach (['viaCep', 'brasilApi'] as $provider) {
            try {
                [$answered, $address] = $this->{$provider}($cep);
            } catch (Throwable $e) {
                Log::info('Consulta de CEP falhou', ['provider' => $provider, 'error' => $e->getMessage()]);

                continue;
            }
            if ($answered) {
                break;
            }
        }

        // Só guarda "não encontrado" quando um serviço respondeu de fato (não em falha de rede).
        if ($answered) {
            Cache::put("cep:{$cep}", ['found' => $address !== null, 'address' => $address], $address ? now()->addDays(30) : now()->addDay());
        }

        return $address;
    }

    /** @return array{0: bool, 1: ?array} [respondeu, endereço] */
    private function viaCep(string $cep): array
    {
        $response = Http::timeout(4)->acceptJson()->get("https://viacep.com.br/ws/{$cep}/json/");
        if ($response->status() === 400) {
            return [true, null];
        }
        if (! $response->successful()) {
            return [false, null];
        }
        $d = $response->json();
        if (! is_array($d) || ! empty($d['erro']) || empty($d['localidade']) || empty($d['uf'])) {
            return [true, null];
        }

        return [true, $this->address($cep, $d['logradouro'] ?? null, $d['bairro'] ?? null, $d['localidade'], $d['uf'])];
    }

    /** @return array{0: bool, 1: ?array} */
    private function brasilApi(string $cep): array
    {
        $response = Http::timeout(4)->acceptJson()->get("https://brasilapi.com.br/api/cep/v1/{$cep}");
        if ($response->status() === 404) {
            return [true, null];
        }
        if (! $response->successful()) {
            return [false, null];
        }
        $d = $response->json();
        if (! is_array($d) || empty($d['city']) || empty($d['state'])) {
            return [true, null];
        }

        return [true, $this->address($cep, $d['street'] ?? null, $d['neighborhood'] ?? null, $d['city'], $d['state'])];
    }

    private function address(string $cep, ?string $street, ?string $neighborhood, string $city, string $state): array
    {
        $clean = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : mb_substr($v, 0, 120);

        return [
            'cep' => $cep,
            'street' => $clean($street),
            'neighborhood' => $clean($neighborhood),
            'city' => mb_substr(trim($city), 0, 120),
            'state' => mb_strtoupper(mb_substr(trim($state), 0, 2)),
        ];
    }
}
