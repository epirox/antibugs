<?php

namespace App\Http\Resources;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Comment */
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'attachmentUrl' => $this->attachment_path ? Storage::url($this->attachment_path) : null,
            'author' => new UserResource($this->whenLoaded('author')),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
