<?php

namespace App\Notifications;

use App\Models\Interview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso por e-mail aos entrevistadores: entrevista agendada, remarcada,
 * cancelada ou lembrete na véspera. Não leva dados pessoais além do nome
 * do candidato; o restante fica no sistema, atrás do login.
 */
class InterviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const SCHEDULED = 'scheduled';

    public const RESCHEDULED = 'rescheduled';

    public const CANCELLED = 'cancelled';

    public const REMINDER = 'reminder';

    public function __construct(public readonly Interview $interview, public readonly string $kind) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $interview = $this->interview->loadMissing('application.candidate', 'application.jobOpening');
        $candidate = $interview->application->candidate;
        $job = $interview->application->jobOpening;
        $when = $interview->scheduled_at->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i');
        $url = rtrim((string) config('app.url'), '/').'/entrevistas/'.$interview->uuid;
        $name = $candidate->social_name ?: $candidate->full_name;

        $subject = match ($this->kind) {
            self::SCHEDULED => 'Entrevista agendada',
            self::RESCHEDULED => 'Entrevista remarcada',
            self::CANCELLED => 'Entrevista cancelada',
            default => 'Lembrete: entrevista amanhã',
        };

        $mail = (new MailMessage)
            ->subject("{$subject} · {$job->code} · ".config('app.name'))
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line(match ($this->kind) {
                self::SCHEDULED => "Você foi indicado(a) como entrevistador(a) de {$name} para a vaga {$job->code} · {$job->title}.",
                self::RESCHEDULED => "A entrevista de {$name} para a vaga {$job->code} · {$job->title} mudou.",
                self::CANCELLED => "A entrevista de {$name} para a vaga {$job->code} · {$job->title} foi cancelada.",
                default => "Lembrete da entrevista de {$name} para a vaga {$job->code} · {$job->title}.",
            });

        if ($this->kind !== self::CANCELLED) {
            $mail->line("Quando: {$when} ({$interview->duration_minutes} minutos)")
                ->line('Formato: '.$interview->mode->label().($interview->location ? " · {$interview->location}" : ''));
        } elseif ($interview->status_reason) {
            $mail->line('Motivo: '.$interview->status_reason);
        }

        return $mail->action('Abrir entrevista', $url)->salutation('Equipe '.config('app.name'));
    }
}
