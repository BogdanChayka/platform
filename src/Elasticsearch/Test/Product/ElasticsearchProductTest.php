<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Test\Product;

use Doctrine\DBAL\Connection;
use Elasticsearch\Client;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\DateHistogramAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\FilterAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\AvgAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\EntityAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MinAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\StatsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\DateHistogramResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\AvgResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MaxResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MinResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\StatsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\SumResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Query\ScoreQuery;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Search\Util\DateHistogramCase;
use Shopware\Core\Framework\Test\TestDataCollection;
use Shopware\Elasticsearch\Framework\ElasticsearchHelper;
use Shopware\Elasticsearch\Test\ElasticsearchTestTestBehaviour;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ElasticsearchProductTest extends TestCase
{
    use ElasticsearchTestTestBehaviour;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var ProductDefinition
     */
    private $productDefinition;

    /**
     * @var EntityRepositoryInterface
     */
    private $languageRepository;

    /**
     * @var ElasticsearchHelper
     */
    private $helper;

    /**
     * @var TestDataCollection
     */
    private $ids;

    protected function setUp(): void
    {
        $this->helper = $this->getContainer()->get(ElasticsearchHelper::class);
        $this->client = $this->getContainer()->get(Client::class);
        $this->productDefinition = $this->getContainer()->get(ProductDefinition::class);
        $this->languageRepository = $this->getContainer()->get('language.repository');
    }

    public function testIndexing()
    {
        $this->getContainer()->get(Connection::class)->executeUpdate('DELETE FROM product');

        $context = Context::createDefaultContext();

        //Instead of indexing the test data in the set-up, we index it in the first test method. So this data does not have to be indexed again in each test.
        $this->ids = new TestDataCollection($context);

        $this->createData();

        $this->indexElasticSearch();

        $products = $this->ids->prefixed('p');

        $languages = $this->languageRepository->searchIds(new Criteria(), $context);

        foreach ($languages->getIds() as $languageId) {
            $index = $this->helper->getIndexName($this->productDefinition, $languageId);

            $exists = $this->client->indices()->exists(['index' => $index]);
            static::assertTrue($exists);

            foreach ($products as $id) {
                $exists = $this->client->exists(['index' => $index, 'id' => $id]);
                static::assertTrue($exists, 'Product with id ' . $id . ' missing');
            }
        }

        return $this->ids;
    }

    /**
     * @depends testIndexing
     */
    public function testEmptySearch(TestDataCollection $data): void
    {
        $searcher = $this->createEntitySearcher();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $products = $searcher->search($this->productDefinition, $criteria, $data->getContext());
        static::assertCount(count($data->prefixed('p')), $products->getIds());
    }

    /**
     * @depends testIndexing
     */
    public function testPagination(TestDataCollection $data): void
    {
        $searcher = $this->createEntitySearcher();

        // check pagination
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->setLimit(1);

        $products = $searcher->search($this->productDefinition, $criteria, $data->getContext());
        static::assertCount(1, $products->getIds());
        static::assertSame(count($data->prefixed('p')), $products->getTotal());
    }

    /**
     * @depends testIndexing
     */
    public function testEqualsFilter(TestDataCollection $data): void
    {
        $searcher = $this->createEntitySearcher();
        // check simple equals filter
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addFilter(new EqualsFilter('stock', 2));

        $products = $searcher->search($this->productDefinition, $criteria, $data->getContext());
        static::assertCount(1, $products->getIds());
        static::assertSame(1, $products->getTotal());
    }

    /**
     * @depends testIndexing
     */
    public function testRangeFilter(TestDataCollection $data): void
    {
        $searcher = $this->createEntitySearcher();
        // check simple range filter
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addFilter(new RangeFilter('product.stock', [RangeFilter::GTE => 10]));

        $products = $searcher->search($this->productDefinition, $criteria, $data->getContext());
        static::assertCount(5, $products->getIds());
        static::assertSame(5, $products->getTotal());
    }

    /**
     * @depends testIndexing
     */
    public function testEqualsAnyFilter(TestDataCollection $data): void
    {
        $searcher = $this->createEntitySearcher();
        // check filter for categories
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addFilter(new EqualsAnyFilter('product.categoriesRo.id', [$data->get('c1')]));

        $products = $searcher->search($this->productDefinition, $criteria, $data->getContext());
        static::assertCount(3, $products->getIds());
        static::assertSame(3, $products->getTotal());
        static::assertContains($data->get('p1'), $products->getIds());
    }

    /**
     * @depends testIndexing
     */
    public function testQueries(TestDataCollection $data): void
    {
        $searcher = $this->createEntitySearcher();
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addQuery(new ScoreQuery(new ContainsFilter('product.name', 'Silk'), 1000));
        $products = $searcher->search($this->productDefinition, $criteria, $data->getContext());
        static::assertCount(2, $products->getIds());
        static::assertContains($data->get('p1'), $products->getIds());
        static::assertContains($data->get('p3'), $products->getIds());

        $searcher = $this->createEntitySearcher();
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addQuery(new ScoreQuery(new ContainsFilter('product.name', 'Slik'), 1000));
        $products = $searcher->search($this->productDefinition, $criteria, $data->getContext());
        static::assertCount(2, $products->getIds());
        static::assertContains($data->get('p1'), $products->getIds());
        static::assertContains($data->get('p3'), $products->getIds());

        $searcher = $this->createEntitySearcher();
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addQuery(new ScoreQuery(new ContainsFilter('product.name', 'Skill'), 1000));
        $criteria->addQuery(new ScoreQuery(new ContainsFilter('product.name', 'Rubar'), 1000));
        $products = $searcher->search($this->productDefinition, $criteria, $data->getContext());
        static::assertCount(3, $products->getIds());
        static::assertContains($data->get('p1'), $products->getIds());
        static::assertContains($data->get('p2'), $products->getIds());
        static::assertContains($data->get('p3'), $products->getIds());
    }

    /**
     * @depends testIndexing
     */
    public function testSingleGroupBy(TestDataCollection $data): void
    {
        $searcher = $this->createEntitySearcher();
        // check simple equals filter
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addGroupField(new FieldGrouping('stock'));

        $products = $searcher->search($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(4, $products->getIds());
        static::assertContains($data->get('p1'), $products->getIds());
        static::assertContains($data->get('p2'), $products->getIds());
        static::assertContains($data->get('p3'), $products->getIds());
        static::assertTrue(
            in_array($data->get('p4'), $products->getIds(), true)
            || in_array($data->get('p5'), $products->getIds(), true)
            || in_array($data->get('p6'), $products->getIds(), true)
        );
    }

    /**
     * @depends testIndexing
     */
    public function testMultiGroupBy(TestDataCollection $data): void
    {
        $searcher = $this->createEntitySearcher();
        // check simple equals filter
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addGroupField(new FieldGrouping('stock'));
        $criteria->addGroupField(new FieldGrouping('purchasePrice'));

        $products = $searcher->search($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(5, $products->getIds());
        static::assertContains($data->get('p1'), $products->getIds());
        static::assertContains($data->get('p2'), $products->getIds());
        static::assertContains($data->get('p3'), $products->getIds());
        static::assertContains($data->get('p6'), $products->getIds());

        static::assertTrue(
            in_array($data->get('p4'), $products->getIds(), true)
            || in_array($data->get('p5'), $products->getIds(), true)
        );
    }

    /**
     * @depends testIndexing
     */
    public function testAvgAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(new AvgAggregation('avg-price', 'product.price'));

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('avg-price'));

        /** @var AvgResult $result */
        $result = $aggregations->get('avg-price');
        static::assertInstanceOf(AvgResult::class, $result);

        static::assertEquals(175, $result->getAvg());
    }

    /**
     * @depends testIndexing
     */
    public function testTermsAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(new TermsAggregation('manufacturer-ids', 'product.manufacturerId'));

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturer-ids'));

        /** @var TermsResult $result */
        $result = $aggregations->get('manufacturer-ids');
        static::assertInstanceOf(TermsResult::class, $result);

        static::assertCount(3, $result->getBuckets());

        static::assertContains($data->get('m1'), $result->getKeys());
        static::assertContains($data->get('m2'), $result->getKeys());
        static::assertContains($data->get('m3'), $result->getKeys());

        $bucket = $result->get($data->get('m1'));
        static::assertEquals(1, $bucket->getCount());

        $bucket = $result->get($data->get('m2'));
        static::assertEquals(3, $bucket->getCount());

        $bucket = $result->get($data->get('m3'));
        static::assertEquals(2, $bucket->getCount());
    }

    /**
     * @depends testIndexing
     */
    public function testTermsAggregationWithAvg(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(
            new TermsAggregation('manufacturer-ids', 'product.manufacturerId', null, null, new AvgAggregation('avg-price', 'product.price'))
        );

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturer-ids'));

        /** @var TermsResult $result */
        $result = $aggregations->get('manufacturer-ids');

        static::assertInstanceOf(TermsResult::class, $result);

        static::assertCount(3, $result->getBuckets());

        static::assertContains($data->get('m1'), $result->getKeys());
        static::assertContains($data->get('m2'), $result->getKeys());
        static::assertContains($data->get('m3'), $result->getKeys());

        $bucket = $result->get($data->get('m1'));
        static::assertEquals(1, $bucket->getCount());

        /** @var AvgResult $price */
        $price = $bucket->getResult();
        static::assertInstanceOf(AvgResult::class, $price);
        static::assertEquals(50, $price->getAvg());

        $bucket = $result->get($data->get('m2'));
        static::assertEquals(3, $bucket->getCount());
        $price = $bucket->getResult();
        static::assertInstanceOf(AvgResult::class, $price);
        static::assertEquals(150, $price->getAvg());

        $bucket = $result->get($data->get('m3'));
        static::assertEquals(2, $bucket->getCount());

        $price = $bucket->getResult();
        static::assertInstanceOf(AvgResult::class, $price);
        static::assertEquals(275, $price->getAvg());
    }

    /**
     * @depends testIndexing
     */
    public function testTermsAggregationWithAssociation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(new TermsAggregation('manufacturer-ids', 'product.manufacturer.id'));

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturer-ids'));

        /** @var TermsResult $result */
        $result = $aggregations->get('manufacturer-ids');
        static::assertInstanceOf(TermsResult::class, $result);

        static::assertCount(3, $result->getBuckets());

        static::assertContains($data->get('m1'), $result->getKeys());
        static::assertContains($data->get('m2'), $result->getKeys());
        static::assertContains($data->get('m3'), $result->getKeys());

        $bucket = $result->get($data->get('m1'));
        static::assertEquals(1, $bucket->getCount());

        $bucket = $result->get($data->get('m2'));
        static::assertEquals(3, $bucket->getCount());

        $bucket = $result->get($data->get('m3'));
        static::assertEquals(2, $bucket->getCount());
    }

    /**
     * @depends testIndexing
     */
    public function testTermsAggregationWithLimit(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(
            new TermsAggregation('manufacturer-ids', 'product.manufacturer.id', 2, new FieldSorting('product.manufacturer.name'))
        );

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturer-ids'));

        /** @var TermsResult $result */
        $result = $aggregations->get('manufacturer-ids');
        static::assertInstanceOf(TermsResult::class, $result);

        static::assertCount(2, $result->getBuckets());

        static::assertContains($data->get('m1'), $result->getKeys());
        static::assertContains($data->get('m2'), $result->getKeys());
    }

    /**
     * @depends testIndexing
     */
    public function testTermsAggregationWithSorting(TestDataCollection $data): void
    {
        static::markTestIncomplete('Requires ongr/dsl update. Waiting for https://github.com/ongr-io/ElasticsearchDSL/pull/296');

        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(
            new TermsAggregation('manufacturer-ids', 'product.manufacturer.id', null, new FieldSorting('product.manufacturer.name', FieldSorting::DESCENDING))
        );

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturer-ids'));

        /** @var TermsResult $result */
        $result = $aggregations->get('manufacturer-ids');
        static::assertInstanceOf(TermsResult::class, $result);

        static::assertCount(3, $result->getBuckets());

        $ordered = $data->getList(['m3', 'm2', 'm1']);
        static::assertEquals(array_values($ordered), $result->getKeys());

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(
            new TermsAggregation('manufacturer-ids', 'product.manufacturer.id', null, new FieldSorting('product.manufacturer.name', FieldSorting::ASCENDING))
        );

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        /** @var TermsResult $result */
        $result = $aggregations->get('manufacturer-ids');
        $ordered = $data->getList(['m1', 'm2', 'm3']);
        static::assertEquals(array_values($ordered), $result->getKeys());
    }

    /**
     * @depends testIndexing
     */
    public function testSumAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(new SumAggregation('sum-price', 'product.price'));

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('sum-price'));

        /** @var SumResult $result */
        $result = $aggregations->get('sum-price');
        static::assertInstanceOf(SumResult::class, $result);

        static::assertEquals(1050, $result->getSum());
    }

    /**
     * @depends testIndexing
     */
    public function testSumAggregationWithTermsAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(
            new TermsAggregation('manufacturer-ids', 'product.manufacturerId', null, null, new SumAggregation('price-sum', 'product.price'))
        );

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturer-ids'));

        /** @var TermsResult $result */
        $result = $aggregations->get('manufacturer-ids');
        static::assertInstanceOf(TermsResult::class, $result);

        static::assertCount(3, $result->getBuckets());

        static::assertContains($data->get('m1'), $result->getKeys());
        static::assertContains($data->get('m2'), $result->getKeys());
        static::assertContains($data->get('m3'), $result->getKeys());

        $bucket = $result->get($data->get('m1'));
        static::assertEquals(1, $bucket->getCount());
        /** @var SumResult $price */
        $price = $bucket->getResult();
        static::assertInstanceOf(SumResult::class, $price);
        static::assertEquals(50, $price->getSum());

        $bucket = $result->get($data->get('m2'));
        static::assertEquals(3, $bucket->getCount());
        $price = $bucket->getResult();
        static::assertInstanceOf(SumResult::class, $price);
        static::assertEquals(450, $price->getSum());

        $bucket = $result->get($data->get('m3'));
        static::assertEquals(2, $bucket->getCount());
        $price = $bucket->getResult();
        static::assertInstanceOf(SumResult::class, $price);
        static::assertEquals(550, $price->getSum());
    }

    /**
     * @depends testIndexing
     */
    public function testMaxAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(new MaxAggregation('max-price', 'product.price'));

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('max-price'));

        /** @var MaxResult $result */
        $result = $aggregations->get('max-price');
        static::assertInstanceOf(MaxResult::class, $result);

        static::assertEquals(300, $result->getMax());
    }

    /**
     * @depends testIndexing
     */
    public function testMaxAggregationWithTermsAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(
            new TermsAggregation('manufacturer-ids', 'product.manufacturerId', null, null, new MaxAggregation('price-max', 'product.price'))
        );

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturer-ids'));

        /** @var TermsResult $result */
        $result = $aggregations->get('manufacturer-ids');
        static::assertInstanceOf(TermsResult::class, $result);

        static::assertCount(3, $result->getBuckets());

        static::assertContains($data->get('m1'), $result->getKeys());
        static::assertContains($data->get('m2'), $result->getKeys());
        static::assertContains($data->get('m3'), $result->getKeys());

        $bucket = $result->get($data->get('m1'));
        static::assertEquals(1, $bucket->getCount());
        /** @var MaxResult $price */
        $price = $bucket->getResult();
        static::assertInstanceOf(MaxResult::class, $price);
        static::assertEquals(50, $price->getMax());

        $bucket = $result->get($data->get('m2'));
        static::assertEquals(3, $bucket->getCount());
        $price = $bucket->getResult();
        static::assertInstanceOf(MaxResult::class, $price);
        static::assertEquals(200, $price->getMax());

        $bucket = $result->get($data->get('m3'));
        static::assertEquals(2, $bucket->getCount());
        $price = $bucket->getResult();
        static::assertInstanceOf(MaxResult::class, $price);
        static::assertEquals(300, $price->getMax());
    }

    /**
     * @depends testIndexing
     */
    public function testMinAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(new MinAggregation('min-price', 'product.price'));

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('min-price'));

        /** @var MinResult $result */
        $result = $aggregations->get('min-price');
        static::assertInstanceOf(MinResult::class, $result);

        static::assertEquals(50, $result->getMin());
    }

    /**
     * @depends testIndexing
     */
    public function testMinAggregationWithTermsAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(
            new TermsAggregation('manufacturer-ids', 'product.manufacturerId', null, null, new MinAggregation('price-min', 'product.price'))
        );

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturer-ids'));

        /** @var TermsResult $result */
        $result = $aggregations->get('manufacturer-ids');
        static::assertInstanceOf(TermsResult::class, $result);

        static::assertCount(3, $result->getBuckets());

        static::assertContains($data->get('m1'), $result->getKeys());
        static::assertContains($data->get('m2'), $result->getKeys());
        static::assertContains($data->get('m3'), $result->getKeys());

        $bucket = $result->get($data->get('m1'));
        static::assertEquals(1, $bucket->getCount());
        /** @var MinResult $price */
        $price = $bucket->getResult();
        static::assertInstanceOf(MinResult::class, $price);
        static::assertEquals(50, $price->getMin());

        $bucket = $result->get($data->get('m2'));
        static::assertEquals(3, $bucket->getCount());
        $price = $bucket->getResult();
        static::assertInstanceOf(MinResult::class, $price);
        static::assertEquals(100, $price->getMin());

        $bucket = $result->get($data->get('m3'));
        static::assertEquals(2, $bucket->getCount());
        $price = $bucket->getResult();
        static::assertInstanceOf(MinResult::class, $price);
        static::assertEquals(250, $price->getMin());
    }

    /**
     * @depends testIndexing
     */
    public function testCountAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(new CountAggregation('manufacturer-count', 'product.manufacturerId'));

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturer-count'));

        /** @var CountResult $result */
        $result = $aggregations->get('manufacturer-count');
        static::assertInstanceOf(CountResult::class, $result);

        static::assertEquals(6, $result->getCount());
    }

    /**
     * @depends testIndexing
     */
    public function testCountAggregationWithTermsAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(
            new TermsAggregation('manufacturer-ids', 'product.manufacturerId', null, null, new CountAggregation('price-count', 'product.price'))
        );

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturer-ids'));

        /** @var TermsResult $result */
        $result = $aggregations->get('manufacturer-ids');
        static::assertInstanceOf(TermsResult::class, $result);

        static::assertCount(3, $result->getBuckets());

        static::assertContains($data->get('m1'), $result->getKeys());
        static::assertContains($data->get('m2'), $result->getKeys());
        static::assertContains($data->get('m3'), $result->getKeys());

        $bucket = $result->get($data->get('m1'));
        static::assertEquals(1, $bucket->getCount());
        /** @var CountResult $price */
        $price = $bucket->getResult();
        static::assertInstanceOf(CountResult::class, $price);
        static::assertEquals(1, $price->getCount());

        $bucket = $result->get($data->get('m2'));
        static::assertEquals(3, $bucket->getCount());
        $price = $bucket->getResult();
        static::assertInstanceOf(CountResult::class, $price);
        static::assertEquals(3, $price->getCount());

        $bucket = $result->get($data->get('m3'));
        static::assertEquals(2, $bucket->getCount());
        $price = $bucket->getResult();
        static::assertInstanceOf(CountResult::class, $price);
        static::assertEquals(2, $price->getCount());
    }

    /**
     * @depends testIndexing
     */
    public function testStatsAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(new StatsAggregation('price-stats', 'product.price'));

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('price-stats'));

        /** @var StatsResult $result */
        $result = $aggregations->get('price-stats');
        static::assertInstanceOf(StatsResult::class, $result);

        static::assertEquals(50, $result->getMin());
        static::assertEquals(300, $result->getMax());
        static::assertEquals(175, $result->getAvg());
        static::assertEquals(1050, $result->getSum());
    }

    /**
     * @depends testIndexing
     */
    public function testStatsAggregationWithTermsAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(
            new TermsAggregation('manufacturer-ids', 'product.manufacturerId', null, null, new StatsAggregation('price-stats', 'product.price'))
        );

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturer-ids'));

        /** @var TermsResult $result */
        $result = $aggregations->get('manufacturer-ids');
        static::assertInstanceOf(TermsResult::class, $result);

        static::assertCount(3, $result->getBuckets());

        static::assertContains($data->get('m1'), $result->getKeys());
        static::assertContains($data->get('m2'), $result->getKeys());
        static::assertContains($data->get('m3'), $result->getKeys());

        $bucket = $result->get($data->get('m1'));
        static::assertEquals(1, $bucket->getCount());
        /** @var StatsResult $price */
        $price = $bucket->getResult();
        static::assertInstanceOf(StatsResult::class, $price);
        static::assertEquals(50, $price->getSum());
        static::assertEquals(50, $price->getMax());
        static::assertEquals(50, $price->getMin());
        static::assertEquals(50, $price->getAvg());

        $bucket = $result->get($data->get('m2'));
        static::assertEquals(3, $bucket->getCount());
        $price = $bucket->getResult();
        static::assertInstanceOf(StatsResult::class, $price);
        static::assertEquals(450, $price->getSum());
        static::assertEquals(200, $price->getMax());
        static::assertEquals(100, $price->getMin());
        static::assertEquals(150, $price->getAvg());

        $bucket = $result->get($data->get('m3'));
        static::assertEquals(2, $bucket->getCount());
        $price = $bucket->getResult();
        static::assertInstanceOf(StatsResult::class, $price);
        static::assertEquals(550, $price->getSum());
        static::assertEquals(300, $price->getMax());
        static::assertEquals(250, $price->getMin());
        static::assertEquals(275, $price->getAvg());
    }

    /**
     * @depends testIndexing
     */
    public function testEntityAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(new EntityAggregation('manufacturers', 'product.manufacturerId', ProductManufacturerDefinition::ENTITY_NAME));

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('manufacturers'));

        /** @var EntityResult $result */
        $result = $aggregations->get('manufacturers');
        static::assertInstanceOf(EntityResult::class, $result);

        static::assertCount(3, $result->getEntities());

        static::assertTrue($result->getEntities()->has($data->get('m1')));
        static::assertTrue($result->getEntities()->has($data->get('m2')));
        static::assertTrue($result->getEntities()->has($data->get('m3')));
    }

    /**
     * @depends testIndexing
     */
    public function testFilterAggregation(TestDataCollection $data): void
    {
        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));
        $criteria->addAggregation(
            new FilterAggregation(
                'filter',
                new AvgAggregation('avg-price', 'product.price'),
                [new EqualsAnyFilter('product.id', $data->getList(['p1', 'p2']))]
            )
        );

        $aggregations = $aggregator->aggregate($this->productDefinition, $criteria, $data->getContext());

        static::assertCount(1, $aggregations);

        static::assertTrue($aggregations->has('avg-price'));

        /** @var AvgResult $result */
        $result = $aggregations->get('avg-price');
        static::assertInstanceOf(AvgResult::class, $result);

        static::assertEquals(75, $result->getAvg());
    }

    /**
     * @depends testIndexing
     * @dataProvider dateHistogramProvider
     */
    public function testDateHistogram(DateHistogramCase $case, TestDataCollection $data): void
    {
        $context = Context::createDefaultContext();

        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));

        $criteria->addAggregation(
            new DateHistogramAggregation(
                'release-histogram',
                'product.releaseDate',
                $case->getInterval(),
                null,
                null,
                $case->getFormat()
            )
        );

        /** @var AggregationResultCollection $result */
        $result = $aggregator->aggregate($this->productDefinition, $criteria, $context);

        static::assertTrue($result->has('release-histogram'));

        $histogram = $result->get('release-histogram');
        static::assertInstanceOf(DateHistogramResult::class, $histogram);

        /** @var DateHistogramResult $histogram */
        static::assertCount(count($case->getBuckets()), $histogram->getBuckets(), print_r($histogram->getBuckets(), true));

        foreach ($case->getBuckets() as $key => $count) {
            static::assertTrue($histogram->has($key));
            $bucket = $histogram->get($key);
            static::assertSame($count, $bucket->getCount());
        }
    }

    public function dateHistogramProvider()
    {
        return [
            [new DateHistogramCase(DateHistogramAggregation::PER_MINUTE, [
                '2019-01-01 10:11:00' => 1,
                '2019-01-01 10:13:00' => 1,
                '2019-06-15 13:00:00' => 1,
                '2020-09-30 15:00:00' => 1,
                '2021-12-10 11:59:00' => 2,
            ])],
            [new DateHistogramCase(DateHistogramAggregation::PER_HOUR, [
                '2019-01-01 10:00:00' => 2,
                '2019-06-15 13:00:00' => 1,
                '2020-09-30 15:00:00' => 1,
                '2021-12-10 11:00:00' => 2,
            ])],
            [new DateHistogramCase(DateHistogramAggregation::PER_DAY, [
                '2019-01-01 00:00:00' => 2,
                '2019-06-15 00:00:00' => 1,
                '2020-09-30 00:00:00' => 1,
                '2021-12-10 00:00:00' => 2,
            ])],
            [new DateHistogramCase(DateHistogramAggregation::PER_WEEK, [
                '2018 01' => 2,
                '2019 24' => 1,
                '2020 40' => 1,
                '2021 49' => 2,
            ])],
            [new DateHistogramCase(DateHistogramAggregation::PER_MONTH, [
                '2019-01-01 00:00:00' => 2,
                '2019-06-01 00:00:00' => 1,
                '2020-09-01 00:00:00' => 1,
                '2021-12-01 00:00:00' => 2,
            ])],
            [new DateHistogramCase(DateHistogramAggregation::PER_QUARTER, [
                '2019 1' => 2,
                '2019 2' => 1,
                '2020 3' => 1,
                '2021 4' => 2,
            ])],
            [new DateHistogramCase(DateHistogramAggregation::PER_YEAR, [
                '2019-01-01 00:00:00' => 3,
                '2020-01-01 00:00:00' => 1,
                '2021-01-01 00:00:00' => 2,
            ])],
            [new DateHistogramCase(DateHistogramAggregation::PER_MONTH, [
                '2019 January' => 2,
                '2019 June' => 1,
                '2020 September' => 1,
                '2021 December' => 2,
            ], 'Y F')],
            [new DateHistogramCase(DateHistogramAggregation::PER_DAY, [
                'Tuesday 01st Jan, 2019' => 2,
                'Saturday 15th Jun, 2019' => 1,
                'Wednesday 30th Sep, 2020' => 1,
                'Friday 10th Dec, 2021' => 2,
            ], 'l dS M, Y')],
        ];
    }

    /**
     * @depends testIndexing
     */
    public function testDateHistogramWithNestedAvg(TestDataCollection $data): void
    {
        $context = Context::createDefaultContext();

        $aggregator = $this->createEntityAggregator();

        // check simple search without any restrictions
        $criteria = new Criteria($data->prefixed('p'));

        $criteria->addAggregation(
            new DateHistogramAggregation(
                'release-histogram',
                'product.releaseDate',
                DateHistogramAggregation::PER_MONTH,
                null,
                new AvgAggregation('price', 'product.price')
            )
        );

        /** @var AggregationResultCollection $result */
        $result = $aggregator->aggregate($this->productDefinition, $criteria, $context);

        static::assertTrue($result->has('release-histogram'));

        $histogram = $result->get('release-histogram');
        static::assertInstanceOf(DateHistogramResult::class, $histogram);

        /** @var DateHistogramResult $histogram */
        static::assertCount(4, $histogram->getBuckets());

        $bucket = $histogram->get('2019-01-01 00:00:00');
        static::assertInstanceOf(Bucket::class, $bucket);
        /** @var AvgResult $price */
        $price = $bucket->getResult();
        static::assertInstanceOf(AvgResult::class, $price);
        static::assertEquals(75, $price->getAvg());

        $bucket = $histogram->get('2019-06-01 00:00:00');
        static::assertInstanceOf(Bucket::class, $bucket);
        $price = $bucket->getResult();
        static::assertInstanceOf(AvgResult::class, $price);
        static::assertEquals(150, $price->getAvg());

        $bucket = $histogram->get('2020-09-01 00:00:00');
        static::assertInstanceOf(Bucket::class, $bucket);
        $price = $bucket->getResult();
        static::assertInstanceOf(AvgResult::class, $price);
        static::assertEquals(200, $price->getAvg());

        $bucket = $histogram->get('2021-12-01 00:00:00');
        static::assertInstanceOf(Bucket::class, $bucket);
        $price = $bucket->getResult();
        static::assertInstanceOf(AvgResult::class, $price);
        static::assertEquals(275, $price->getAvg());
    }

    protected function getDiContainer(): ContainerInterface
    {
        return $this->getContainer();
    }

    private function createProduct(
        string $key,
        string $name,
        string $taxKey,
        string $manufacturerKey,
        float $price,
        string $releaseDate,
        float $purchasePrice,
        int $stock,
        array $categoryKeys
    ): array {
        $categories = array_map(function ($categoryKey) {
            return ['id' => $this->ids->create($categoryKey), 'name' => $categoryKey];
        }, $categoryKeys);

        $data = [
            'id' => $this->ids->create($key),
            'productNumber' => $key,
            'name' => $name,
            'stock' => $stock,
            'purchasePrice' => $purchasePrice,
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => $price, 'net' => $price / 115 * 100, 'linked' => false],
            ],
            'manufacturer' => ['id' => $this->ids->create($manufacturerKey), 'name' => $manufacturerKey],
            'tax' => ['id' => $this->ids->create($taxKey),  'name' => 'test', 'taxRate' => 15],
            'releaseDate' => $releaseDate,
        ];

        if (!empty($categories)) {
            $data['categories'] = $categories;
        }

        return $data;
    }

    private function createData(): void
    {
        /** @var EntityRepositoryInterface $repo */
        $repo = $this->getContainer()->get('product.repository');

        $repo->create([
            $this->createProduct('p1', 'Silk', 't1', 'm1', 50, '2019-01-01 10:11:00', 0, 2, ['c1', 'c2']),
            $this->createProduct('p2', 'Rubber', 't1', 'm2', 100, '2019-01-01 10:13:00', 0, 10, ['c1']),
            $this->createProduct('p3', 'Stilk', 't2', 'm2', 150, '2019-06-15 13:00:00', 100, 100, ['c1', 'c3']),
            $this->createProduct('p4', 'Grouped 1', 't2', 'm2', 200, '2020-09-30 15:00:00', 100, 300, ['c3']),
            $this->createProduct('p5', 'Grouped 2', 't3', 'm3', 250, '2021-12-10 11:59:00', 100, 300, []),
            $this->createProduct('p6', 'Grouped 3', 't3', 'm3', 300, '2021-12-10 11:59:00', 200, 300, []),
        ], Context::createDefaultContext());
    }
}
