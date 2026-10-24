<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use J87NL\Mailbox\Contracts\ResolvesAccessors;
use ReflectionClass;

class ExistingAccessor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isAssignable($value)) {
            $fail('validation.exists')->translate();
        }
    }

    private function isAssignable(string $key): bool
    {
        $parts = explode(':', $key, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        if (! $this->isMorphClass($parts[0])) {
            return false;
        }

        $labels = app(ResolvesAccessors::class)->labels([
            [
                'id' => $parts[1],
                'type' => $parts[0],
            ],
        ]);

        return array_key_exists($key, $labels);
    }

    private function isMorphClass(string $type): bool
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return false;
        }

        if (! (new ReflectionClass($class))->isInstantiable()) {
            return false;
        }

        return (new $class)->getMorphClass() === $type;
    }
}
