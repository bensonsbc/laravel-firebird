<?php

namespace Benson\LaravelFirebird\Tests\Support\Models;

use Benson\LaravelFirebird\Tests\Support\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public static $factory = UserFactory::class;

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
