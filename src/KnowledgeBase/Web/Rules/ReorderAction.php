<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\Rules;

use App\KnowledgeBase\Application\RuleService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Reorders a knowledge base's rules (POST /knowledge-bases/{slug}/rules/reorder).
 *
 * The submitted `order[]` is the full list of rule ids in the desired sequence. Ids not belonging to
 * this knowledge base are ignored by the service, so a tampered payload cannot touch another base.
 */
final readonly class ReorderAction
{
    public function __construct(
        private RuleService $ruleService,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, ServerRequestInterface $request): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $order = RuleService::normalizeOrder(FormData::fromRequest($request)->rawValue('order'));

        if ($order !== []) {
            $this->ruleService->reorder($knowledgeBase->id(), $order);
            $this->flash->success('Rule order updated.');
        }

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
