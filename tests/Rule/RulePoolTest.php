<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Rule;

use Davix\Customs\Exception\RuleConfigurationException;
use Davix\Customs\Product\ProductCustomsData;
use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\Checks\InvalidCodeFormat;
use Davix\Customs\Rule\Checks\MissingHsCode;
use Davix\Customs\Rule\Checks\MissingOrigin;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\RuleInterface;
use Davix\Customs\Rule\RulePool;
use Davix\Customs\Rule\RuleSettings;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Rule\SkipReason;
use PHPUnit\Framework\TestCase;

final class RulePoolTest extends TestCase
{
    /**
     * @param list<string> $prerequisites
     */
    private function stubRule(
        string $code,
        bool $fails = false,
        array $prerequisites = [],
        Severity $severity = Severity::Attention,
    ): RuleInterface {
        return new class ($code, $fails, $prerequisites, $severity) implements RuleInterface {
            /**
             * @param list<string> $prerequisites
             */
            public function __construct(
                private readonly string $ruleCode,
                private readonly bool $fails,
                private readonly array $prerequisites,
                private readonly Severity $ruleSeverity,
            ) {
            }

            public function code(): string
            {
                return $this->ruleCode;
            }

            public function severity(): Severity
            {
                return $this->ruleSeverity;
            }

            public function prerequisites(): array
            {
                return $this->prerequisites;
            }

            public function evaluate(
                ProductCustomsDataInterface $data,
                EvaluationContext $context,
            ): ?Issue {
                return $this->fails
                    ? new Issue($this->ruleCode, $this->ruleSeverity)
                    : null;
            }
        };
    }

    private function product(?string $code = '6201401019', ?string $origin = 'CN'): ProductCustomsData
    {
        return ProductCustomsData::fromRawCode(
            identifier: '1',
            sku: 'ABC-001',
            name: 'Parka',
            rawHsCode: $code,
            countryOfOrigin: $origin,
        );
    }

    public function testEmptyPoolIsValid(): void
    {
        $pool = new RulePool();

        self::assertSame(0, $pool->count());
        self::assertSame([], $pool->ordered());
    }

    public function testRegistrationAndLookup(): void
    {
        $pool = new RulePool([new MissingOrigin()]);

        self::assertTrue($pool->has(MissingOrigin::CODE));
        self::assertFalse($pool->has('nonexistent'));
        self::assertInstanceOf(MissingOrigin::class, $pool->get(MissingOrigin::CODE));
        self::assertNull($pool->get('nonexistent'));
        self::assertSame([MissingOrigin::CODE], $pool->codes());
    }

    public function testDuplicateCodesAreRejected(): void
    {
        $this->expectException(RuleConfigurationException::class);
        $this->expectExceptionMessage('missing_origin');

        new RulePool([new MissingOrigin(), new MissingOrigin()]);
    }

    public function testUnknownPrerequisiteIsRejectedAtConstruction(): void
    {
        $this->expectException(RuleConfigurationException::class);
        $this->expectExceptionMessage('not registered');

        new RulePool([$this->stubRule('dependent', prerequisites: ['ghost'])]);
    }

    public function testSelfPrerequisiteIsRejected(): void
    {
        $this->expectException(RuleConfigurationException::class);
        $this->expectExceptionMessage('itself');

        new RulePool([$this->stubRule('loop', prerequisites: ['loop'])]);
    }

    public function testCircularPrerequisitesAreRejected(): void
    {
        $this->expectException(RuleConfigurationException::class);
        $this->expectExceptionMessage('cycle');

        new RulePool([
            $this->stubRule('a', prerequisites: ['c']),
            $this->stubRule('b', prerequisites: ['a']),
            $this->stubRule('c', prerequisites: ['b']),
        ]);
    }

    public function testRulesAreOrderedAfterTheirPrerequisites(): void
    {
        $pool = new RulePool([
            new InvalidCodeFormat(),
            new MissingOrigin(),
            new MissingHsCode(),
        ]);

        $order = array_map(
            static fn (RuleInterface $rule): string => $rule->code(),
            $pool->ordered(),
        );

        $hsCodePosition = array_search(MissingHsCode::CODE, $order, true);
        $formatPosition = array_search(InvalidCodeFormat::CODE, $order, true);

        self::assertIsInt($hsCodePosition);
        self::assertIsInt($formatPosition);
        self::assertLessThan($formatPosition, $hsCodePosition);
    }

    public function testIndependentRulesKeepRegistrationOrder(): void
    {
        $pool = new RulePool([
            $this->stubRule('first'),
            $this->stubRule('second'),
            $this->stubRule('third'),
        ]);

        $order = array_map(
            static fn (RuleInterface $rule): string => $rule->code(),
            $pool->ordered(),
        );

        self::assertSame(['first', 'second', 'third'], $order);
    }

