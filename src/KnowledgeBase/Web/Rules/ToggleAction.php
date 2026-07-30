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
 * Enables or disables a rule (POST /knowledge-bases/{slug}/rules/{ruleId}/toggle).
 *
 * The desired state is submitted explicitly (`enabled` = 1 or 0) rather than flipped server-side, so a
 * double submission is idempotent instead of racing to the wrong state.
 */
final readonly class ToggleAction
{
    public function __construct(
        private RuleService $ruleService,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(
        #[RouteArgument]
        string $slug,
        #[RouteArgument]
        int $ruleId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $knowledgeBase = $this->finder->bySlug($slug);
        $enabled = FormData::fromRequest($request)->string('enabled') === '1';

        $this->ruleService->toggle($knowledgeBase->id(), $ruleId, $enabled);
        $this->flash->success($enabled ? 'Rule enabled.' : 'Rule disabled.');

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
