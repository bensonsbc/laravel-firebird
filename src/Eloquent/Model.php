<?php

namespace Benson\LaravelFirebird\Eloquent;

use Benson\LaravelFirebird\Eloquent\Concerns\SerializesFirebirdDates;
use Illuminate\Database\Eloquent\Model as BaseModel;

abstract class Model extends BaseModel
{
    use SerializesFirebirdDates;
}
