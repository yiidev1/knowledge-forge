<?php

declare(strict_types=1);

namespace App\OrderTesting\Web;

use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * The one way the Practice Results pages link to a demo order's comparison.
 *
 * ## Why one class
 *
 * The comparison is reached from four places on these pages — a score in the practitioners table, the
 * row's own View Comparison, each try in the drawer, and each unmatched result — and every one of them
 * must land on the comparison of exactly the demo order it shows. Building that address in four
 * templates is four chances to pass the wrong id, so it is built here, once, from the only three
 * things that identify a comparison: the store, the source order and the demo order.
 *
 * The route name comes from the page, because the administrators' and the agents' surfaces mount the
 * same comparison at different addresses. Nothing here decides who may read it; the comparison page
 * checks that again for every request.
 *
 * ## Always a new tab
 *
 * Each link opens the existing comparison page beside the results rather than in place of them, so a
 * reviewer can open several tries and keep the table, its search and its page where they were.
 */
final readonly class ComparisonLinks
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        /** The surface's own name for the comparison page — see each action's `surfaceRoutes()`. */
        private string $route,
        private int $sourceId,
        private int $sourceOrderId,
    ) {}

    public function url(int $demoOrderId): string
    {
        return $this->urlGenerator->generate($this->route, [
            'sourceId' => $this->sourceId,
            'sourceOrderId' => $this->sourceOrderId,
            'demoOrderId' => $demoOrderId,
        ]);
    }

    /**
     * "View Comparison", as the row action every table on these pages uses.
     *
     * @param string|null $name what the link is for, when the visible words alone would not say —
     *                          a screen reader lists every "View Comparison" on the page together
     */
    public function action(int $demoOrderId, ?string $name = null): string
    {
        return '<a class="ot-rowaction" target="_blank" rel="noopener noreferrer"'
            . ' href="' . Html::encode($this->url($demoOrderId)) . '"'
            . ($name === null ? '' : ' aria-label="' . Html::encode($name) . '"')
            . ' data-pr-compare="' . Html::encode((string) $demoOrderId) . '">View Comparison</a>';
    }

    /**
     * One try's score, as a link to that try's comparison.
     *
     * The figure and its colour band are the page's own, passed in already worked out, so this prints
     * exactly what the page would have printed. The try number goes into the tooltip and the
     * accessible name, so "75%" is never the only thing telling a reader which try it opens.
     *
     * @param string $percent the figure as the page prints it, e.g. `75%` or `—`
     * @param string $band    the page's colour band for it: excellent, good, fair, poor or none
     * @param bool   $latest  marks the newest try, which the row's own View Comparison also opens
     */
    public function chip(int $demoOrderId, int $tryNumber, string $percent, string $band, bool $latest = false): string
    {
        $try = $tryNumber > 0 ? 'Try ' . $tryNumber : 'this try';
        $name = 'View comparison for ' . $try . ($latest ? ' (latest)' : '') . ': ' . $percent;

        return '<a class="pr-chip pr-score pr-score--' . Html::encode($band) . ($latest ? ' pr-chip--latest' : '') . '"'
            . ' target="_blank" rel="noopener noreferrer"'
            . ' href="' . Html::encode($this->url($demoOrderId)) . '"'
            . ' title="' . Html::encode(($tryNumber > 0 ? 'Try ' . $tryNumber : 'Try') . ($latest ? ' · latest' : '') . ' — open its comparison') . '"'
            . ' aria-label="' . Html::encode($name) . '"'
            . ' data-pr-compare="' . Html::encode((string) $demoOrderId) . '">'
            . Html::encode($percent) . '</a>';
    }
}
