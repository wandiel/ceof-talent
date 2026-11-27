<?php

namespace App\Domain\Privacy;

use App\Domain\Candidates\Support\Cpf;
use App\Models\Candidate;

/**
 * Relatório dos dados pessoais de um titular (acesso e portabilidade, art. 18, II e V).
 *
 * Traz o que a pessoa informou ou que foi registrado sobre ela, em formato estruturado (JSON).
 * As anotações internas e os pareceres das entrevistas não entram: são avaliações da equipe
 * protegidas por segredo do processo seletivo; a existência delas é informada.
 */
class CandidateDataExport
{
    /** @return array<string, mixed> */
    public function build(Candidate $c): array
    {
        $c->loadMissing([
            'educations', 'experiences', 'courses', 'skills', 'documents', 'consents', 'portalSubmissions',
            'applications.jobOpening:id,code,title', 'applications.history', 'applications.interviews',
        ]);
        $date = fn ($d) => $d?->toIso8601String();

        return [
            'gerado_em' => now()->toIso8601String(),
            'controlador' => config('privacy.controller'),
            'titular' => [
                'nome' => $c->full_name,
                'nome_social' => $c->social_name,
                'email' => $c->email,
                'telefone' => $c->phone,
                'telefone_secundario' => $c->phone_secondary,
                'cpf' => Cpf::format($c->cpf),
                'data_nascimento' => $c->birth_date?->toDateString(),
                'cep' => $c->zip_code,
                'bairro' => $c->neighborhood,
                'cidade' => $c->city,
                'uf' => $c->state,
                'linkedin' => $c->linkedin_url,
                'portfolio' => $c->portfolio_url,
                'area_profissional' => $c->professional_area?->label(),
                'escolaridade' => $c->education_level?->label(),
                'cargo_pretendido' => $c->desired_position,
                'resumo' => $c->summary,
                'pretensao_salarial' => $c->salary_expectation,
                'disponibilidade' => $c->availability?->label(),
                'turnos' => $c->work_shifts,
                'aceita_mudar_de_cidade' => $c->willing_to_relocate,
                'origem' => $c->source?->label(),
                'situacao' => $c->status?->label(),
                'cadastrado_em' => $date($c->created_at),
                'atualizado_em' => $date($c->updated_at),
            ],
            'formacao' => $c->educations->map(fn ($e) => ['nivel' => $e->level?->label(), 'instituicao' => $e->institution, 'curso' => $e->course, 'situacao' => $e->status?->label(), 'inicio' => $e->start_year, 'conclusao' => $e->end_year])->all(),
            'experiencias' => $c->experiences->map(fn ($e) => ['empresa' => $e->company, 'cargo' => $e->job_title, 'inicio' => $e->start_date?->format('Y-m'), 'fim' => $e->end_date?->format('Y-m'), 'atual' => $e->is_current, 'descricao' => $e->description])->all(),
            'cursos' => $c->courses->map(fn ($e) => ['tipo' => $e->type?->label(), 'nome' => $e->name, 'instituicao' => $e->institution, 'ano' => $e->year, 'carga_horaria' => $e->workload_hours])->all(),
            'competencias' => $c->skills->pluck('name')->all(),
            'documentos' => $c->documents->map(fn ($d) => ['tipo' => $d->type->label(), 'arquivo' => $d->original_name, 'enviado_em' => $date($d->created_at)])->all(),
            'processos_seletivos' => $c->applications->map(fn ($a) => [
                'vaga' => $a->jobOpening->code.' · '.$a->jobOpening->title,
                'inscrito_em' => $date($a->created_at),
                'etapa_atual' => $a->stage->label(),
                'etapas' => $a->history->map(fn ($h) => ['etapa' => $h->to_stage->label(), 'em' => $date($h->created_at)])->all(),
                'entrevistas' => $a->interviews->map(fn ($i) => ['data' => $date($i->scheduled_at), 'tipo' => $i->type->label(), 'situacao' => $i->status->label()])->all(),
            ])->all(),
            'consentimentos' => $c->consents->map(fn ($k) => ['finalidade' => $k->purpose->label(), 'versao_aviso' => $k->version, 'canal' => $k->channel, 'concedido_em' => $date($k->granted_at), 'revogado_em' => $date($k->revoked_at)])->all(),
            'envios_pelo_portal' => $c->portalSubmissions->map(fn ($s) => ['protocolo' => $s->protocol, 'em' => $date($s->created_at)])->all(),
            'observacao' => 'Anotações internas e pareceres de entrevistas são avaliações da equipe e não constam deste relatório. Dúvidas: fale com o encarregado de dados.',
        ];
    }
}
