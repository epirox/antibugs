<?php

namespace App\Models;

use Database\Factories\PriorityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'level', 'sla_hours'])]
class Priority extends Model
{
    /** @use HasFactory<PriorityFactory> */
    use HasFactory;

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
