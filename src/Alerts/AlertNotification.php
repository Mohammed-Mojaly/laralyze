<?php

namespace MohammedMojaly\Laralyze\Alerts;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AlertNotification extends Notification
{
    /**
     * @param  list<Alert>  $alerts
     */
    public function __construct(public array $alerts) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $first = $this->alerts[0];
        $more = count($this->alerts) - 1;

        $mail = (new MailMessage)
            ->error()
            ->subject('['.config('app.name').'] '.$first->title.($more > 0 ? " and {$more} more" : ''));

        foreach ($this->alerts as $alert) {
            $mail->line("**{$alert->title}**")->line($alert->body)->line($alert->url);
        }

        return $mail->action('Open Laralyze', $first->url);
    }
}
