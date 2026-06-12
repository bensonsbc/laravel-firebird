<?php

namespace Benson\LaravelFirebird\Concerns;

use Illuminate\Support\Str;

trait NormalizesObjectNames
{
    /**
     * Normalize a database object lookup for legacy uppercase schemas.
     *
     * @param  string  $name
     * @return string
     */
    protected function normalizeObjectName($name)
    {
        return $this->connection->getConfig('uppercase_identifiers', false) === true
            ? Str::upper($name)
            : $name;
    }
}
