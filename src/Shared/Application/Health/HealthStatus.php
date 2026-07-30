<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

enum HealthStatus: string
{
    case Ok = 'ok';

    /**
     * The application runs, but something will bite later: a credential that is still unset, a file
     * mode that is too permissive, a retry budget that exceeds the web server's patience.
     */
    case Warning = 'warning';

    case Failure = 'failure';

    /**
     * Failure dominates warning, warning dominates ok, so an overall verdict never looks better than
     * its worst component.
     */
    public function worseOf(self $other): self
    {
        $rank = [self::Ok->value => 0, self::Warning->value => 1, self::Failure->value => 2];

        return $rank[$this->value] >= $rank[$other->value] ? $this : $other;
    }
}
