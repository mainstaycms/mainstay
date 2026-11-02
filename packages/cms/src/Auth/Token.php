<?php

namespace Mainstay\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* A named secret an account hands a program, holding capabilities of its
   own: `*` for every one its account holds. Made and revoked through
   Tokens. */
class Token extends Model
{
    protected $table = 'mainstay_tokens';

    protected $fillable = ['user_id', 'name', 'token', 'capabilities', 'last_used_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'last_used_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
