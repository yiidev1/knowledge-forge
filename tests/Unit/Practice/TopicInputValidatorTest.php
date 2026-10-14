<?php

declare(strict_types=1);

namespace App\Tests\Unit\Practice;

use App\Practice\Application\TopicInputValidator;
use App\Shared\Domain\Exception\ValidationException;
use Codeception\Test\Unit;

use function str_repeat;

/**
 * What a topic name has to be, and what "the same name" means.
 *
 * The folding rule is the interesting half. A trainer who types "Change Dish" today and
 * "change  dish" tomorrow means one topic both times, and a list containing both would make the
 * duplicate rule look broken rather than strict. So case and internal spacing are folded away before
 * anything is compared — and the folded form is what the database's unique key is built on, so the two
 * cannot drift apart.
 */
final class TopicInputValidatorTest extends Unit
{
    private TopicInputValidator $validator;

    protected function _before(): void
    {
        $this->validator = new TopicInputValidator();
    }

    public function testItTrimsAndCollapsesWhitespaceInTheStoredName(): void
    {
        [$name, $description] = $this->validator->validate("  Sushi   Any \n Roll 2  ", null);

        self::assertSame('Sushi Any Roll 2', $name);
        self::assertNull($description);
    }

    public function testAnEmptyOrWhitespaceOnlyNameIsRejected(): void
    {
        foreach (['', '   ', "\t\n "] as $blank) {
            try {
                $this->validator->validate($blank, null);
                self::fail('A blank name should not be accepted.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('name', $e->errors());
            }
        }
    }

    public function testAnOverlongNameIsRejectedAndTheLimitIsTheStoredOne(): void
    {
        $this->validator->validate(str_repeat('a', TopicInputValidator::NAME_MAX), null);

        $this->expectException(ValidationException::class);
        $this->validator->validate(str_repeat('a', TopicInputValidator::NAME_MAX + 1), null);
    }

    public function testAnOverlongDescriptionIsRejectedOnItsOwnField(): void
    {
        try {
            $this->validator->validate('Fine', str_repeat('b', TopicInputValidator::DESCRIPTION_MAX + 1));
            self::fail('An overlong description should not be accepted.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('description', $e->errors());
            self::assertArrayNotHasKey('name', $e->errors());
        }
    }

    public function testAnEmptyDescriptionIsStoredAsNothingRatherThanAsAnEmptyString(): void
    {
        [, $description] = $this->validator->validate('Change Dish', '   ');

        self::assertNull($description);
    }

    /** Multi-byte names must survive intact, and must not be truncated by a byte count. */
    public function testUnicodeNamesAreMeasuredInCharactersNotBytes(): void
    {
        $name = str_repeat('é', TopicInputValidator::NAME_MAX);

        [$stored] = $this->validator->validate($name, null);

        self::assertSame($name, $stored);
    }

    public function testTheFoldingRuleIgnoresCaseAndSpacing(): void
    {
        $expected = 'change dish';

        foreach (['Change Dish', 'CHANGE DISH', '  change   dish  ', "change\tdish"] as $spelling) {
            self::assertSame($expected, TopicInputValidator::fold($spelling), $spelling . ' folded wrongly');
        }
    }

    /** Different topics must still fold differently, or the rule would be useless. */
    public function testDistinctNamesDoNotFoldTogether(): void
    {
        self::assertNotSame(
            TopicInputValidator::fold('Sushi Any Roll 2'),
            TopicInputValidator::fold('Sushi Any Roll 3'),
        );
    }
}
