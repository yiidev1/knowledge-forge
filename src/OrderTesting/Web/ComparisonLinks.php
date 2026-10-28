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
     * exactly what the page would have printed. The band colours the figure only — the try number and
     * the Latest label stay neutral, and nothing but the figure can read as a score.
     *
     * Numbered (`1 · 47%`) where nothing beside it says which try it is, as in the practitioners
     * table; plain in the drawer, whose Try column already does. Either way the try number and whether
     * it is the latest are in the tooltip and the accessible name.
     *
     * @param string $percent  the figure as the page prints it, e.g. `75%` or `—`
     * @param string $band     the page's colour band for it: excellent, good, fair, poor or none
     * @param bool   $latest   marks the newest try, which the row's own View Comparison also opens
     * @param bool   $numbered prints the try number, and a Latest label on the newest
     */
    public function chip(
        int $demoOrderId,
        int $tryNumber,
        string $percent,
        string $band,
        bool $latest = false,
        bool $numbered = false,
    ): string {
        $name = ($tryNumber > 0 ? 'Try ' . $tryNumber : 'Try') . ($latest ? ' — Latest' : '')
            . ' — ' . $percent . ' — View Comparison';

        $body = '<span class="pr-chip__score pr-score pr-score--' . Html::encode($band) . '">'
            . Html::encode($percent) . '</span>';

        if ($numbered && $tryNumber > 0) {
            $body = '<span class="pr-chip__try">' . Html::encode((string) $tryNumber) . '</span>'
                . '<span class="pr-chip__sep" aria-hidden="true">·</span>' . $body;
        }

        if ($numbered && $latest) {
            $body .= '<span class="pr-chip__latest">Latest</span>';
        }

        return '<a class="pr-chip' . ($latest ? ' pr-chip--latest' : '') . '"'
            . ' target="_blank" rel="noopener noreferrer"'
            . ' href="' . Html::encode($this->url($demoOrderId)) . '"'
            . ' title="' . Html::encode($name) . '"'
            . ' aria-label="' . Html::encode($name) . '"'
            . ' data-pr-compare="' . Html::encode((string) $demoOrderId) . '">'
            . $body . '</a>';
    }
}
