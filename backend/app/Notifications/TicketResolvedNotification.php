<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * CU-T03: notifica al usuario original pidiéndole confirmación de cierre.
 */
class TicketResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Ticket $ticket) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Ticket #{$this->ticket->id} resuelto: {$this->ticket->title}")
            ->greeting("Hola {$notifiable->name},")
            ->line('Tu incidente fue marcado como RESUELTO por el equipo de soporte.')
            ->line('Solución registrada: '.$this->ticket->resolution)
            ->line('Por favor confirmá si la solución fue satisfactoria para cerrar formalmente el ticket.')
            ->action('Ver ticket', url("/tickets/{$this->ticket->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket.resolved',
            'ticket_id' => $this->ticket->id,
            'title' => $this->ticket->title,
        ];
    }
}