    public function testPassingProductProducesNoIssues(): void
    {
        $pool = new RulePool([new MissingHsCode(), new InvalidCodeFormat(), new MissingOrigin()]);

        $result = $pool->evaluate($this->product(), EvaluationContext::at());

        self::assertFalse($result->hasIssues());
        self::assertSame(0, $result->issueCount());
        self::assertNull($result->highestSeverity());
    }

    public function testFailingRulesProduceIssues(): void
    {
        $pool = new RulePool([new MissingHsCode(), new InvalidCodeFormat(), new MissingOrigin()]);

        $result = $pool->evaluate(
            $this->product(code: '6201401019', origin: null),
            EvaluationContext::at(),
        );

        self::assertTrue($result->has(MissingOrigin::CODE));
        self::assertSame(1, $result->issueCount());
    }

    /**
     * The behaviour prerequisites exist for. Without them a product with no
     * code reports both "no code" and "code is not a valid format", of which
     * the second is noise derived from the first.
     */
    public function testDependentRuleIsSkippedWhenItsPrerequisiteFails(): void
    {
        $pool = new RulePool([new MissingHsCode(), new InvalidCodeFormat()]);

        $result = $pool->evaluate($this->product(code: null), EvaluationContext::at());

        self::assertTrue($result->has(MissingHsCode::CODE));
        self::assertFalse($result->has(InvalidCodeFormat::CODE));
        self::assertSame(
            SkipReason::PrerequisiteFailed,
            $result->skipReason(InvalidCodeFormat::CODE),
        );
    }

    public function testSkippingCascadesThroughAChain(): void
    {
        $pool = new RulePool([
            $this->stubRule('root', fails: true),
            $this->stubRule('middle', prerequisites: ['root']),
            $this->stubRule('leaf', prerequisites: ['middle']),
        ]);

        $result = $pool->evaluate($this->product(), EvaluationContext::at());

        self::assertSame(SkipReason::PrerequisiteFailed, $result->skipReason('middle'));
        self::assertSame(SkipReason::PrerequisiteSkipped, $result->skipReason('leaf'));
        self::assertSame(1, $result->issueCount());
    }

    public function testDisabledRulesAreSkipped(): void
    {
        $pool = new RulePool([new MissingOrigin()]);

        $result = $pool->evaluate(
            $this->product(origin: null),
            new EvaluationContext(
                new \DateTimeImmutable('2026-08-22'),
                new RuleSettings(disabledRules: [MissingOrigin::CODE]),
            ),
        );

        self::assertFalse($result->hasIssues());
        self::assertSame(SkipReason::Disabled, $result->skipReason(MissingOrigin::CODE));
    }

    /**
     * Silencing a check must not silently disable everything downstream of it.
     * A merchant switching off the missing-code rule still wants format
     * checking on the codes they do have.
     */
    public function testDisabledPrerequisiteCountsAsPassing(): void
    {
        $pool = new RulePool([new MissingHsCode(), new InvalidCodeFormat()]);

        $result = $pool->evaluate(
            $this->product(code: 'TBC'),
            new EvaluationContext(
                new \DateTimeImmutable('2026-08-22'),
                new RuleSettings(disabledRules: [MissingHsCode::CODE]),
            ),
        );

        self::assertTrue($result->has(InvalidCodeFormat::CODE));
        self::assertFalse($result->wasSkipped(InvalidCodeFormat::CODE));
    }

    public function testSeverityOverrideIsApplied(): void
    {
        $pool = new RulePool([new MissingOrigin()]);

        $result = $pool->evaluate(
            $this->product(origin: null),
            new EvaluationContext(
                new \DateTimeImmutable('2026-08-22'),
                new RuleSettings(severityOverrides: [MissingOrigin::CODE => Severity::Blocked]),
            ),
        );

        self::assertSame(Severity::Blocked, $result->issueFor(MissingOrigin::CODE)?->severity);
    }

    public function testHighestSeverityAcrossIssues(): void
    {
        $pool = new RulePool([
            $this->stubRule('low', fails: true, severity: Severity::Opportunity),
            $this->stubRule('high', fails: true, severity: Severity::Blocked),
            $this->stubRule('mid', fails: true, severity: Severity::Attention),
        ]);

        $result = $pool->evaluate($this->product(), EvaluationContext::at());

        self::assertSame(Severity::Blocked, $result->highestSeverity());
        self::assertTrue($result->hasBlocking());
        self::assertCount(1, $result->issuesOfSeverity(Severity::Attention));
    }

    public function testIssueCodesAreReported(): void
    {
        $pool = new RulePool([
            $this->stubRule('alpha', fails: true),
            $this->stubRule('beta', fails: true),
        ]);

        $result = $pool->evaluate($this->product(), EvaluationContext::at());

        self::assertSame(['alpha', 'beta'], $result->issueCodes());
    }

    public function testAddingARuleAfterConstructionInvalidatesOrdering(): void
    {
        $pool = new RulePool([new MissingHsCode()]);
        self::assertCount(1, $pool->ordered());

        $pool->add(new InvalidCodeFormat());

        self::assertCount(2, $pool->ordered());
    }
}
