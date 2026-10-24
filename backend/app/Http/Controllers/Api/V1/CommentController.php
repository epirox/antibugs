<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;

class CommentController extends Controller
{
    public function __construct(private readonly TicketService $service) {}

    /**
     * POST /api/v1/tickets/{ticket}/comments (CU-U03)
     */
    public function store(StoreCommentRequest $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('comment', $ticket);

        $attachmentPath = $request->hasFile('attachment')
            ? $request->file('attachment')->store("tickets/{$ticket->id}", 'public')
            : null;

        $comment = $this->service->addComment(
            $ticket,
            $request->user(),
            $request->validated('body'),
            $attachmentPath,
        );

        return (new CommentResource($comment))->response()->setStatusCode(201);
    }
}
