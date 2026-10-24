<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * CU-U03: notifica a los responsables cuando se agrega un comentario.
 */
class TicketCommentedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly Comment $comment,
    ) {}

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
            ->subject("Nuevo comentario en ticket #{$this->ticket->id}")
            ->line("{$this->comment->author->name} comentó en \"{$this->ticket->title}\":")
            ->line($this->comment->body)
            ->action('Ver ticket', url("/tickets/{$this->ticket->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket.commented',
            'ticket_id' => $this->ticket->id,
            'comment_id' => $this->comment->id,
            'author_id' => $this->comment->user_id,
        ];
    }
}
