<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Models\MailboxMessageLink;

/**
 * @extends HasMany<MailboxMessageLink, MailboxMessage>
 */
class MessageLinks extends HasMany
{
    public function addConstraints(): void
    {
        parent::addConstraints();

        if (! static::$constraints) {
            return;
        }

        $this->query->where($this->related->qualifyColumn('mailbox_id'), $this->parent->getAttribute('mailbox_id'));
    }

    /**
     * @param  array<int, MailboxMessage>  $models
     * @param  EloquentCollection<int, MailboxMessageLink>  $results
     * @param  string  $relation
     * @return array<int, MailboxMessage>
     */
    public function match(array $models, EloquentCollection $results, $relation): array
    {
        $dictionary = $results->groupBy(fn (MailboxMessageLink $link): string => $this->dictionaryKeyFor($link->mailbox_id, $link->message_id));

        foreach ($models as $model) {
            $key = $this->dictionaryKeyFor($model->getAttribute('mailbox_id'), $model->getAttribute('message_id'));

            $model->setRelation($relation, $this->related->newCollection($dictionary->get($key)?->all() ?? []));
        }

        return $models;
    }

    /**
     * @param  Builder<MailboxMessageLink>  $query
     * @param  Builder<MailboxMessage>  $parentQuery
     * @param  mixed  $columns
     * @return Builder<MailboxMessageLink>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        return parent::getRelationExistenceQuery($query, $parentQuery, $columns)
            ->whereColumn($this->related->qualifyColumn('mailbox_id'), $this->parent->qualifyColumn('mailbox_id'));
    }

    private function dictionaryKeyFor(mixed $mailboxId, mixed $messageId): string
    {
        return sprintf('%s|%s', $mailboxId, $messageId);
    }
}
