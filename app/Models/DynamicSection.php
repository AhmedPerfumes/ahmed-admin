<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DynamicSection extends Model
{
    use HasFactory;
    protected $fillable = ['heading','description','link','image','video1','video2']; // Add other fields as needed
}
