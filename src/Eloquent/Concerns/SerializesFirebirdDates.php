<?php

namespace Benson\LaravelFirebird\Eloquent\Concerns;

trait SerializesFirebirdDates
{
    /**
     * Set a given attribute on the model.
     *
     * Attributes cast to a pure date (`date` / `immutable_date`) are stored
     * without a time component. Laravel formats every temporal value with a
     * single connection-wide format that includes the time, which a Firebird
     * `DATE` column (dialect 3) rejects with a conversion error. Datetime
     * casts keep their time as usual.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        if (! is_null($value)
            && ! $this->hasSetMutator($key)
            && ! $this->hasAttributeSetMutator($key)
            && $this->hasCast($key, ['date', 'immutable_date'])) {
            $this->attributes[$key] = $this->asDateTime($value)->format('Y-m-d');

            return $this;
        }

        return parent::setAttribute($key, $value);
    }
}
