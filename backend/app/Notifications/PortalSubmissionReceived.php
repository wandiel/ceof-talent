<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Comprovante enviado ao candidato após o envio pelo portal. */
class PortalSubmissionReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $protocol,
        public readonly string $name,
        public readonly ?string $jobTitle,
        public readonly ?string $jobCode,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = rtrim((string) config('app.url'), '/');
        $first = explode(' ', trim($this->name))[0];

        $mail = (new MailMessage)
            ->subject('Recebemos o seu currículo · Hospital de Olhos CEOF')
            ->greeting("Olá, {$first}!")
            ->line($this->jobTitle
                ? "Recebemos a sua candidatura para a vaga **{$this->jobTitle}** ({$this->jobCode})."
                : 'Recebemos o seu currículo para o nosso banco de talentos.')
            ->line("Protocolo: **{$this->protocol}**")
            ->line('A equipe de Recursos Humanos vai analisar as informações. Se o seu perfil seguir no processo, entraremos em contato pelo e-mail ou telefone informados.')
            ->action('Ver vagas abertas', $base.'/trabalhe-conosco')
            ->line('Os seus dados são tratados conforme o nosso aviso de privacidade: '.$base.'/privacidade')
            ->salutation('Equipe de Recursos Humanos · Hospital de Olhos CEOF');

        return $mail;
    }
}
