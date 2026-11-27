<?php

namespace Tests\Unit\Candidates;

use App\Domain\Candidates\Support\Cpf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CpfTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function cpfs(): array
    {
        return [
            'válido com máscara' => ['529.982.247-25', true],
            'válido só dígitos' => ['52998224725', true],
            'dígito errado' => ['529.982.247-24', false],
            'repetido' => ['111.111.111-11', false],
            'curto' => ['5299822472', false],
            'vazio' => ['', false],
        ];
    }

    #[DataProvider('cpfs')]
    public function test_validation(string $cpf, bool $expected): void
    {
        $this->assertSame($expected, Cpf::isValid($cpf));
    }

    public function test_format_and_mask(): void
    {
        $this->assertSame('529.982.247-25', Cpf::format('52998224725'));
        $this->assertSame('***.982.247-**', Cpf::mask('52998224725'));
        $this->assertNull(Cpf::mask(null));
    }
}
