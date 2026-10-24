<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
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

    public function search(string $term, int $limit): array
    {
        $pattern = sprintf('%%%s%%', str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term));

        return collect($this->sources())
            ->flatMap(fn (array $source): array => $source['model']::query()
                ->where(function (Builder $query) use ($source, $pattern): void {
                    foreach ($source['search'] as $column) {
                        $query->orWhereRaw(
                            sprintf("LOWER(%s) LIKE LOWER(?) ESCAPE '!'", $query->getQuery()->getGrammar()->wrap($column)),
                            [$pattern],
                        );
                    }
                })
                ->orderByRaw(sprintf('LOWER(%s)', (new $source['model'])->getConnection()->getQueryGrammar()->wrap($source['label'])))
                ->limit($limit)
                ->get()
                ->map(fn (Model $model): array => [
                    'key' => sprintf('%s:%s', $model->getMorphClass(), $model->getKey()),
                    'label' => $this->describe($source, $model),
                ])
                ->all())
            ->sortBy(fn (array $accessor): string => mb_strtolower($accessor['label']))
            ->take($limit)
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
     * @param  array{label: string, model: string, prefix: string, search: array<int, string>}  $source
     */
    private function describe(array $source, Model $model): string
    {
        return sprintf('%s: %s', $source['prefix'], $model->getAttribute($source['label']));
    }

    /**
     * @return array<int, array{label?: string, model: string, prefix?: string, relation: string, search?: array<int, string>}>
     */
    private function groups(): array
    {
        return config('mailbox.access.groups', []);
    }

    /**
     * @return array<int, array{label: string, model: string, prefix: string, search: array<int, string>}>
     */
    private function sources(): array
    {
        $userLabel = config('mailbox.access.user_label', 'name');

        return [
            [
                'label' => $userLabel,
                'model' => config('mailbox.user_model'),
                'prefix' => __('mailbox::mailbox.accessor_user'),
                'search' => array_values(array_filter(config('mailbox.access.user_search') ?? [])) ?: [
                    $userLabel,
                    'email',
                ],
            ],
            ...array_map(fn (array $group): array => [
                'label' => $group['label'] ?? 'name',
                'model' => $group['model'],
                'prefix' => $group['prefix'] ?? class_basename($group['model']),
                'search' => array_values(array_filter($group['search'] ?? [])) ?: [$group['label'] ?? 'name'],
            ], $this->groups()),
        ];
    }
}
