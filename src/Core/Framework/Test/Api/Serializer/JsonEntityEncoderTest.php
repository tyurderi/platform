<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Test\Api\Serializer;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderDefinition;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Rule\RuleDefinition;
use Shopware\Core\Framework\Api\Exception\UnsupportedEncoderInputException;
use Shopware\Core\Framework\Api\Serializer\JsonEntityEncoder;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\Api\Serializer\fixtures\SerializationFixture;
use Shopware\Core\Framework\Test\Api\Serializer\fixtures\TestBasicStruct;
use Shopware\Core\Framework\Test\Api\Serializer\fixtures\TestBasicWithExtension;
use Shopware\Core\Framework\Test\Api\Serializer\fixtures\TestBasicWithToManyRelationships;
use Shopware\Core\Framework\Test\Api\Serializer\fixtures\TestBasicWithToOneRelationship;
use Shopware\Core\Framework\Test\Api\Serializer\fixtures\TestCollectionWithSelfReference;
use Shopware\Core\Framework\Test\Api\Serializer\fixtures\TestCollectionWithToOneRelationship;
use Shopware\Core\Framework\Test\Api\Serializer\fixtures\TestEncodeWithSourceFields;
use Shopware\Core\Framework\Test\Api\Serializer\fixtures\TestInternalFieldsAreFiltered;
use Shopware\Core\Framework\Test\Api\Serializer\fixtures\TestMainResourceShouldNotBeInIncluded;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\DataAbstractionLayerFieldTestBehaviour;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\AssociationExtension;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\ExtendableDefinition;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\ExtendedDefinition;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\ScalarRuntimeExtension;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\System\User\UserDefinition;

class JsonEntityEncoderTest extends TestCase
{
    use KernelTestBehaviour;
    use DataAbstractionLayerFieldTestBehaviour;

    public function emptyInputProvider(): array
    {
        return [
            [null],
            ['string'],
            [1],
            [false],
            [new \DateTime()],
            [1.1],
        ];
    }

    /**
     * @dataProvider emptyInputProvider
     */
    public function testEncodeWithEmptyInput($input): void
    {
        $this->expectException(UnsupportedEncoderInputException::class);
        $encoder = $this->getContainer()->get(JsonEntityEncoder::class);
        $encoder->encode(new Criteria(), $this->getContainer()->get(ProductDefinition::class), $input, SerializationFixture::API_BASE_URL, SerializationFixture::API_VERSION);
    }

    public function complexStructsProvider(): array
    {
        return [
            [MediaDefinition::class, new TestBasicStruct()],
            [UserDefinition::class, new TestBasicWithToManyRelationships()],
            [MediaDefinition::class, new TestBasicWithToOneRelationship()],
            [MediaFolderDefinition::class, new TestCollectionWithSelfReference()],
            [MediaDefinition::class, new TestCollectionWithToOneRelationship()],
            [RuleDefinition::class, new TestInternalFieldsAreFiltered()],
            [UserDefinition::class, new TestMainResourceShouldNotBeInIncluded()],
        ];
    }

    /**
     * @dataProvider complexStructsProvider
     */
    public function testEncodeComplexStructs(string $definitionClass, SerializationFixture $fixture): void
    {
        /** @var EntityDefinition $definition */
        $definition = $this->getContainer()->get($definitionClass);
        $encoder = $this->getContainer()->get(JsonEntityEncoder::class);
        $actual = $encoder->encode(new Criteria(), $definition, $fixture->getInput(), SerializationFixture::API_BASE_URL, SerializationFixture::API_VERSION);

        static::assertEquals($fixture->getAdminJsonFixtures(), $actual);
    }

    /**
     * Not possible with dataprovider
     * as we have to manipulate the container, but the dataprovider run before all tests
     */
    public function testEncodeStructWithExtension(): void
    {
        $this->registerDefinition(ExtendableDefinition::class, ExtendedDefinition::class);
        $extendableDefinition = new ExtendableDefinition();
        $extendableDefinition->addExtension(new AssociationExtension());
        $extendableDefinition->addExtension(new ScalarRuntimeExtension());

        $extendableDefinition->compile($this->getContainer()->get(DefinitionInstanceRegistry::class));
        $fixture = new TestBasicWithExtension();

        $encoder = $this->getContainer()->get(JsonEntityEncoder::class);
        $actual = $encoder->encode(new Criteria(), $extendableDefinition, $fixture->getInput(), SerializationFixture::API_BASE_URL, SerializationFixture::API_VERSION);

        static::assertEquals($fixture->getAdminJsonFixtures(), $actual);
    }

    public function testEncodeWithSourceField(): void
    {
        $case = new TestEncodeWithSourceFields();

        $entity = $case->getEntity();

        $criteria = $case->getCriteria();

        $encoder = $this->getContainer()->get(JsonEntityEncoder::class);

        $definition = $this->getContainer()->get(ProductDefinition::class);
        $actual = $encoder->encode(
            $criteria,
            $definition,
            new EntityCollection([$entity]),
            SerializationFixture::API_BASE_URL,
            SerializationFixture::API_VERSION
        );

        static::assertCount(1, $actual);
        $actual = $actual[0];

        static::assertArrayHasKey('id', $actual);
        static::assertArrayHasKey('name', $actual);
        static::assertArrayNotHasKey('description', $actual);
        static::assertArrayNotHasKey('extensions', $actual);

        static::assertArrayHasKey('tax', $actual);
        static::assertArrayHasKey('id', $actual['tax']);
        static::assertArrayHasKey('name', $actual['tax']);
        static::assertArrayHasKey('taxRate', $actual['tax']);

        static::assertArrayHasKey('manufacturer', $actual);
        static::assertArrayHasKey('name', $actual['manufacturer']);
        static::assertArrayNotHasKey('id', $actual['manufacturer']);
        static::assertArrayNotHasKey('extensions', $actual['manufacturer']);
        static::assertArrayNotHasKey('customField', $actual['manufacturer']);

        static::assertArrayHasKey('prices', $actual);
        static::assertArrayHasKey('price', $actual['prices'][0]);
        static::assertArrayHasKey('quantityStart', $actual['prices'][0]);
        static::assertArrayNotHasKey('ruleId', $actual['prices'][0]);
        static::assertArrayNotHasKey('productId', $actual['prices'][0]);
        static::assertArrayNotHasKey('extensions', $actual['prices'][0]);

        static::assertArrayHasKey('price', $actual['prices'][1]);
        static::assertArrayHasKey('quantityStart', $actual['prices'][1]);
        static::assertArrayNotHasKey('ruleId', $actual['prices'][1]);
        static::assertArrayNotHasKey('productId', $actual['prices'][1]);
        static::assertArrayNotHasKey('extensions', $actual['prices'][1]);
    }
}
