<?php

namespace App\Models;

use App\Support\Devices\Questions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Item extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'subject_id';

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['owner_user_id', 'identifier_ciphertext', 'identifier_hmac', 'identifier_key_version'];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(ItemObservation::class, 'item_id', 'subject_id');
    }

    public function storageAsset(): HasOne
    {
        return $this->hasOne(StorageAsset::class, 'subject_id', 'subject_id');
    }

    public function isPart(): bool
    {
        return $this->intake_class === 'standalone_part';
    }

    public function subtypeLabel(): ?string
    {
        if ($this->intake_class === 'processor_derived') {
            return ['hdd' => 'HDD', 'ssd' => 'SSD', 'other_nonvolatile' => 'Other nonvolatile storage'][$this->subtype];
        }

        return Questions::subtypes($this->category->code)[$this->subtype ?? ''] ?? null;
    }
}
