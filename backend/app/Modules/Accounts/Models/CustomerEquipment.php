<?php

namespace App\Modules\Accounts\Models;

use App\Modules\Accounts\Support\BelongsToAccount;
use App\Modules\Catalog\Models\EquipmentModel;
use App\Modules\Identity\Models\Organization;
use App\Modules\Platform\Models\Attachment;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** معدة في أسطول العميل (FR-ACC-05) */
class CustomerEquipment extends Model
{
    use BelongsToAccount;

    protected $table = 'customer_equipment';

    protected $casts = [
        'year' => 'integer',
        'current_hour_meter' => 'integer',
        'hour_meter_updated_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function personalColumn(): string
    {
        return 'owner_user_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function equipmentModel(): BelongsTo
    {
        return $this->belongsTo(EquipmentModel::class, 'model_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'site_address_id');
    }

    public function nameplate(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'nameplate_photo_id');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(HourMeterReading::class)->orderByDesc('recorded_at');
    }

    /** الماركة والموديل من الكتالوج، أو كما كتبهما العميل */
    public function machineName(): string
    {
        $model = $this->equipmentModel;

        return $model
            ? trim("{$model->brand->name} {$model->model_name}")
            : trim("{$this->brand_text} {$this->model_text}");
    }

    public function displayName(): string
    {
        return $this->nickname ? "{$this->nickname} · {$this->machineName()}" : $this->machineName();
    }
}
