<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Provider\Hmrc;

use Davix\Customs\Provider\Hmrc\RulesOfOriginMapper;
use Davix\Customs\Tariff\OriginProof;
use Davix\Customs\Tariff\OriginRule;
use Davix\Customs\Tariff\OriginScheme;
use Davix\Customs\Tariff\OriginSchemeSet;
use PHPUnit\Framework\TestCase;

/**
 * Replays a recorded rules of origin response.
 *
 * Chosen because it is the awkward case rather than the tidy one: two schemes
 * apply to the same goods from the same country, with different rules and
 * different proof, which is exactly the situation a mapper written from the
 * published example gets wrong.
 */
final class RulesOfOriginMapperTest extends TestCase
{
    private function schemes(): OriginSchemeSet
    {
        $json = file_get_contents(__DIR__ . '/../../Fixtures/Api/rules-620140-VN.json');

        self::assertIsString($json, 'Fixture rules-620140-VN.json is unreadable');

        return (new RulesOfOriginMapper())->mapJson($json);
    }

    private function scheme(string $code): OriginScheme
    {
        foreach ($this->schemes()->all() as $scheme) {
            if ($scheme->code === $code) {
                return $scheme;
            }
        }

        self::fail(sprintf('No scheme with code %s', $code));
    }

    public function testBothApplicableSchemesAreMapped(): void
    {
        $schemes = $this->schemes();

        self::assertSame(2, $schemes->count());
        self::assertSame(2, $schemes->withRules()->count());
    }

    /**
     * The rules arrive as rules_of_origin_v2_rule, not the
     * rules_of_origin_rule the documentation illustrates. Reading only the
     * documented type finds nothing and reports every scheme as having no
     * rules, which looks like an agreement that does not cover the goods
     * rather than like a mapper looking in the wrong place.
     */
    public function testRulesAreFoundDespiteTheVersionedType(): void
    {
        self::assertNotEmpty($this->scheme('cptpp')->allRules());
        self::assertNotEmpty($this->scheme('vietnam')->allRules());
    }

    /**
     * Rules hang off rule sets, not off the scheme. Every scheme in the live
     * payload had an empty rules relationship of its own.
     */
    public function testRulesAreReachedThroughRuleSets(): void
    {
        $vietnam = $this->scheme('vietnam');

        self::assertCount(1, $vietnam->ruleSets);
        self::assertSame(
            'ex Chapter 62: Articles of apparel and clothing accessories, not knitted or crocheted',
            $vietnam->ruleSets[0]->describes(),
        );
        self::assertCount(2, $vietnam->ruleSets[0]->rules);
    }

    /**
     * Rule text carries markdown links back to the tariff site and
     * non-breaking spaces inside code references. Rendered raw in an admin
     * panel that reads as broken markup rather than as a citation.
     */
    public function testRuleTextIsReducedToSomethingReadable(): void
    {
        $rule = $this->scheme('cptpp')->allRules()[0];

        self::assertStringContainsString('[heading', $rule->text, 'The raw text keeps its links');

        $plain = $rule->plainText();

        self::assertStringNotContainsString('[', $plain);
        self::assertStringNotContainsString('](', $plain);
        self::assertStringNotContainsString('&nbsp;', $plain);
        self::assertStringNotContainsString("\u{00A0}", $plain);
        self::assertStringContainsString('heading 6201', $plain);
    }

    /**
     * The CPTPP rule carries no rule_class at all, so the kind has to be read
     * from the text. A merchant answering a change of heading rule works from
     * a bill of materials; a processing rule needs them to know what the
     * factory did. Different questions, different evidence.
     */
    public function testTheKindOfRuleIsRecognisedWithoutAClass(): void
    {
        $cptpp = $this->scheme('cptpp')->allRules()[0];

        self::assertSame([], $cptpp->classes);
        self::assertTrue($cptpp->isChangeOfHeading());

        $vietnam = $this->scheme('vietnam')->allRules()[0];

        self::assertSame(['PROCESSING'], $vietnam->classes);
        self::assertFalse($vietnam->isChangeOfHeading());
    }

