<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
