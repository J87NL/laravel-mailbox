<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class AccessQuery
{
    /**
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    public static function for(string $model, mixed $narrow): Builder
    {
        $query = $model::query();

        if (is_null($narrow)) {
            return $query;
        }

        $callback = is_string($narrow) && class_exists($narrow)
            ? app($narrow)
            : $narrow;

        if (! is_callable($callback)) {
            throw new InvalidArgumentException(sprintf('The access query of %s must be callable or an invokable class.', $model));
        }

        return $query->where(fn (Builder $constrained) => $callback($constrained));
    }

    public static function admitsUser(Authenticatable $user): bool
    {
        $model = config('mailbox.user_model');
        $narrow = config('mailbox.access.user_query');

        if (is_null($narrow) || ! $user instanceof Model || ! is_a($user, $model)) {
            return true;
        }

        return self::for($model, $narrow)
            ->whereKey($user->getKey())
            ->exists();
    }
}
