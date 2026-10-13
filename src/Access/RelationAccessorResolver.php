<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use J87NL\Mailbox\Contracts\ResolvesAccessors;

class RelationAccessorResolver implements ResolvesAccessors
{
    /**
     * @var array<int, array{key: string, label: string}>|null
     */
    private ?array $assignable = null;

    public function accessorsFor(Authenticatable $user): array
    {
        $accessors = [];

        if ($user instanceof Model) {
            $accessors[] = [
                'id' => $user->getKey(),
                'type' => $user->getMorphClass(),
            ];
        }

        foreach ($this->groups() as $group) {
            if (! method_exists($user, $group['relation'])) {
                continue;
            }

            foreach ($user->{$group['relation']} as $model) {
                $accessors[] = [
                    'id' => $model->getKey(),
                    'type' => $model->getMorphClass(),
                ];
            }
        }

        return $accessors;
    }

    public function assignable(): array
    {
        return $this->assignable ??= collect($this->sources())
            ->flatMap(fn (array $source): array => $source['model']::query()
                ->orderBy($source['label'])
                ->get()
                ->map(fn (Model $model): array => [
                    'key' => sprintf('%s:%s', $model->getMorphClass(), $model->getKey()),
                    'label' => $this->describe($source, $model),
                ])
                ->all())
            ->values()
            ->all();
    }

    public function labels(array $accessors): array
    {
        return collect($accessors)
            ->groupBy('type')
            ->flatMap(fn (Collection $group, string $type): array => $this->labelsOfType($type, $group->pluck('id')->all()))
            ->all();
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<string, string>
     */
    private function labelsOfType(string $type, array $ids): array
    {
        $class = Relation::getMorphedModel($type) ?? $type;
        $source = collect($this->sources())->firstWhere('model', $class);

        if (is_null($source) || ! class_exists($class)) {
            return [];
        }

        return $class::query()
            ->whereKey($ids)
            ->get()
            ->mapWithKeys(fn (Model $model): array => [sprintf('%s:%s', $type, $model->getKey()) => $this->describe($source, $model)])
            ->all();
    }

    /**
     * @param  array{label: string, model: string, prefix: string}  $source
     */
    private function describe(array $source, Model $model): string
    {
        return sprintf('%s: %s', $source['prefix'], $model->getAttribute($source['label']));
    }

    /**
     * @return array<int, array{label?: string, model: string, prefix?: string, relation: string}>
     */
    private function groups(): array
    {
        return config('mailbox.access.groups', []);
    }

    /**
     * @return array<int, array{label: string, model: string, prefix: string}>
     */
    private function sources(): array
    {
        return [
            [
                'label' => config('mailbox.access.user_label', 'name'),
                'model' => config('mailbox.user_model'),
                'prefix' => __('mailbox::mailbox.accessor_user'),
            ],
            ...array_map(fn (array $group): array => [
                'label' => $group['label'] ?? 'name',
                'model' => $group['model'],
                'prefix' => $group['prefix'] ?? class_basename($group['model']),
            ], $this->groups()),
        ];
    }
}
