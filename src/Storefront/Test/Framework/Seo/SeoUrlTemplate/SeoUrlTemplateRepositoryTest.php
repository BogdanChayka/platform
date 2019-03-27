<?php declare(strict_types=1);

namespace Shopware\Storefront\Test\Framework\Seo\SeoUrlTemplate;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Storefront\Framework\Seo\DbalIndexing\SeoUrl\DetailPageSeoUrlIndexer;
use Shopware\Storefront\Framework\Seo\SeoUrlGenerator\DetailPageSeoUrlGenerator;
use Shopware\Storefront\Framework\Seo\SeoUrlTemplate\SeoUrlTemplateDefinition;
use Shopware\Storefront\Framework\Seo\SeoUrlTemplate\SeoUrlTemplateEntity;

class SeoUrlTemplateRepositoryTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testCreate(): void
    {
        $id = Uuid::uuid4()->getHex();
        $template = [
            'id' => $id,
            'salesChannelId' => Defaults::SALES_CHANNEL,
            'routeName' => DetailPageSeoUrlIndexer::ROUTE_NAME,
            'entityName' => ProductDefinition::getEntityName(),
            'template' => DetailPageSeoUrlGenerator::DEFAULT_TEMPLATE,
        ];

        $context = Context::createDefaultContext();
        /** @var EntityRepositoryInterface $repo */
        $repo = $this->getContainer()->get('seo_url_template.repository');
        $events = $repo->create([$template], $context);
        static::assertCount(1, $events->getEvents());

        $event = $events->getEventByDefinition(SeoUrlTemplateDefinition::class);
        static::assertNotNull($event);
        static::assertCount(1, $event->getPayloads());
    }

    public function testUpdate(): void
    {
        $id = Uuid::uuid4()->getHex();
        $template = [
            'id' => $id,
            'salesChannelId' => Defaults::SALES_CHANNEL,
            'routeName' => DetailPageSeoUrlIndexer::ROUTE_NAME,
            'entityName' => ProductDefinition::getEntityName(),
            'template' => DetailPageSeoUrlGenerator::DEFAULT_TEMPLATE,
        ];

        $context = Context::createDefaultContext();
        /** @var EntityRepositoryInterface $repo */
        $repo = $this->getContainer()->get('seo_url_template.repository');
        $repo->create([$template], $context);

        $update = [
            'id' => $id,
            'routeName' => 'foo_bar',
        ];
        $events = $repo->update([$update], $context);
        $event = $events->getEventByDefinition(SeoUrlTemplateDefinition::class);
        static::assertNotNull($event);
        static::assertCount(1, $event->getPayloads());

        /** @var SeoUrlTemplateEntity $first */
        $first = $repo->search(new Criteria([$id]), $context)->first();
        static::assertEquals($update['id'], $first->getId());
        static::assertEquals($update['routeName'], $first->getRouteName());
    }

    public function testDelete(): void
    {
        $id = Uuid::uuid4()->getHex();
        $template = [
            'id' => $id,
            'salesChannelId' => Defaults::SALES_CHANNEL,
            'routeName' => DetailPageSeoUrlIndexer::ROUTE_NAME,
            'entityName' => ProductDefinition::getEntityName(),
            'template' => DetailPageSeoUrlGenerator::DEFAULT_TEMPLATE,
        ];

        $context = Context::createDefaultContext();
        /** @var EntityRepositoryInterface $repo */
        $repo = $this->getContainer()->get('seo_url_template.repository');
        $repo->create([$template], $context);

        $result = $repo->delete([['id' => $id]], $context);
        $event = $result->getEventByDefinition(SeoUrlTemplateDefinition::class);
        static::assertEquals([$id], $event->getIds());

        /** @var SeoUrlTemplateEntity|null $first */
        $first = $repo->search(new Criteria([$id]), $context)->first();
        static::assertNull($first);
    }
}