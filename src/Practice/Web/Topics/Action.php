<?php

declare(strict_types=1);

namespace App\Practice\Web\Topics;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The topic list.
 *
 * Administrators only, which in this application means: declared inside the admin route group. There
 * is no check in here, because there is no check in any action here — the group is the boundary.
 */
final readonly class Action
{
    public function __construct(private TopicsPage $page) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page->render($request);
    }
}
