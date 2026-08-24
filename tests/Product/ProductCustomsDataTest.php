<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Product;

use Davix\Customs\Product\ProductCustomsData;
use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Validation\CodeNormaliser;
use Davix\Customs\Validation\NormalisationFailure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProductCustomsDataTest extends TestCase
{
    public function testItSatisfiesTheContract(): void
    {
        $data = ProductCustomsData::fromRawCode('1', 'ABC-001', 'Parka', '6201401019');

        self::assertInstanceOf(ProductCustomsDataInterface::class, $data);
    }

    public function testAccessors(): void
    {
        $verified = new DateTimeImmutable('2026-01-15');

        $data = ProductCustomsData::fromRawCode(
            identifier: '42',
            sku: 'ABC-001',
            name: 'Mountain Parka',
            rawHsCode: '6201401019',
            countryOfOrigin: 'CN',
            customsDescription: "Men's parka, outer shell 100% polyester",
            netWeight: 1.2,
            grossWeight: 1.45,
            supplementaryQuantity: 1.0,
            composition: '100% polyester',
            intendedUse: 'Outerwear',
            manufacturer: 'Example Textiles Ltd',
            verifiedAt: $verified,
        );

        self::assertSame('42', $data->identifier());
        self::assertSame('ABC-001', $data->sku());
        self::assertSame('Mountain Parka', $data->name());
        self::assertSame('CN', $data->countryOfOrigin());
        self::assertSame("Men's parka, outer shell 100% polyester", $data->customsDescription());
        self::assertSame(1.2, $data->netWeight());
        self::assertSame(1.45, $data->grossWeight());
        self::assertSame(1.0, $data->supplementaryQuantity());
        self::assertSame('100% polyester', $data->composition());
        self::assertSame('Outerwear', $data->intendedUse());
        self::assertSame('Example Textiles Ltd', $data->manufacturer());
        self::assertSame($verified, $data->verifiedAt());
    }

    public function testOptionalFieldsDefaultToNull(): void
    {
        $data = ProductCustomsData::fromRawCode('1', 'ABC-001', 'Parka', '6201401019');

        self::assertNull($data->countryOfOrigin());
        self::assertNull($data->customsDescription());
        self::assertNull($data->netWeight());
        self::assertNull($data->grossWeight());
        self::assertNull($data->supplementaryQuantity());
        self::assertNull($data->composition());
        self::assertNull($data->intendedUse());
        self::assertNull($data->manufacturer());
        self::assertNull($data->verifiedAt());
    }

    public function testRawCodeIsNormalisedOnConstruction(): void
    {
        $data = ProductCustomsData::fromRawCode('1', 'ABC-001', 'Parka', '6201.40.10.19');

        self::assertTrue($data->hsCode()->isSuccess());
        self::assertSame('6201401019', $data->hsCode()->code());
    }

    /**
     * The reason the accessor returns a result rather than a string: a rule
     * explaining a bad code must be able to quote what the merchant typed.
     */
    public function testMerchantsOriginalInputSurvivesNormalisation(): void
    {
        $data = ProductCustomsData::fromRawCode('1', 'ABC-001', 'Parka', '  6201.40.10.19 ');

        self::assertSame('  6201.40.10.19 ', $data->hsCode()->raw);
        self::assertSame('6201401019', $data->hsCode()->code());
        self::assertTrue($data->hsCode()->wasModified());
    }

    /**
     * Missing and unreadable are different problems with different fixes, and
     * the distinction has to survive as far as the rules.
     */
    public function testMissingCodeIsDistinguishableFromUnreadableCode(): void
    {
        $missing = ProductCustomsData::fromRawCode('1', 'ABC-001', 'Parka', null);
        $unreadable = ProductCustomsData::fromRawCode('2', 'ABC-002', 'Parka', '6.20140102E+09');

        self::assertSame(NormalisationFailure::Blank, $missing->hsCode()->failure);
        self::assertSame(
            NormalisationFailure::ScientificNotation,
            $unreadable->hsCode()->failure,
        );
    }

    public function testASharedNormaliserCanBeSupplied(): void
    {
        $normaliser = new CodeNormaliser();

        $data = ProductCustomsData::fromRawCode(
            identifier: '1',
            sku: 'ABC-001',
            name: 'Parka',
            rawHsCode: '620140',
            normaliser: $normaliser,
        );

        self::assertSame('620140', $data->hsCode()->code());
    }

    public function testConstructorAcceptsAnAlreadyNormalisedResult(): void
    {
        $result = (new CodeNormaliser())->normalise('6201401019');

        $data = new ProductCustomsData('1', 'ABC-001', 'Parka', $result);

        self::assertSame($result, $data->hsCode());
    }
}
