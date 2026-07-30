<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\Rules;

use App\KnowledgeBase\Application\RuleService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Deletes a rule (POST /knowledge-bases/{slug}/rules/{ruleId}/delete).
 */
final readonly class DeleteAction
{
    public function __construct(
        private RuleService $ruleService,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $ruleId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);

        $this->ruleService->delete($knowledgeBase->id(), $ruleId);
        $this->flash->success('Rule deleted.');

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
