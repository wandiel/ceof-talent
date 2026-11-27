<?php

namespace App\Domain\Intelligence\Support;

use Illuminate\Support\Str;

/** Normalização de texto em português para comparação por palavras. */
final class Text
{
    private const STOPWORDS = [
        'a', 'o', 'as', 'os', 'de', 'da', 'do', 'das', 'dos', 'e', 'em', 'no', 'na', 'nos', 'nas', 'um', 'uma', 'para', 'por', 'com',
        'sem', 'ao', 'aos', 'ou', 'que', 'se', 'sua', 'seu', 'ter', 'ser', 'como', 'mais', 'experiencia', 'conhecimento',
        'conhecimentos', 'desejavel', 'obrigatorio', 'minimo', 'anos', 'ano', 'area', 'boa', 'bom', 'nivel', 'curso', 'cursos',
    ];

    /** minúsculas, sem acentos, só letras/números separados por um espaço */
    public static function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii($text));

        return trim((string) preg_replace('/[^a-z0-9+#]+/', ' ', $text));
    }

    /** @return list<string> palavras significativas (sem stopwords, com 3+ letras) */
    public static function keywords(string $text): array
    {
        $words = explode(' ', self::normalize($text));

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true))));
    }

    /** Radical simples: compara os 6 primeiros caracteres ("enfermeira" ~ "enfermagem" não, "cirurgico" ~ "cirurgica" sim). */
    public static function stem(string $word): string
    {
        return mb_substr($word, 0, max(4, min(6, mb_strlen($word) - 1)));
    }

    /** O texto normalizado contém a expressão inteira, respeitando limites de palavra? */
    public static function containsPhrase(string $normalizedHaystack, string $phrase): bool
    {
        $needle = self::normalize($phrase);

        return $needle !== '' && preg_match('/(^| )'.preg_quote($needle, '/').'( |$)/', $normalizedHaystack) === 1;
    }

    /** Trecho do texto original ao redor da primeira ocorrência (para mostrar a evidência). */
    public static function excerpt(string $original, string $phrase, int $radius = 60): ?string
    {
        $haystack = Str::lower(Str::ascii($original));
        $pos = mb_strpos($haystack, self::normalize($phrase));
        if ($pos === false) {
            return null;
        }
        $start = max(0, $pos - $radius);
        $snippet = trim((string) preg_replace('/\s+/u', ' ', mb_substr($original, $start, mb_strlen($phrase) + 2 * $radius)));

        return Str::limit(($start > 0 ? '…' : '').$snippet, 280);
    }
}
