<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use J87NL\Mailbox\Contracts\ResolvesAccessors;

class RelationAccessorResolver implements ResolvesAccessors
{
    public function accessorsFor(Authenticatable $user): array
    {
        $accessors = [];

        if ($user instanceof Model) {
            $accessors[] = ['id' => $user->getKey(), 'type' => $user->getMorphClass()];
        }

        foreach ($this->groups() as $group) {
            if (! method_exists($user, $group['relation'])) {
                continue;
            }

            foreach ($user->{$group['relation']} as $model) {
                $accessors[] = ['id' => $model->getKey(), 'type' => $model->getMorphClass()];
            }
        }

        return $accessors;
    }

    public function assignable(): array
    {
        return collect($this->sources())
            ->flatMap(fn (array $source): array => $source['model']::query()
                ->orderBy($source['label'])
                ->get()
                ->map(fn (Model $model): array => [
                    'key' => $model->getMorphClass().':'.$model->getKey(),
                    'label' => $source['prefix'].': '.$model->{$source['label']},
                ])
                ->all())
            ->values()
            ->all();
    }

    public function label(string $type, int|string $id): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        $source = collect($this->sources())->firstWhere('model', $class);

        if (! $source || ! class_exists($class)) {
            return null;
        }

        $model = $class::query()->find($id);

        return $model ? $source['prefix'].': '.$model->{$source['label']} : null;
    }

    private function groups(): array
    {
        return config('mailbox.access.groups', []);
    }

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
