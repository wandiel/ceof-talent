<?php

/*
| Aviso de privacidade do portal "Trabalhe conosco" (LGPD).
| Ao mudar o texto do aviso de forma relevante, aumente a versão: os próximos
| aceites ficam registrados com a nova versão.
*/
return [
    'notice_version' => env('PRIVACY_NOTICE_VERSION', '2026.1'),
    'notice_updated_at' => env('PRIVACY_NOTICE_UPDATED_AT', '2026-11-27'),
    'controller' => env('PRIVACY_CONTROLLER', 'Hospital de Olhos CEOF'),
    'dpo_name' => env('PRIVACY_DPO_NAME'),
    'dpo_email' => env('PRIVACY_DPO_EMAIL'),
    // Por quanto tempo o currículo fica no banco de talentos sem nova atividade.
    'talent_pool_retention_months' => (int) env('PRIVACY_TALENT_POOL_MONTHS', 24),

    /*
    | Retenção (aplicada todo dia pelo comando privacy:apply-retention).
    */
    // Quem recusou ou revogou o banco de talentos: meses após a última atividade.
    'no_consent_retention_months' => (int) env('PRIVACY_NO_CONSENT_MONTHS', 6),
    // Cadastros excluídos são anonimizados depois deste prazo (permite desfazer engano).
    'deleted_grace_days' => (int) env('PRIVACY_DELETED_GRACE_DAYS', 30),
    // Contratados não são anonimizados automaticamente (dados seguem para o registro de empregado).
    'keep_hired' => (bool) env('PRIVACY_KEEP_HIRED', true),
    // Trilha de auditoria: meses de guarda (padrão 5 anos).
    'audit_retention_months' => (int) env('PRIVACY_AUDIT_MONTHS', 60),
    // A rotina diária anonimiza de fato (true) ou só lista o que venceu (false).
    'retention_enabled' => (bool) env('PRIVACY_RETENTION_ENABLED', true),

    /*
    | Registro das operações de tratamento (art. 37). Aparece na tela LGPD.
    */
    'processing_activities' => [
        [
            'name' => 'Processo seletivo',
            'purpose' => 'Avaliar candidaturas, conduzir entrevistas e contatar candidatos.',
            'legal_basis' => 'Procedimentos preliminares a contrato, a pedido do titular (art. 7º, V)',
            'data' => 'Identificação, contato, formação, experiência, currículo, avaliações da equipe',
            'retention' => 'Durante o processo e pelo prazo de retenção configurado',
            'shared_with' => 'Equipe de RH, gestores e entrevistadores do processo; hospedagem do sistema',
        ],
        [
            'name' => 'Banco de talentos',
            'purpose' => 'Considerar o candidato em vagas futuras.',
            'legal_basis' => 'Consentimento (art. 7º, I), registrado com versão do aviso; cadastros feitos pelo RH: legítimo interesse (art. 7º, IX)',
            'data' => 'Identificação, contato, perfil profissional e currículo',
            'retention' => 'Prazo do banco de talentos após a última atividade, ou até a revogação',
            'shared_with' => 'Equipe de RH',
        ],
        [
            'name' => 'Leitura automática de currículos',
            'purpose' => 'Organizar o texto do currículo para busca e sugerir competências (sem decisão automática).',
            'legal_basis' => 'A mesma do processo seletivo ou do banco de talentos',
            'data' => 'Texto do currículo',
            'retention' => 'Junto com o currículo',
            'shared_with' => 'Ninguém: processamento no próprio servidor',
        ],
        [
            'name' => 'Segurança e auditoria',
            'purpose' => 'Registrar acessos e alterações para segurança e prestação de contas.',
            'legal_basis' => 'Cumprimento de obrigação legal e exercício regular de direitos (art. 7º, II e VI)',
            'data' => 'Usuário, data, IP, navegador e ação realizada',
            'retention' => 'Prazo de guarda da auditoria',
            'shared_with' => 'Administradores; autoridades quando exigido',
        ],
    ],
];
