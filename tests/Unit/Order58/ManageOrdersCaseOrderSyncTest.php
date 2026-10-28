<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Web\CaseOrders\CaseOrderSyncControl;
use App\Order58\Web\StoreAudio\OrderTestingAction;
use App\Shared\Web\Actor\CurrentActor;
use Closure;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function file_get_contents;
use function realpath;
use function sort;
use function str_contains;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * Manage Orders offers "Sync Order58 Case Orders" — and it is the Call Recordings control, not a copy.
 *
 * Two things a Web test cannot reach:
 *
 * - **Who is offered it.** The case order endpoint sits in the administrators' route group. An
 *   operation user reaches Manage Orders through a group of their own, and the Web suite has no way to
 *   sign one in — so the realm gate is pinned here, on the action itself.
 * - **That there is one of everything.** The markup hooks the script binds to, and the address the
 *   form posts to, each live in exactly one file. A second dialog or a second endpoint is precisely the
 *   drift this exists to prevent.
 */
final class ManageOrdersCaseOrderSyncTest extends TestCase
{
    private const SRC = __DIR__ . '/../../../src';

    public function testAnOperationUserIsNotOfferedIt(): void
    {
        $actor = new CurrentActor();
        $actor->setOperation(41, 'operator');

        self::assertSame(['caseOrderSyncOffered' => false], $this->surfaceParametersFor($actor));
    }

    public function testAnAgentIsNotOfferedIt(): void
    {
        $actor = new CurrentActor();
        $actor->setAgent(42, 'agent');

        self::assertSame(['caseOrderSyncOffered' => false], $this->surfaceParametersFor($actor));
    }

    public function testNobodyResolvedIsNotOfferedIt(): void
    {
        self::assertSame(['caseOrderSyncOffered' => false], $this->surfaceParametersFor(new CurrentActor()));
    }

    /** The dialog and the trigger are one partial each; no page carries its own copy. */
    public function testTheDialogAndTheTriggerExistInOneFileEach(): void
    {
        self::assertSame(
            ['Order58/Web/CaseOrders/_sync-dialog.php'],
            $this->filesContaining('data-o58-case-order-dialog'),
        );
        self::assertSame(
            ['Order58/Web/CaseOrders/_sync-trigger.php'],
            $this->filesContaining('data-o58-case-order-open'),
        );
    }

    /** One place generates the address the press goes to, so both pages post to the same endpoint. */
    public function testOneFileNamesTheSynchronisationEndpoint(): void
    {
        self::assertSame(
            ['Order58/Web/CaseOrders/CaseOrderSyncControl.php'],
            $this->filesContaining("'order58.case-orders.sync'"),
        );
    }

    /** Both pages render those two partials. */
    public function testBothPagesRenderTheSharedPartials(): void
    {
        foreach (['Order58/Web/CallRecordings/template.php', 'Order58/Web/StoreAudio/order-testing.php'] as $page) {
            $source = (string) file_get_contents(self::SRC . '/' . $page);

            self::assertStringContainsString('CaseOrderSyncViews::trigger()', $source, $page);
            self::assertStringContainsString('CaseOrderSyncViews::dialog()', $source, $page);
        }
    }

    /**
     * The action's realm gate, asked directly.
     *
     * Built without its constructor because the renderer it needs cannot be stood up here, and the
     * branch under test reads only the actor. The control is never reached on that branch, so an
     * unconstructed one is enough to prove it is not called.
     *
     * @return array<string, mixed>
     */
    private function surfaceParametersFor(CurrentActor $actor): array
    {
        $action = (new ReflectionClass(OrderTestingAction::class))->newInstanceWithoutConstructor();
        $control = (new ReflectionClass(CaseOrderSyncControl::class))->newInstanceWithoutConstructor();

        // Readonly properties may be initialised once, from the declaring class's own scope.
        Closure::bind(function () use ($actor, $control): void {
            $this->actor = $actor;
            $this->caseOrderSync = $control;
        }, $action, OrderTestingAction::class)();

        /** @var array<string, mixed> */
        return Closure::bind(
            fn(): array => $this->surfaceParameters([]),
            $action,
            OrderTestingAction::class,
        )();
    }

    /** @return list<string> paths under `src/`, sorted */
    private function filesContaining(string $needle): array
    {
        $base = (string) realpath(self::SRC);
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SRC)) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $path = $file->getRealPath();

            if ($path !== false && str_contains((string) file_get_contents($path), $needle)) {
                $found[] = substr($path, strlen($base) + 1);
            }
        }

        sort($found);

        return $found;
    }
}
