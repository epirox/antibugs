<?php

namespace App\Http\Controllers\Api\V1;

use App\DTOs\ChangeTicketStatusData;
use App\DTOs\CreateTicketData;
use App\DTOs\TicketFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketStatusRequest;
use App\Http\Resources\TicketResource;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Gateway HTTP: valida la entrada y delega toda la lógica al TicketService.
 */
class TicketController extends Controller
{
    public function __construct(private readonly TicketService $service) {}

    /**
     * GET /api/v1/tickets
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = TicketFilters::fromQuery($request->query());

        return TicketResource::collection($this->service->list($request->user(), $filters));
    }

    /**
     * POST /api/v1/tickets
     */
    public function store(StoreTicketRequest $request): JsonResponse
    {
        $ticket = $this->service->create(
            CreateTicketData::fromArray($request->validated(), $request->user()->id)
        );

        return (new TicketResource($ticket))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/tickets/{id}
     */
    public function show(Request $request, int $id): TicketResource
    {
        $ticket = $this->service->find($id);

        $this->authorize('view', $ticket);

        return new TicketResource($ticket->load(['comments.author', 'statusHistory.actor', 'statusHistory.assignee']));
    }

    /**
     * PATCH /api/v1/tickets/{id}/status
     */
    public function updateStatus(UpdateTicketStatusRequest $request, int $id): TicketResource
    {
        $ticket = $this->service->changeStatus(
            $id,
            ChangeTicketStatusData::fromArray($request->validated()),
            $request->user(),
        );

        return new TicketResource($ticket);
    }
}
