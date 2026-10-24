<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'description',
    'status',
    'category_id',
    'priority_id',
    'created_by',
    'assigned_to',
    'resolution',
    'resolved_at',
    'closed_at',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Priority, $this> */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->latest();
    }

    /** @return HasMany<TicketStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(TicketStatusHistory::class)->latest();
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assigned_to === $user->id;
    }
}
