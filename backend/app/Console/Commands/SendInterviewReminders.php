<?php

namespace App\Console\Commands;

use App\Domain\Interviews\Enums\InterviewStatus;
use App\Models\Interview;
use App\Notifications\InterviewNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/** Lembrete por e-mail aos entrevistadores, nas 24 horas que antecedem a entrevista (uma vez). */
class SendInterviewReminders extends Command
{
    protected $signature = 'interviews:send-reminders';

    protected $description = 'Envia lembretes das entrevistas das próximas 24 horas';

    public function handle(): int
    {
        $sent = 0;
        Interview::query()
            ->with('interviewers')
            ->where('status', InterviewStatus::Scheduled->value)
            ->whereNull('reminded_at')
            ->whereBetween('scheduled_at', [now(), now()->addDay()])
            ->each(function (Interview $interview) use (&$sent) {
                Notification::send($interview->interviewers, new InterviewNotification($interview, InterviewNotification::REMINDER));
                $interview->forceFill(['reminded_at' => now()])->save();
                $sent++;
            });

        $this->info("Lembretes enviados: {$sent}.");

        return self::SUCCESS;
    }
}
