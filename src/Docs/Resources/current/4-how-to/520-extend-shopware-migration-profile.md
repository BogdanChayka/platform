[titleEn]: <>(Extending a Shopware migration profile)
[metaDescriptionEn]: <>(This HowTo will give an example on extending a Shopware migration profile.)

## Overview

In this HowTo you will see an example on how you can extend a Shopware migration profile of the 
[Shopware Migration Assistant](https://store.shopware.com/search?sSearch=Swag257162657297f). For this example the Shopware 5 
[SwagAdvDevBundle](https://github.com/shopwareLabs/SwagAdvDevBundle) plugin is migrated to the Shopware 6 
[SwagBundleExample](./../1-getting-started/35-indepth-guide-bundle/010-introduction.md).
For simplicities' sake, only the local gateway is implemented.

## Setup

It is required to already have a basic plugin running and you have installed the
[SwagAdvDevBundle](https://github.com/shopwareLabs/SwagAdvDevBundle) plugin in Shopware 5,
the [SwagBundleExample](./../1-getting-started/35-indepth-guide-bundle/010-introduction.md) and 
[Shopware Migration Assistant](https://store.shopware.com/search?sSearch=Swag257162657297f) in Shopware 6.

## Enrich existing plugin with migration features

Instead of creating a new plugin for the migration, you might want to add migration features to your existing plugin.
Of course, your plugin should then also be installable without the Migration Assistant plugin.
So we have an optional requirement. Have a look at this [HowTo](./590-optional-plugin-requirements.md)
on how to inject the needed migration services only if the Migration Assistant plugin is available.
You could also have a look at the example plugin, to see how the conditional loading is managed in the plugin base class.

## Creating a new DataSet

First of all, you need to create a new `DataSet` for your bundle entity:

```php
<?php declare(strict_types=1);

namespace SwagMigrationBundleExample\Profile\Shopware\DataSelection\DataSet;

use SwagMigrationAssistant\Migration\DataSelection\DataSet\CountingInformationStruct;
use SwagMigrationAssistant\Migration\DataSelection\DataSet\CountingQueryStruct;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware\DataSelection\DataSet\ShopwareDataSet;
use SwagMigrationAssistant\Profile\Shopware\ShopwareProfileInterface;

class BundleDataSet extends ShopwareDataSet
{
    public static function getEntity(): string
    {
        return 'swag_bundle'; // Identifier of this entity
    }

    public function supports(MigrationContextInterface $migrationContext): bool
    {
        // This way we support all Shopware profile versions
        return $migrationContext->getProfile() instanceof ShopwareProfileInterface;
    }

    public function getCountingInformation(): ?CountingInformationStruct
    {
        $information = new CountingInformationStruct(self::getEntity());
        $information->addQueryStruct(new CountingQueryStruct('s_bundles')); // Counting of the given bundle table

        return $information;
    }

    public function getApiRoute(): string
    {
        return 'SwagMigrationBundles'; // This is only for fetching via API gateway
    }

    public function getExtraQueryParameters(): array
    {
        return []; // This is only for fetching via API gateway
    }
}
```

The bundle entities must be migrated after the products, because of that you have to extend the `ProductDataSelection`
as following:

```php
<?php declare(strict_types=1);

namespace SwagMigrationBundleExample\Profile\Shopware\DataSelection;

use SwagMigrationAssistant\Migration\DataSelection\DataSelectionInterface;
use SwagMigrationAssistant\Migration\DataSelection\DataSelectionStruct;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationBundleExample\Profile\Shopware\DataSelection\DataSet\BundleDataSet;

class ProductDataSelection implements DataSelectionInterface
{
    /**
     * @var DataSelectionInterface
     */
    private $originalDataSelection;

    public function __construct(DataSelectionInterface $originalDataSelection)
    {
        $this->originalDataSelection = $originalDataSelection;
    }

    public function supports(MigrationContextInterface $migrationContext): bool
    {
        return $this->originalDataSelection->supports($migrationContext);
    }

    public function getData(): DataSelectionStruct
    {
        $dataSelection = $this->originalDataSelection->getData();

        // Add the modified entities array to a new DataSelectionStruct
        return new DataSelectionStruct(
            $dataSelection->getId(),
            $this->getEntityNames(),
            $dataSelection->getSnippet(),
            $dataSelection->getPosition(),
            $dataSelection->getProcessMediaFiles()
        );
    }

    /**
     * @return string[]
     */
    public function getEntityNames(): array
    {
        $entities = $this->originalDataSelection->getEntityNames();
        $entities[] = BundleDataSet::getEntity(); // Add the BundleDataSet entity to the entities array

        return $entities;
    }
}
```

To insert the bundle entity to this `DataSelection`, you have to add this entity to the entities array of the returning
`DataSelectionStruct` of the `getData` function.

Both classes have to be registered in the `migration_assistant_extension.xml`:

```xml
<service id="SwagMigrationBundleExample\Profile\Shopware\DataSelection\ProductDataSelection"
         decorates="SwagMigrationAssistant\Profile\Shopware\DataSelection\ProductDataSelection">
    <argument type="service" id="SwagMigrationBundleExample\Profile\Shopware\DataSelection\ProductDataSelection.inner"/>
</service>

<service id="SwagMigrationBundleExample\Profile\Shopware\DataSelection\DataSet\BundleDataSet">
    <tag name="shopware.migration.data_set"/>
</service>
```
All `DataSets` have to be tagged with `shopware.migration.data_set`. The `DataSetRegistry` fetches all of these classes
and searches the correct `DataSet` with the `supports` method.

## Adding entity count snippets

If you check your current progress in the data selection table of Shopware Migration Assistant in the administration, 
you can see, that the bundle entities are automatically counted, but the description of the entity count is currently not loaded.
To get a correct description of the new entity count, you have to add new snippets for this.

First of all you create a new snippet file e.g. `en-GB.json`:

```json
{
    "swag-migration": {
        "index": {
            "selectDataCard": {
                "entities": {
                    "swag_bundle": "Bundles:"
                }
            }
        }
    }
}
```
All count entity descriptions are located in the `swag-migration.index.selectDataCard.entities` namespace, so you have to
create a new entry with the entity name of the new bundle entity.

At last you have to create the `main.js` in the `Resources/administration` directory like this:

```javascript
import enGBSnippets from './snippet/en-GB.json';

Shopware.Application.addInitializerDecorator('locale', (localeFactory) => {
    localeFactory.extend('en-GB', enGBSnippets);

    return localeFactory;
});
```

As you see in the code above, you register your snippet file for the `en-GB` locale. Now the count entity description
should display in the administration correctly.

## Creating a local reader

After creating the `DataSet`, `DataSelection` and the snippets for your new bundle entity, you have to create a new
local reader to fetch all entity data from your source system:

```php
<?php declare(strict_types=1);

namespace SwagMigrationBundleExample\Profile\Shopware\Gateway\Local\Reader;

use Doctrine\DBAL\Connection;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware\Gateway\Local\Reader\LocalAbstractReader;
use SwagMigrationAssistant\Profile\Shopware\Gateway\Local\Reader\LocalReaderInterface;
use SwagMigrationAssistant\Profile\Shopware\ShopwareProfileInterface;
use SwagMigrationBundleExample\Profile\Shopware\DataSelection\DataSet\BundleDataSet;

class LocalBundleReader extends LocalAbstractReader implements LocalReaderInterface
{
    public function supports(MigrationContextInterface $migrationContext): bool
    {
        // Make sure that this reader is only called for the BundleDataSet entity
        return $migrationContext->getProfile() instanceof ShopwareProfileInterface
            && $migrationContext->getDataSet()::getEntity() === BundleDataSet::getEntity();
    }

    /**
     * Read all bundles with associated product data
     */
    public function read(MigrationContextInterface $migrationContext, array $params = []): array
    {
        $this->setConnection($migrationContext);

        // Fetch the ids of the given table with the given offset and limit
        $ids = $this->fetchIdentifiers('s_bundles', $migrationContext->getOffset(), $migrationContext->getLimit());
        
        // Strip the table prefix 'bundles' out of the bundles array 
        $bundles = $this->mapData($this->fetchBundles($ids), [], ['bundles']);
        $bundleProducts = $this->fetchBundleProducts($ids);

        foreach ($bundles as &$bundle) {
            if (isset($bundleProducts[$bundle['id']])) {
                $bundle['products'] = $bundleProducts[$bundle['id']];
            }
        }

        return $bundles;
    }

    /**
     * Fetch all bundles by given ids
     */
    private function fetchBundles(array $ids): array
    {
        $query = $this->connection->createQueryBuilder();

        $query->from('s_bundles', 'bundles');
        $this->addTableSelection($query, 's_bundles', 'bundles');

        $query->where('bundles.id IN (:ids)');
        $query->setParameter('ids', $ids, Connection::PARAM_STR_ARRAY);

        $query->addOrderBy('bundles.id');

        return $query->execute()->fetchAll();
    }

    /**
     * Fetch all bundle products by bundle ids
     */
    private function fetchBundleProducts(array $ids): array
    {
        $query = $this->connection->createQueryBuilder();

        $query->from('s_bundle_products', 'bundleProducts');
        $this->addTableSelection($query, 's_bundle_products', 'bundleProducts');

        $query->where('bundleProducts.bundle_id IN (:ids)');
        $query->setParameter('ids', $ids, Connection::PARAM_INT_ARRAY);

        return $query->execute()->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_COLUMN);
    }
}
``` 

In this local reader, you fetch all bundles with associated products and return this in the `read` method. Like the `DataSelection`
and `DataSet`, you have to register the local reader and tag it with `shopware.migration.local_reader`
in your `migration_assistant_extension.xml`.
Also, you have to set the parent property of your local reader to `LocalAbstractReader` to inherit from this class:

```xml
<service id="SwagMigrationBundleExample\Profile\Shopware\Gateway\Local\Reader\LocalBundleReader"
         parent="SwagMigrationAssistant\Profile\Shopware\Gateway\Local\Reader\LocalAbstractReader">
    <tag name="shopware.migration.local_reader" />
</service>
```

## Creating a converter

```php
<?php declare(strict_types=1);

namespace SwagMigrationBundleExample\Profile\Shopware\Converter;

use Shopware\Core\Framework\Context;
use SwagMigrationAssistant\Migration\Converter\ConvertStruct;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\Mapping\MappingServiceInterface;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware\Converter\ShopwareConverter;
use SwagMigrationAssistant\Profile\Shopware\ShopwareProfileInterface;
use SwagMigrationBundleExample\Profile\Shopware\DataSelection\DataSet\BundleDataSet;

class BundleConverter extends ShopwareConverter
{
    /**
     * @var MappingServiceInterface
     */
    private $mappingService;

    public function __construct(MappingServiceInterface $mappingService) {
        $this->mappingService = $mappingService;
    }

    public function supports(MigrationContextInterface $migrationContext): bool
    {
        // Take care that you specify the supports function the same way that you have in your reader
        return $migrationContext->getProfile() instanceof ShopwareProfileInterface
            && $migrationContext->getDataSet()::getEntity() === BundleDataSet::getEntity();
    }

    public function convert(array $data, Context $context, MigrationContextInterface $migrationContext): ConvertStruct
    {
        // Get uuid for bundle entity out of mapping table or create a new one
        $converted['id'] = $this->mappingService->createNewUuid(
            $migrationContext->getConnection()->getId(),
            BundleDataSet::getEntity(),
            $data['id'],
            $context
        );
        
        // This method checks if key is available in data array and set value in converted array
        $this->convertValue($converted, 'name', $data, 'name');
        
        // Set default values for required fields, because these data do not exists in SW5
        $converted['discountType'] = 'absolute';
        $converted['discount'] = 0;

        if (isset($data['products'])) {
            $products = $this->getProducts($context, $migrationContext, $data);

            if (!empty($products)) {
                $converted['products'] = $products;
            }
        }
        
        // Unset used data keys
        unset(
            // Used
            $data['id'],
            $data['name'],
            $data['products']
        );

        return new ConvertStruct($converted, $data);
    }

    /** 
     * Get converted products 
    */
    private function getProducts(Context $context, MigrationContextInterface $migrationContext, array $data): array
    {
        $connectionId = $migrationContext->getConnection()->getId();
        $products = [];
        foreach ($data['products'] as $product) {
            // Get associated uuid of product out of mapping table
            $productUuid = $this->mappingService->getUuid($connectionId, DefaultEntities::PRODUCT . '_mainProduct', $product, $context);

            // Log missing association of product
            if ($productUuid === null) {
                continue;
            }

            $newProduct['id'] = $productUuid;
            $products[] = $newProduct;
        }

        return $products;
    }

    /** 
     * Called to write the created mapping to mapping table
    */
    public function writeMapping(Context $context): void
    {
        $this->mappingService->writeMapping($context);
    }
}
```

The converter is the main logic of the migration and converts old Shopware 5 data to new Shopware 6 data structure.
If you don't know how the Shopware 6 data structure of your entity looks like, you have to look for the entity definition:

```php
<?php declare(strict_types=1);

namespace Swag\BundleExample\Core\Content\Bundle;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslatedField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslationsAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\BundleExample\Core\Content\Bundle\Aggregate\BundleProduct\BundleProductDefinition;
use Swag\BundleExample\Core\Content\Bundle\Aggregate\BundleTranslation\BundleTranslationDefinition;

class BundleDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'swag_bundle';
    }

    public function getEntityClass(): string
    {
        return BundleEntity::class;
    }

    public function getCollectionClass(): string
    {
        return BundleCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new TranslatedField('name'),
            (new StringField('discount_type', 'discountType'))->addFlags(new Required()),
            (new FloatField('discount', 'discount'))->addFlags(new Required()),
            new TranslationsAssociationField(BundleTranslationDefinition::class, 'swag_bundle_id'),
            new ManyToManyAssociationField('products', ProductDefinition::class, BundleProductDefinition::class, 'bundle_id', 'product_id'),
        ]);
    }
}
```

In the `BundleDefinition` you can see which fields the entity has and which are required. (Hint: Always use the property name
of the field.) In the end of this step, you have to register your new converter in the `migration_assistant_extension.xml` and tag it with `shopware.migration.converter`:

```xml
<service id="SwagMigrationBundleExample\Profile\Shopware\Converter\BundleConverter">
    <argument type="service" id="SwagMigrationAssistant\Migration\Mapping\MappingService"/>
    <tag name="shopware.migration.converter"/>
</service>
```

## Adding a writer

After adding a reader and converter, you get bundle data from your source system and convert it, but the final step is
to write the converted data into Shopware 6. To finish this tutorial, you have to create a new writer, register and
tag it with `shopware.migration.writer` in the `migration_assistant_extension.xml`:

```php
<?php declare(strict_types=1);

namespace SwagMigrationBundleExample\Migration\Writer;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriterInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use SwagMigrationAssistant\Migration\Writer\WriterInterface;
use SwagMigrationBundleExample\Profile\Shopware\DataSelection\DataSet\BundleDataSet;

class BundleWriter implements WriterInterface
{
    /**
     * @var EntityWriterInterface
     */
    private $entityWriter;

    /**
     * @var EntityDefinition
     */
    private $definition;

    public function __construct(EntityWriterInterface $entityWriter, EntityDefinition $definition)
    {
        $this->entityWriter = $entityWriter;
        $this->definition = $definition;
    }

    public function supports(): string
    {
        return BundleDataSet::getEntity();
    }

    public function writeData(array $data, Context $context): void
    {
        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($data) {
            $this->entityWriter->upsert(
                $this->definition,
                $data,
                WriteContext::createFromContext($context)
            );
        });
    }
}
```

```xml
<service id="SwagMigrationBundleExample\Migration\Writer\BundleWriter">
    <argument type="service" id="Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriter"/>
    <argument type="service" id="Swag\BundleExample\Core\Content\Bundle\BundleDefinition"/>
    <tag name="shopware.migration.writer"/>
</service>
```

In the writer you use the `EntityWriter` to write your entities of the given entity definition (look above into `migration_assistant_extension.xml`).

And that's it, you're done and have already implemented your first plugin migration.
Install your plugin, clear the cache and build the administration to see the migration of your bundle entities.

## Source

There's a GitHub repository available, containing a full example source.
Check it out [here](https://github.com/shopware/swag-docs-extending-shopware-migration-profile).
