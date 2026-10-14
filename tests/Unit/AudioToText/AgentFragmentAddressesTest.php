<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Web\Job\Review\Fragment\Action;
use App\AudioToText\Web\Job\Review\Fragment\AgentReadOnlyAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_slice;
use function file;
use function implode;
use function str_contains;

/**
 * Every address the conversation fragment can hand an agent must be an agent's own.
 *
 * ## The failure this exists to stop
 *
 * The fragment is one payload rendered for three realms. Administrators reach every audio and review
 * endpoint in the application, so the inherited addresses are right for them; an agent reaches none of
 * them, because `/audio-to-text/...` is behind a gate that refuses that realm outright.
 *
 * An address that slips through does not look like a permission error. The browser fetches it, follows
 * the gate's redirect, gets 200 and a completely different page — and writes that page into the dialog.
 * It happened: the revision-history address was left ungated and the agent home ended up rendered
 * inside the conversation modal, which read, from the outside, as "the chat does not show".
 *
 * So rather than naming the addresses that exist today, this reads the parent for **every** seam that
 * generates one and requires the agent subclass to have overridden it. A new seam that forgets is a
 * failure here, not a bug report later.
 */
final class AgentFragmentAddressesTest extends TestCase
{
    /** Every protected seam on the parent whose body names an administrator route. */
    private static function addressSeams(): array
    {
        $parent = new ReflectionClass(Action::class);
        $lines = (array) file((string) $parent->getFileName());
        $seams = [];

        foreach ($parent->getMethods(ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->getDeclaringClass()->getName() !== Action::class) {
                continue;
            }

            $body = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));

            if (str_contains($body, 'AudioToTextRoute::')) {
                $seams[] = $method->getName();
            }
        }

        return $seams;
    }

    public function testTheParentStillKeepsItsAddressesBehindSeams(): void
    {
        // Not an assertion about a number: it is what makes the next test mean anything. If the parent
        // ever stops routing its addresses through overridable methods, this suite is checking nothing.
        self::assertNotEmpty(
            self::addressSeams(),
            'the fragment generates administrator addresses outside any overridable method',
        );
    }

    public function testTheAgentFragmentOverridesEverySeamThatNamesAnAdminAddress(): void
    {
        $agent = new ReflectionClass(AgentReadOnlyAction::class);

        foreach (self::addressSeams() as $seam) {
            $declaring = $agent->getMethod($seam)->getDeclaringClass()->getName();

            self::assertNotSame(
                Action::class,
                $declaring,
                $seam . '() still answers with an administrator address for an agent. Override it in '
                . 'AgentReadOnlyAction (an agent route) or in ReadOnlyAction (null).',
            );
        }
    }
}
