<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassRelationship extends Model
{
    protected $fillable = ['class1', 'relationship', 'class2'];
}
