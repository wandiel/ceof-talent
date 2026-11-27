<?php

namespace App\Domain\Intelligence\Local;

use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Intelligence\Contracts\CandidateProfileExtractor;
use App\Domain\Intelligence\Data\Suggestion;
use App\Domain\Intelligence\Enums\SuggestionType;
use App\Domain\Intelligence\Support\Text;
use App\Models\Candidate;
use App\Models\Skill;

/**
 * Sugestões por dicionário, sem serviço externo:
 * - competências: catálogo de competências já usado no sistema + lista base;
 * - área profissional: palavras típicas de cada área, com a mais frequente sugerida.
 * Fica pronto para ser substituído por um modelo de linguagem (mesma interface).
 */
class KeywordProfileExtractor implements CandidateProfileExtractor
{
    /** Competências comuns em hospital, reconhecidas mesmo antes de existirem no catálogo. */
    public const BASE_SKILLS = [
        'Atendimento ao público', 'Instrumentação cirúrgica', 'Centro cirúrgico', 'Central de material e esterilização', 'Esterilização',
        'Oftalmologia', 'Biossegurança', 'Primeiros socorros', 'Suporte básico de vida', 'BLS', 'ACLS', 'Administração de medicamentos',
        'Sinais vitais', 'Curativos', 'Prontuário eletrônico', 'Tasy', 'MV Soul', 'Faturamento hospitalar', 'Auditoria de contas médicas',
        'Autorização de convênios', 'TISS', 'Agendamento de consultas', 'Recepção hospitalar', 'Excel', 'Word', 'Power BI', 'SAP',
        'Pacote Office', 'Inglês', 'Espanhol', 'Libras', 'Gestão de equipes', 'Liderança', 'Departamento pessoal', 'Folha de pagamento',
        'Recrutamento e seleção', 'Contas a pagar', 'Contas a receber', 'Conciliação bancária', 'Compras', 'Controle de estoque',
        'Almoxarifado', 'Manutenção predial', 'Elétrica', 'Refrigeração', 'Redes de computadores', 'Suporte técnico', 'SQL', 'Linux',
        'Higienização hospitalar', 'Limpeza concorrente', 'Telemarketing', 'Vendas', 'Marketing digital', 'Redes sociais',
        'Optometria', 'Tomografia de coerência óptica', 'Campimetria', 'Biometria ocular', 'Retinografia', 'Topografia de córnea',
    ];

    /** Palavras (sem acento) que indicam cada área. */
    public const AREA_KEYWORDS = [
        'medical' => ['medico', 'medica', 'crm', 'residencia medica', 'oftalmologista', 'cirurgiao', 'plantonista', 'anestesiologista'],
        'nursing' => ['enfermagem', 'enfermeira', 'enfermeiro', 'coren', 'tecnico de enfermagem', 'tecnica de enfermagem', 'auxiliar de enfermagem', 'instrumentador', 'instrumentadora', 'sae'],
        'clinical_support' => ['optometrista', 'optometria', 'farmacia', 'farmaceutico', 'laboratorio', 'radiologia', 'biomedico', 'exames', 'tecnico em oftalmologia', 'ortoptista'],
        'patient_service' => ['recepcao', 'recepcionista', 'atendimento ao paciente', 'atendimento ao publico', 'agendamento', 'call center', 'telefonista', 'acolhimento'],
        'administrative' => ['auxiliar administrativo', 'assistente administrativo', 'arquivo', 'secretaria', 'rotinas administrativas', 'protocolo'],
        'finance' => ['faturamento', 'contas a pagar', 'contas a receber', 'financeiro', 'contabilidade', 'tesouraria', 'conciliacao', 'glosas', 'convenios', 'tiss'],
        'human_resources' => ['recursos humanos', 'departamento pessoal', 'folha de pagamento', 'recrutamento', 'treinamento', 'beneficios', 'admissao'],
        'information_technology' => ['tecnologia da informacao', 'suporte tecnico', 'redes', 'sistemas', 'desenvolvedor', 'programacao', 'infraestrutura de ti', 'help desk', 'sql', 'linux'],
        'commercial' => ['vendas', 'comercial', 'marketing', 'consultor', 'relacionamento com cliente', 'redes sociais'],
        'logistics' => ['estoque', 'almoxarifado', 'compras', 'logistica', 'suprimentos', 'inventario'],
        'maintenance' => ['manutencao', 'eletricista', 'refrigeracao', 'predial', 'engenharia clinica', 'mecanico'],
        'general_services' => ['higienizacao', 'limpeza', 'servicos gerais', 'copa', 'lavanderia', 'zeladoria'],
        'legal' => ['juridico', 'advogado', 'advogada', 'oab', 'contratos', 'compliance'],
        'management' => ['gerente', 'coordenador', 'coordenadora', 'supervisor', 'supervisora', 'gestao de equipes', 'lideranca', 'diretor'],
    ];

    public function suggest(string $text, Candidate $candidate): array
    {
        $normalized = Text::normalize($text);
        if ($normalized === '') {
            return [];
        }

        return [...$this->skills($normalized, $text, $candidate), ...$this->area($normalized, $text, $candidate)];
    }

    /** @return list<Suggestion> */
    private function skills(string $normalized, string $original, Candidate $candidate): array
    {
        $owned = $candidate->skills()->pluck('name')->map(fn ($n) => Text::normalize($n))->all();
        $catalog = Skill::query()->whereRaw('char_length(name) >= 3')->orderBy('name')->limit(5000)->pluck('name')->all();

        $found = [];
        foreach ([...$catalog, ...self::BASE_SKILLS] as $name) {
            $key = Text::normalize($name);
            if ($key === '' || isset($found[$key]) || in_array($key, $owned, true)) {
                continue;
            }
            // Siglas curtas (BLS, SAP, SQL) só valem em maiúsculas no texto original, para evitar falsos positivos.
            if (mb_strlen($key) <= 3 && ! preg_match('/\b'.preg_quote(mb_strtoupper($name), '/').'\b/u', $original)) {
                continue;
            }
            if (Text::containsPhrase($normalized, $name)) {
                $occurrences = substr_count(' '.$normalized.' ', ' '.$key.' ');
                $found[$key] = new Suggestion(SuggestionType::Skill, $name, min(0.95, 0.6 + 0.1 * $occurrences), Text::excerpt($original, $name));
            }
        }

        return array_slice(array_values($found), 0, 25);
    }

    /** @return list<Suggestion> */
    private function area(string $normalized, string $original, Candidate $candidate): array
    {
        $scores = [];
        $evidence = [];
        foreach (self::AREA_KEYWORDS as $area => $words) {
            foreach ($words as $word) {
                $count = substr_count(' '.$normalized.' ', ' '.$word.' ');
                if ($count > 0) {
                    $scores[$area] = ($scores[$area] ?? 0) + $count * (str_contains($word, ' ') ? 2 : 1);
                    $evidence[$area] ??= Text::excerpt($original, $word);
                }
            }
        }
        if ($scores === []) {
            return [];
        }

        arsort($scores);
        $best = array_key_first($scores);
        if ($best === $candidate->professional_area?->value) {
            return [];
        }
        $total = array_sum($scores);
        $confidence = round(min(0.95, 0.4 + 0.6 * ($scores[$best] / $total) * min(1, $scores[$best] / 4)), 2);

        return [new Suggestion(SuggestionType::ProfessionalArea, ProfessionalArea::from($best)->value, $confidence, $evidence[$best] ?? null)];
    }
}
