<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'amount'];

    public function logs()
    {
        return $this->hasMany(BudgetLog::class);
    }
    
    
}
