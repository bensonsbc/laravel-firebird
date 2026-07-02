<?php

namespace Benson\LaravelFirebird\Concerns;

use Illuminate\Support\Str;

trait WrapsIdentifiers
{
    /**
     * Identifiers that are problematic enough to quote even in legacy mode.
     *
     * @var string[]
     */
    protected $reservedIdentifiers = [
        'KEY',
        'TIMESTAMP',
        'VALUE',
    ];

    /**
     * Determine whether identifiers should be quoted.
     *
     * @return bool
     */
    protected function shouldQuoteIdentifiers()
    {
        return $this->connection->getConfig('quote_identifiers', true) !== false;
    }

    /**
     * Determine whether unquoted identifiers should be uppercased.
     *
     * @return bool
     */
    protected function shouldUppercaseIdentifiers()
    {
        return $this->connection->getConfig('uppercase_identifiers', false) === true;
    }

    /**
     * Normalize an identifier segment for legacy Firebird schemas.
     *
     * @param  string  $value
     * @return string
     */
    protected function normalizeIdentifier($value)
    {
        if (! $this->shouldUppercaseIdentifiers() || ! preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $value)) {
            return $value;
        }

        return Str::upper($value);
    }

    /**
     * Determine whether a value is already explicitly quoted.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isAlreadyQuoted($value)
    {
        // Require a single fully quoted identifier; embedded quotes must be
        // escaped so values like `"a" or "b"` are not passed through raw.
        return preg_match('/^"(?:[^"]|"")*"$/', $value) === 1;
    }

    /**
     * Determine whether an identifier should be protected as reserved.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isReservedIdentifier($value)
    {
        return in_array(Str::upper($value), $this->reservedIdentifiers, true);
    }

    /**
     * Quote an identifier, escaping embedded double quotes.
     *
     * @param  string  $value
     * @return string
     */
    protected function quoteIdentifier($value)
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
