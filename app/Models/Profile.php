<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
class Profile extends Model
{
	protected $fillable = [
    'full_name',
	'gender',
    'date_of_birth',
    'education',
    'location',
];

   

public function user()
{
    return $this->belongsTo(User::class);
}

}
