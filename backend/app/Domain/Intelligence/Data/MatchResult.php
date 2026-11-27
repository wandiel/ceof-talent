<?php

namespace App\Domain\Intelligence\Data;

/**
 * Aderência de um candidato a uma vaga (0 a 100) e a conferência item a item.
 * É apenas apoio à triagem: nunca reprova nem aprova ninguém.
 */
final class MatchResult
{
    /** @param  list<array{label: string, mandatory: bool, matched: bool, evidence: ?string}>  $items */
    public function __construct(public readonly int $score, public readonly array $items, public readonly string $method) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'items' => $this->items,
            'mandatory_missing' => count(array_filter($this->items, fn ($i) => $i['mandatory'] && ! $i['matched'])),
            'method' => $this->method,
            'notice' => 'Sugestão automática baseada em palavras do cadastro e do currículo. Use como apoio: a decisão é sempre da equipe.',
        ];
    }
}
