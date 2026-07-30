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
 * Edits a rule's name and instruction (POST /knowledge-bases/{slug}/rules/{ruleId}).
 */
final readonly class UpdateAction
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
        $form = FormData::fromRequest($request);

        try {
            $this->ruleService->update($knowledgeBase->id(), $ruleId, $form->string('name'), $form->raw('instruction'));
            $this->flash->success('Rule updated.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $message) {
                $this->flash->error($message);
            }
        }

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
