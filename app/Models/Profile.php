<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
class Profile extends Model
{
protected $fillable = [
    'user_id',
    'full_name',
    'gender',
    'date_of_birth',
    'education',
    'caste',
    'location',
];

   

public function user()
{
    return $this->belongsTo(User::class);
}

}
