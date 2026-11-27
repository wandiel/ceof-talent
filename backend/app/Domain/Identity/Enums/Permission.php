<?php

namespace App\Domain\Identity\Enums;

/**
 * Catálogo de permissões do CEOF TALENT ("recurso.ação").
 *
 * Permissões de módulos ainda não implementados já existem para que os perfis
 * permaneçam estáveis ao longo das fases. Toda verificação ocorre no backend
 * (Policies/Gates); o frontend apenas reflete o que o usuário pode fazer.
 */
enum Permission: string
{
    // Identidade e acesso
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case RolesView = 'roles.view';

    // Estrutura organizacional
    case OrganizationView = 'organization.view';
    case OrganizationManage = 'organization.manage';

    // Candidatos e banco de talentos (Fases 4 e 5)
    case CandidatesView = 'candidates.view';
    case CandidatesViewDepartment = 'candidates.view_department';
    case CandidatesManage = 'candidates.manage';
    case CandidatesDownloadDocuments = 'candidates.download_documents';
    case CandidatesDelete = 'candidates.delete';
    case TalentPoolManage = 'talent_pool.manage';

    // Vagas (Fase 6)
    case JobsView = 'jobs.view';
    case JobsRequest = 'jobs.request';
    case JobsApprove = 'jobs.approve';
    case JobsPublish = 'jobs.publish';
    case JobsManage = 'jobs.manage';

    // Processos seletivos (Fase 6)
    case RecruitmentView = 'recruitment.view';
    case RecruitmentMove = 'recruitment.move';
    case RecruitmentDecide = 'recruitment.decide';

    // Entrevistas (Fase 7)
    case InterviewsView = 'interviews.view';
    case InterviewsSchedule = 'interviews.schedule';
    case InterviewsEvaluate = 'interviews.evaluate';

    // Dashboard e relatórios (Fase 8)
    case DashboardView = 'dashboard.view';
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    // LGPD e auditoria (Fase 10)
    case PrivacyManage = 'privacy.manage';
    case AuditView = 'audit.view';

    public function label(): string
    {
        return match ($this) {
            self::UsersView => 'Visualizar usuários',
            self::UsersManage => 'Gerenciar usuários e perfis de acesso',
            self::RolesView => 'Visualizar perfis e permissões',
            self::OrganizationView => 'Visualizar unidades, departamentos e cargos',
            self::OrganizationManage => 'Gerenciar unidades, departamentos e cargos',
            self::CandidatesView => 'Visualizar todos os candidatos',
            self::CandidatesViewDepartment => 'Visualizar candidatos das vagas do próprio departamento',
            self::CandidatesManage => 'Cadastrar e editar candidatos',
            self::CandidatesDownloadDocuments => 'Baixar currículos e documentos',
            self::CandidatesDelete => 'Excluir/anonimizar candidatos',
            self::TalentPoolManage => 'Gerenciar favoritos e etiquetas do banco de talentos',
            self::JobsView => 'Visualizar vagas',
            self::JobsRequest => 'Solicitar vagas',
            self::JobsApprove => 'Aprovar vagas',
            self::JobsPublish => 'Publicar e encerrar vagas',
            self::JobsManage => 'Gerenciar todas as vagas',
            self::RecruitmentView => 'Visualizar processos seletivos',
            self::RecruitmentMove => 'Movimentar candidatos entre etapas',
            self::RecruitmentDecide => 'Aprovar, reprovar e contratar',
            self::InterviewsView => 'Visualizar entrevistas',
            self::InterviewsSchedule => 'Agendar entrevistas',
            self::InterviewsEvaluate => 'Registrar pareceres de entrevista',
            self::DashboardView => 'Visualizar dashboard',
            self::ReportsView => 'Visualizar relatórios',
            self::ReportsExport => 'Exportar relatórios',
            self::PrivacyManage => 'Gerenciar LGPD (titulares, consentimentos, retenção)',
            self::AuditView => 'Visualizar auditoria',
        };
    }

    public function group(): string
    {
        return match (explode('.', $this->value)[0]) {
            'users', 'roles' => 'Usuários e acesso',
            'organization' => 'Estrutura organizacional',
            'candidates', 'talent_pool' => 'Candidatos e banco de talentos',
            'jobs' => 'Vagas',
            'recruitment' => 'Processos seletivos',
            'interviews' => 'Entrevistas',
            'dashboard', 'reports' => 'Dashboard e relatórios',
            'privacy', 'audit' => 'LGPD e auditoria',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
