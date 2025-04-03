<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DynamicSection extends Model
{
    use HasFactory;
    protected $fillable = ['title', 'subtitle', 'description', 'image']; // Add other fields as needed
}
