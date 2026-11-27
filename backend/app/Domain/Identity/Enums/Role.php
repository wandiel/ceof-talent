<?php

namespace App\Domain\Identity\Enums;

use App\Domain\Identity\Enums\Permission as P;

/**
 * Perfis de acesso do CEOF TALENT e suas permissões.
 * Fonte única da matriz documentada em docs/01-planejamento-arquitetura.md (seção 8).
 */
enum Role: string
{
    case Admin = 'admin';
    case HrManager = 'hr_manager';
    case HrAnalyst = 'hr_analyst';
    case Recruiter = 'recruiter';
    case DepartmentManager = 'department_manager';
    case Interviewer = 'interviewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::HrManager => 'Gestor de RH',
            self::HrAnalyst => 'Analista de RH',
            self::Recruiter => 'Recrutador',
            self::DepartmentManager => 'Gestor de departamento',
            self::Interviewer => 'Entrevistador',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Acesso total, incluindo usuários, perfis e auditoria.',
            self::HrManager => 'Gestão completa do recrutamento, vagas, relatórios e LGPD.',
            self::HrAnalyst => 'Operação de candidatos, vagas e processos seletivos.',
            self::Recruiter => 'Triagem, movimentação de candidatos e entrevistas.',
            self::DepartmentManager => 'Solicita e aprova vagas e decide sobre candidatos do próprio departamento.',
            self::Interviewer => 'Acessa as entrevistas atribuídas e registra pareceres.',
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => P::cases(),
            self::HrManager => [
                P::UsersView, P::RolesView,
                P::OrganizationView, P::OrganizationManage,
                P::CandidatesView, P::CandidatesManage, P::CandidatesDownloadDocuments, P::CandidatesDelete, P::TalentPoolManage,
                P::JobsView, P::JobsRequest, P::JobsApprove, P::JobsPublish, P::JobsManage,
                P::RecruitmentView, P::RecruitmentMove, P::RecruitmentDecide,
                P::InterviewsView, P::InterviewsSchedule, P::InterviewsEvaluate,
                P::DashboardView, P::ReportsView, P::ReportsExport,
                P::PrivacyManage, P::AuditView,
            ],
            self::HrAnalyst => [
                P::OrganizationView,
                P::CandidatesView, P::CandidatesManage, P::CandidatesDownloadDocuments, P::TalentPoolManage,
                P::JobsView, P::JobsRequest,
                P::RecruitmentView, P::RecruitmentMove,
                P::InterviewsView, P::InterviewsSchedule, P::InterviewsEvaluate,
                P::DashboardView, P::ReportsView, P::ReportsExport,
            ],
            self::Recruiter => [
                P::OrganizationView,
                P::CandidatesView, P::CandidatesManage, P::CandidatesDownloadDocuments, P::TalentPoolManage,
                P::JobsView,
                P::RecruitmentView, P::RecruitmentMove,
                P::InterviewsView, P::InterviewsSchedule, P::InterviewsEvaluate,
                P::DashboardView, P::ReportsView,
            ],
            self::DepartmentManager => [
                P::OrganizationView,
                P::CandidatesViewDepartment, P::CandidatesDownloadDocuments,
                P::JobsView, P::JobsRequest, P::JobsApprove,
                P::RecruitmentView, P::RecruitmentDecide,
                P::InterviewsView, P::InterviewsSchedule, P::InterviewsEvaluate,
                P::DashboardView, P::ReportsView,
            ],
            self::Interviewer => [
                P::InterviewsView, P::InterviewsEvaluate,
            ],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
