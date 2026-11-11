<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Testing;

use Illuminate\Support\Str;
use J87NL\Mailbox\Contracts\ClassifiesSpam;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Spam\SpamVerdict;
use Throwable;

class FakeSpamClassifier implements ClassifiesSpam
{
    /**
     * @var array<int, int>
     */
    public array $classified = [];

    public ?Throwable $failure = null;

    /**
     * @var array<string, SpamVerdict>
     */
    private array $senders = [];

    public function __construct(
        public SpamVerdict $verdict = new SpamVerdict(null),
    ) {}

    public static function install(?SpamVerdict $verdict = null): self
    {
        $classifier = new self($verdict ?? SpamVerdict::none());

        app()->instance(ClassifiesSpam::class, $classifier);

        return $classifier;
    }

    public function score(float $score, ?string $reason = null): self
    {
        $this->verdict = new SpamVerdict($score, $reason);

        return $this;
    }

    public function scoreSender(string $email, float $score, ?string $reason = null): self
    {
        $this->senders[Str::lower($email)] = new SpamVerdict($score, $reason);

        return $this;
    }

    public function classify(MailboxMessage $message): SpamVerdict
    {
        $this->classified[] = $message->id;

        if (! is_null($this->failure)) {
            throw $this->failure;
        }

        return $this->senders[Str::lower((string) $message->from_email)] ?? $this->verdict;
    }
}
