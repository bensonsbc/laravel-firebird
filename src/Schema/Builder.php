<?php

namespace Benson\LaravelFirebird\Schema;

use Illuminate\Database\Schema\Builder as BaseBuilder;

class Builder extends BaseBuilder
{
    /**
     * Drop all views from the database.
     *
     * @return void
     */
    public function dropAllViews()
    {
        $this->connection->disconnect();

        $views = array_column($this->getViews(), 'name');

        if ($views === []) {
            return;
        }

        foreach ($views as $view) {
            $this->connection->statement('DROP VIEW '.$this->grammar->wrapTable($view));
        }
    }
}
