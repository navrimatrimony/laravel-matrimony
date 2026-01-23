<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| AdminAuditLog Model
|--------------------------------------------------------------------------
|
| 👉 Represents admin action audit logs
| 👉 Tracks admin actions with reasons and entity references
|
*/
class AdminAuditLog extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Mass Assignable Fields
    |--------------------------------------------------------------------------
    */
    protected $fillable = [
        'admin_id',
        'action_type',
        'entity_type',
        'entity_id',
        'reason',
        'is_demo',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Admin who performed the action
     */
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}