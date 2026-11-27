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
];
