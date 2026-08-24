<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Rule;

use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\RemediationHint;
use Davix\Customs\Rule\Severity;
use PHPUnit\Framework\TestCase;

final class IssueTest extends TestCase
{
    public function testMessageKeyDerivesFromRuleCode(): void
    {
        $issue = new Issue('withdrawn_code', Severity::Attention);

        self::assertSame('rule.withdrawn_code.message', $issue->messageKey());
        self::assertSame('rule.withdrawn_code.explanation', $issue->explanationKey());
    }

    /**
     * A code withdrawn with one successor deserves a different sentence from
     * one withdrawn with none, without needing a second rule.
     */
    public function testVariantProducesADistinctMessageKey(): void
    {
        $issue = new Issue('withdrawn_code', Severity::Attention, variant: 'with_successor');

        self::assertSame('rule.withdrawn_code.message.with_successor', $issue->messageKey());
        self::assertSame('rule.withdrawn_code.explanation', $issue->explanationKey());
    }

    public function testContextIsCarriedForInterpolation(): void
    {
        $issue = new Issue('withdrawn_code', Severity::Attention, context: [
            'code' => '620193',
            'withdrawn_on' => '2022-01-01',
            'candidate_count' => 3,
        ]);

        self::assertSame('620193', $issue->contextValue('code'));
        self::assertSame(3, $issue->contextValue('candidate_count'));
        self::assertNull($issue->contextValue('absent'));
    }

    public function testFixability(): void
    {
        $plain = new Issue('prohibited_goods', Severity::Blocked);
        $manual = new Issue('missing_origin', Severity::Attention, remediation: RemediationHint::requiresInput('set_country_of_origin'));
        $automatic = new Issue('withdrawn_code', Severity::Attention, remediation: RemediationHint::automatic('replace_with_successor', ['successor' => '6201401019']));

        self::assertFalse($plain->isFixable());
        self::assertFalse($plain->isAutomaticallyFixable());

        self::assertTrue($manual->isFixable());
        self::assertFalse($manual->isAutomaticallyFixable());

        self::assertTrue($automatic->isFixable());
        self::assertTrue($automatic->isAutomaticallyFixable());
    }

    public function testWithSeverityReturnsACopy(): void
    {
        $original = new Issue('missing_origin', Severity::Attention, context: ['sku' => 'ABC']);
        $elevated = $original->withSeverity(Severity::Blocked);

        self::assertNotSame($original, $elevated);
        self::assertSame(Severity::Attention, $original->severity);
        self::assertSame(Severity::Blocked, $elevated->severity);
        self::assertSame(['sku' => 'ABC'], $elevated->context);
    }

    public function testWithSameSeverityReturnsTheSameInstance(): void
    {
        $issue = new Issue('missing_origin', Severity::Attention);

        self::assertSame($issue, $issue->withSeverity(Severity::Attention));
    }

    public function testRemediationLabelKey(): void
    {
        $hint = RemediationHint::automatic('replace_with_successor');

        self::assertSame('remediation.replace_with_successor.label', $hint->labelKey());
        self::assertTrue($hint->isAutomatic());
    }

    public function testRemediationCarriesPayload(): void
    {
        $hint = RemediationHint::automatic('replace_with_successor', ['successor' => '6201401019']);

        self::assertSame(['successor' => '6201401019'], $hint->payload);
    }

    public function testSeverityRanking(): void
    {
        self::assertTrue(Severity::Blocked->isMoreSevereThan(Severity::Attention));
        self::assertTrue(Severity::Attention->isMoreSevereThan(Severity::Opportunity));
        self::assertFalse(Severity::Opportunity->isMoreSevereThan(Severity::Blocked));
        self::assertFalse(Severity::Blocked->isMoreSevereThan(Severity::Blocked));
    }

    public function testHighestSeverity(): void
    {
        self::assertSame(
            Severity::Blocked,
            Severity::highest([Severity::Opportunity, Severity::Blocked, Severity::Attention]),
        );
        self::assertSame(Severity::Opportunity, Severity::highest([Severity::Opportunity]));
        self::assertNull(Severity::highest([]));
    }
}
