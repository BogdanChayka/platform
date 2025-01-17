<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Test\Cart;

use Composer\Repository\RepositoryInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Event\LineItemAddedEvent;
use Shopware\Core\Checkout\Cart\Event\LineItemQuantityChangedEvent;
use Shopware\Core\Checkout\Cart\Event\LineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\SalesChannel\AccountService;
use Shopware\Core\Content\MailTemplate\Service\Event\MailSentEvent;
use Shopware\Core\Content\Product\Cart\ProductLineItemFactory;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\MailTemplateTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\CallableClass;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Symfony\Component\EventDispatcher\EventDispatcher;

class CartServiceTest extends TestCase
{
    use IntegrationTestBehaviour;
    use MailTemplateTestBehaviour;

    /**
     * @var RepositoryInterface|null
     */
    private $customerRepository;

    /**
     * @var AccountService|null
     */
    private $accountService;

    /**
     * @var Connection|null
     */
    private $connection;

    /**
     * @var string
     */
    private $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->getContainer()->get(Connection::class);
        $this->customerRepository = $this->getContainer()->get('customer.repository');
        $this->accountService = $this->getContainer()->get(AccountService::class);

        $context = Context::createDefaultContext();
        $this->productId = Uuid::randomHex();
        $product = [
            'id' => $this->productId,
            'productNumber' => $this->productId,
            'name' => 'test',
            'stock' => 10,
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 100, 'net' => 100, 'linked' => false],
            ],
            'tax' => ['name' => 'test', 'taxRate' => 18],
            'manufacturer' => ['name' => 'test'],
        ];

        $this->getContainer()->get('product.repository')
            ->create([$product], $context);
    }

    public function testLineItemAddedEventFired(): void
    {
        $dispatcher = $this->getContainer()->get('event_dispatcher');

        $listener = $this->getMockBuilder(CallableClass::class)->getMock();
        $listener->expects(static::once())->method('__invoke');

        $dispatcher->addListener(LineItemAddedEvent::class, $listener);

        $cartService = $this->getContainer()->get(CartService::class);

        $context = $this->getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), Defaults::SALES_CHANNEL);

        $cartService->add(
            $cartService->getCart(Uuid::randomHex(), $context),
            new LineItem('test', 'test'),
            $context
        );
    }

    public function testLineItemRemovedEventFired(): void
    {
        $dispatcher = $this->getContainer()->get('event_dispatcher');

        $listener = $this->getMockBuilder(CallableClass::class)->getMock();
        $listener->expects(static::once())->method('__invoke');

        $dispatcher->addListener(LineItemRemovedEvent::class, $listener);

        $cartService = $this->getContainer()->get(CartService::class);

        $context = $this->getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), Defaults::SALES_CHANNEL);

        $lineItem = (new ProductLineItemFactory())->create($this->productId);

        $cart = $cartService->getCart($context->getToken(), $context);

        $cart = $cartService->add($cart, $lineItem, $context);

        static::assertTrue($cart->has($this->productId));

        $cart = $cartService->remove($cart, $this->productId, $context);

        static::assertFalse($cart->has($this->productId));
    }

    public function testLineItemQuantityChangedEventFired(): void
    {
        $dispatcher = $this->getContainer()->get('event_dispatcher');

        $listener = $this->getMockBuilder(CallableClass::class)->getMock();
        $listener->expects(static::once())->method('__invoke');

        $dispatcher->addListener(LineItemQuantityChangedEvent::class, $listener);

        $cartService = $this->getContainer()->get(CartService::class);

        $context = $this->getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), Defaults::SALES_CHANNEL);

        $lineItem = (new ProductLineItemFactory())->create($this->productId);

        $cart = $cartService->getCart($context->getToken(), $context);

        $cart = $cartService->add($cart, $lineItem, $context);

        static::assertTrue($cart->has($this->productId));

        $cartService->changeQuantity($cart, $this->productId, 100, $context);
    }

    public function testZeroPricedItemsCanBeAddedToCart(): void
    {
        $cartService = $this->getContainer()->get(CartService::class);

        $context = $this->getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), Defaults::SALES_CHANNEL);

        $productId = Uuid::randomHex();
        $product = [
            'id' => $productId,
            'productNumber' => $productId,
            'name' => 'test',
            'stock' => 10,
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 0, 'net' => 0, 'linked' => false],
            ],
            'tax' => ['name' => 'test', 'taxRate' => 18],
            'manufacturer' => ['name' => 'test'],
        ];

        $this->getContainer()->get('product.repository')
            ->create([$product], $context->getContext());

        $lineItem = (new ProductLineItemFactory())->create($productId);

        $cart = $cartService->getCart($context->getToken(), $context);

        $cart = $cartService->add($cart, $lineItem, $context);

        static::assertTrue($cart->has($productId));
        static::assertEquals(0, $cart->getPrice()->getTotalPrice());
        $calculatedLineItem = $cart->getLineItems()->get($productId);
        static::assertEquals(0, $calculatedLineItem->getPrice()->getTotalPrice());
        static::assertEquals(0, $calculatedLineItem->getPrice()->getCalculatedTaxes()->getAmount());
    }

    public function testOrderCartSendMail(): void
    {
        /** @var SalesChannelContextFactory $salesChannelContextFactory */
        $salesChannelContextFactory = $this->getContainer()->get(SalesChannelContextFactory::class);
        $context = $salesChannelContextFactory->create(Uuid::randomHex(), Defaults::SALES_CHANNEL);

        /** @var SalesChannelContextService $contextService */
        $contextService = $this->getContainer()->get(SalesChannelContextService::class);

        $addressId = Uuid::randomHex();

        $mail = 'test@shopware.com';
        $password = 'shopware';

        $this->createCustomer($addressId, $mail, $password, $context->getContext());

        $newtoken = $this->accountService->login($mail, $context);

        $context = $contextService->get(Defaults::SALES_CHANNEL, $newtoken);

        $lineItem = (new ProductLineItemFactory())->create($this->productId);

        /** @var CartService $cartService */
        $cartService = $this->getContainer()->get(CartService::class);

        $cart = $cartService->getCart($context->getToken(), $context);

        $cart = $cartService->add($cart, $lineItem, $context);

        $this->assignMailtemplatesToSalesChannel(Defaults::SALES_CHANNEL, $context->getContext());

        /** @var EventDispatcher $dispatcher */
        $dispatcher = $this->getContainer()->get('event_dispatcher');

        $phpunit = $this;
        $listenerClosure = function (MailSentEvent $event) use (&$eventDidRun, $phpunit): void {
            $eventDidRun = true;
            $phpunit->assertStringContainsString('Shipping costs: €0.00', $event->getContents()['text/html']);
        };

        $eventDidRun = false;
        $dispatcher->addListener(MailSentEvent::class, $listenerClosure);

        $cartService->order($cart, $context);

        $dispatcher->removeListener(MailSentEvent::class, $listenerClosure);

        static::assertTrue($eventDidRun, 'The mail.sent Event did not run');
    }

    private function createCustomer(string $addressId, string $mail, string $password, Context $context): void
    {
        $this->connection->executeUpdate('DELETE FROM customer WHERE email = :mail', [
            'mail' => $mail,
        ]);

        $this->customerRepository->create([
            [
                'salesChannelId' => Defaults::SALES_CHANNEL,
                'defaultShippingAddress' => [
                    'id' => $addressId,
                    'firstName' => 'not',
                    'lastName' => 'not',
                    'street' => 'test',
                    'city' => 'not',
                    'zipcode' => 'not',
                    'salutationId' => $this->getValidSalutationId(),
                    'country' => ['name' => 'not'],
                ],
                'defaultBillingAddressId' => $addressId,
                'defaultPaymentMethodId' => $this->getValidPaymentMethodId(),
                'groupId' => Defaults::FALLBACK_CUSTOMER_GROUP,
                'email' => $mail,
                'password' => $password,
                'lastName' => 'not',
                'firstName' => 'match',
                'salutationId' => $this->getValidSalutationId(),
                'customerNumber' => 'not',
            ],
        ], $context);
    }
}
