<?php

namespace App\Modules\Quoting\Models;

use App\Modules\Catalog\Models\EquipmentListing;
use App\Modules\Parts\Models\Part;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqItem extends Model
{
    public $timestamps = false;

    protected $casts = ['specs' => 'array'];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(EquipmentListing::class, 'listing_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