    /**
     * Knowing goods qualify is no use without knowing what to hold. The two
     * schemes want different things, which is a real reason to show both.
     */
    public function testProofsAreMappedPerScheme(): void
    {
        $summaries = array_map(
            static fn (OriginProof $proof): string => $proof->summary,
            $this->scheme('vietnam')->proofs,
        );

        self::assertContains('EUR.1 movement certificate', $summaries);
        self::assertContains('Origin declaration', $summaries);

        self::assertCount(1, $this->scheme('cptpp')->proofs);
        self::assertSame('Certification of origin', $this->scheme('cptpp')->proofs[0]->summary);
    }

    public function testProofContentIsTrimmedToSomethingThatFits(): void
    {
        foreach ($this->scheme('vietnam')->proofs as $proof) {
            $short = $proof->shortContent(90);

            if ($short === null) {
                continue;
            }

            self::assertLessThanOrEqual(93, mb_strlen($short));
            self::assertStringNotContainsString('<', $short);
        }
    }

    /**
     * A preference measure names its scope as an area description such as
     * "CPTPP All Members", while the rules of origin service keys on a scheme
     * code such as "cptpp". Nothing in either payload joins the two.
     */
    public function testTheSchemeNamedByThePreferenceComesFirst(): void
    {
        $ordered = $this->schemes()->orderedFor('CPTPP All Members');

        self::assertSame('cptpp', $ordered[0]->code);
        self::assertSame('vietnam', $ordered[1]->code, 'The alternative is kept, not hidden');
    }

    /**
     * Both are kept whichever matched. A merchant who cannot satisfy CPTPP's
     * change of chapter may well satisfy Vietnam's weaving rule, and hiding it
     * because the measure named the other would cost them the saving.
     */
    public function testNoSchemeIsLostByOrdering(): void
    {
        self::assertCount(2, $this->schemes()->orderedFor('CPTPP All Members'));
        self::assertCount(2, $this->schemes()->orderedFor('Something unrecognised'));
        self::assertCount(2, $this->schemes()->orderedFor(null));
    }

    public function testCountryCoverageIsReadable(): void
    {
        $cptpp = $this->scheme('cptpp');

        self::assertTrue($cptpp->covers('VN'));
        self::assertTrue($cptpp->covers('vn'), 'Case should not matter');
        self::assertFalse($cptpp->covers('FR'));
    }

    /**
     * A scheme can cover a country and say nothing about the goods. An empty
     * scheme on screen reads as missing data rather than as an agreement that
     * does not reach this product.
     */
    public function testSchemesWithoutRulesCanBeFilteredOut(): void
    {
        $set = new OriginSchemeSet([
            new OriginScheme(code: 'empty', title: 'Covers the country, not the goods'),
            new OriginScheme(
                code: 'real',
                title: 'Has something to say',
                ruleSets: [new \Davix\Customs\Tariff\OriginRuleSet(rules: [new OriginRule('A rule')])],
            ),
        ]);

        self::assertSame(2, $set->count());
        self::assertSame(1, $set->withRules()->count());
        self::assertSame('real', $set->withRules()->all()[0]->code);
    }

    /**
     * Thresholds are published as markdown emphasis. The Vietnam rule set
     * carries "**47.5%**", which on a screen that cannot render markdown
     * appears with its asterisks attached to the number that matters most.
     */
    public function testEmphasisIsStrippedFromRuleText(): void
    {
        $texts = array_map(
            static fn (OriginRule $rule): string => $rule->plainText(),
            $this->scheme('vietnam')->allRules(),
        );

        $joined = implode(' ', $texts);

        self::assertStringContainsString('47.5%', $joined);
        self::assertStringNotContainsString('*', $joined);
        self::assertStringNotContainsString('__', $joined);
    }

    public function testAnEmptyResponseIsHandled(): void
    {
        $set = (new RulesOfOriginMapper())->mapJson('{"data": [], "included": []}');

        self::assertTrue($set->isEmpty());
        self::assertSame([], $set->orderedFor('anything'));
    }
}