<?php

namespace MohammedMojaly\Laralyze\Alerts;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AlertNotification extends Notification
{
    public function __construct(public Message $message) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('['.config('app.name').'] '.$this->message->heading());

        if (! in_array($this->message->event, ['recovered', 'test'], true)) {
            $mail->error();
        }

        foreach ($this->message->lines() as $line) {
            $mail->line($line);
        }

        return $this->message->url === '' ? $mail : $mail->action('Open in Laralyze', $this->message->url);
    }
}
