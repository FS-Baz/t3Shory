<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Idea extends Model
{
    protected $fillable = [
        'idea',
        'verdict',
        'confidence',
        'go_probability',
        'dont_probability',
        'model',
    ];

       protected $casts = [
        'confidence' => 'integer',
        'go_probability' => 'integer',
        'dont_probability' => 'integer',
    ];
}
