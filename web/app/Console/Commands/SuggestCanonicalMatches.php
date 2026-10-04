<?php

namespace App\Console\Commands;

use App\Services\Matching\MatchSuggester;
use Illuminate\Console\Command;

class SuggestCanonicalMatches extends Command
{
    protected $signature = 'canonical:suggest';

    protected $description = 'Re-score unconfirmed store products against canonical items and write fuzzy-match suggestions (never confirms anything)';

    public function handle(): int
    {
        $counts = MatchSuggester::fromConfig()->run();

        $this->info(sprintf(
            '%d new/updated suggestions, %d stale suggestions withdrawn, %d unchanged.',
            $counts['suggested'],
            $counts['withdrawn'],
            $counts['unchanged'],
        ));

        return self::SUCCESS;
    }
}
