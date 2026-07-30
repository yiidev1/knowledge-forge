<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\Rules;

use App\KnowledgeBase\Application\RuleService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Adds a rule to a knowledge base (POST /knowledge-bases/{slug}/rules).
 *
 * On a validation error the messages are flashed and the user is returned to the detail page. The rule
 * form is short, so re-typing on the rare duplicate-name case is an acceptable trade for keeping the
 * detail page's several inline forms simple.
 */
final readonly class StoreAction
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
        $form = FormData::fromRequest($request);

        try {
            $this->ruleService->add(
                $knowledgeBase->id(),
                $form->string('name'),
                $form->raw('instruction'),
                $form->has('is_enabled'),
            );
            $this->flash->success('Rule added.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $message) {
                $this->flash->error($message);
            }
        }

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
