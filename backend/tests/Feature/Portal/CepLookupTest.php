<?php

namespace Tests\Feature\Portal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CepLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_finds_address_on_viacep_and_caches_it(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response([
            'cep' => '76801-000', 'logradouro' => 'Avenida Sete de Setembro', 'bairro' => 'Centro', 'localidade' => 'Porto Velho', 'uf' => 'RO',
        ])]);

        $this->getJson('/api/v1/public/cep/76801000')->assertOk()->assertExactJson(['data' => [
            'cep' => '76801000', 'street' => 'Avenida Sete de Setembro', 'neighborhood' => 'Centro', 'city' => 'Porto Velho', 'state' => 'RO',
        ]]);
        $this->getJson('/api/v1/public/cep/76801000')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_falls_back_to_brasilapi_when_viacep_is_down(): void
    {
        Http::fake([
            'viacep.com.br/*' => Http::response('erro', 503),
            'brasilapi.com.br/*' => Http::response(['cep' => '69005010', 'state' => 'AM', 'city' => 'Manaus', 'neighborhood' => 'Centro', 'street' => 'Rua Henrique Martins']),
        ]);

        $this->getJson('/api/v1/public/cep/69005010')->assertOk()->assertJsonPath('data.city', 'Manaus')->assertJsonPath('data.state', 'AM');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'brasilapi.com.br/api/cep/v1/69005010'));
    }

    public function test_unknown_cep_and_offline_services_return_404_without_error(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response(['erro' => true]), 'brasilapi.com.br/*' => Http::response([], 404)]);
        $this->getJson('/api/v1/public/cep/00000000')->assertNotFound()->assertJsonPath('message', 'CEP não encontrado. Preencha a cidade e a UF.');

        Http::fake(['*' => fn () => throw new ConnectionException('sem rede')]);
        $this->getJson('/api/v1/public/cep/12345678')->assertNotFound();
    }

    public function test_invalid_format_does_not_reach_external_services(): void
    {
        Http::fake();
        $this->getJson('/api/v1/public/cep/1234')->assertNotFound();
        $this->getJson('/api/v1/public/cep/abcdefgh')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_can_be_disabled(): void
    {
        config(['services.cep.enabled' => false]);
        Http::fake();
        $this->getJson('/api/v1/public/cep/76801000')->assertNotFound();
        Http::assertNothingSent();
    }
}
