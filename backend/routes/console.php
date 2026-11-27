<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Lembrete das entrevistas das próximas 24 horas (Fase 7).
Schedule::command('interviews:send-reminders')->hourly()->withoutOverlapping();

// Política de retenção da LGPD: anonimiza cadastros vencidos e remove auditoria antiga (Fase 10).
Schedule::command('privacy:apply-retention')->dailyAt('03:15')->withoutOverlapping()->onOneServer();
