<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Model\Express;

use Magento\Quote\Model\Quote;
use Magento\Checkout\Model\Type\Onepage;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Class CheckoutTest
 */
class CheckoutTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * Set up
     *
     * @return void
     */
    protected function setUp()
    {
        $this->_objectManager = Bootstrap::getObjectManager();
    }

    /**
     * Initialization the quote
     *
     * @param Quote $quote
     * @return void
     */
    protected function quoteInitialization(Quote $quote)
    {
        $quote->setCheckoutMethod(Onepage::METHOD_REGISTER);
        $quote->setCustomerEmail('user@example.com');
        $quote->setCustomerFirstname('Firstname');
        $quote->setCustomerLastname('Lastname');
        $quote->setCustomerIsGuest(false);
        $quote->setReservedOrderId(null);
    }

    /**
     * Verify that an order placed with an existing customer can re-use the customer addresses.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_payment_express_with_customer.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testPrepareCustomerQuote()
    {
        /** @var Quote $quote */
        $quote = $this->_getFixtureQuote();
        $quote->setCheckoutMethod(Onepage::METHOD_CUSTOMER); // to dive into _prepareCustomerQuote() on switch
        $quote->getShippingAddress()->setSameAsBilling(0);
        $quote->setReservedOrderId(null);
        $customer = $this->_objectManager->create('Magento\Customer\Model\Customer')->load(1);
        $customer->setDefaultBilling(false)
            ->setDefaultShipping(false)
            ->save();

        /** @var \Magento\Customer\Model\Session $customerSession */
        $customerSession = $this->_objectManager->get('Magento\Customer\Model\Session');
        $customerSession->loginById(1);
        $checkout = $this->_getCheckout($quote);
        $checkout->place('token');

        /** @var \Magento\Customer\Api\CustomerRepositoryInterface $customerService */
        $customerService = $this->_objectManager->get('Magento\Customer\Api\CustomerRepositoryInterface');
        $customer = $customerService->getById($quote->getCustomerId());

        $this->assertEquals(1, $quote->getCustomerId());
        $this->assertEquals(2, count($customer->getAddresses()));

        $this->assertEquals(1, $quote->getBillingAddress()->getCustomerAddressId());
        $this->assertEquals(2, $quote->getShippingAddress()->getCustomerAddressId());

        $order = $checkout->getOrder();
        $this->assertEquals(1, $order->getBillingAddress()->getCustomerAddressId());
        $this->assertEquals(2, $order->getShippingAddress()->getCustomerAddressId());
    }

    /**
     * Verify that an order placed with a new customer will create the customer.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_payment_express.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testPrepareNewCustomerQuote()
    {
        /** @var \Magento\Customer\Api\CustomerRepositoryInterface $customerService */
        $customerService = $this->_objectManager->get('Magento\Customer\Api\CustomerRepositoryInterface');

        /** @var Quote $quote */
        $quote = $this->_getFixtureQuote();
        $this->quoteInitialization($quote);
        $checkout = $this->_getCheckout($quote);
        $checkout->place('token');
        $customer = $customerService->getById($quote->getCustomerId());
        $this->assertEquals('user@example.com', $customer->getEmail());
        $this->assertEquals('11111111', $customer->getAddresses()[0]->getTelephone());
    }

    /**
     * Verify that an order placed with a new unconfirmed customer alerts the user that they must confirm the account.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_payment_express.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoConfigFixture current_store customer/create_account/confirm true
     */
    public function testPrepareNewCustomerQuoteConfirmationRequired()
    {
        /** @var \Magento\Customer\Api\CustomerRepositoryInterface $customerService */
        $customerService = $this->_objectManager->get('Magento\Customer\Api\CustomerRepositoryInterface');

        /** @var Quote $quote */
        $quote = $this->_getFixtureQuote();
        $this->quoteInitialization($quote);

        $checkout = $this->_getCheckout($quote);
        $checkout->place('token');
        $customer = $customerService->getById($quote->getCustomerId());
        $this->assertEquals('user@example.com', $customer->getEmail());
        $this->assertEquals('11111111', $customer->getAddresses()[0]->getTelephone());

        /** @var \Magento\Framework\Message\ManagerInterface $messageManager */
        $messageManager = $this->_objectManager->get('Magento\Framework\Message\ManagerInterface');
        $confirmationText = sprintf(
            'customer/account/confirmation/email/%s/key/',
            $customer->getEmail()
        );
        /** @var \Magento\Framework\Message\MessageInterface $message */
        $message = $messageManager->getMessages()->getLastAddedMessage();
        $this->assertInstanceOf('\Magento\Framework\Message\MessageInterface', $message);
        $this->assertTrue(
            strpos($message->getText(), $confirmationText) !== false
        );
    }

    /**
     * Verify that after placing the order, addresses are associated with the order and the quote is a guest quote.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_payment_express.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testPlaceGuestQuote()
    {
        /** @var Quote $quote */
        $quote = $this->_getFixtureQuote();
        $quote->setCheckoutMethod(Onepage::METHOD_GUEST); // to dive into _prepareGuestQuote() on switch
        $quote->getShippingAddress()->setSameAsBilling(0);
        $quote->setReservedOrderId(null);

        $checkout = $this->_getCheckout($quote);
        $checkout->place('token');

        $this->assertNull($quote->getCustomerId());
        $this->assertTrue($quote->getCustomerIsGuest());
        $this->assertEquals(
            \Magento\Customer\Model\GroupManagement::NOT_LOGGED_IN_ID,
            $quote->getCustomerGroupId()
        );

        $this->assertNotEmpty($quote->getBillingAddress());
        $this->assertNotEmpty($quote->getShippingAddress());

        $order = $checkout->getOrder();
        $this->assertNotEmpty($order->getBillingAddress());
        $this->assertNotEmpty($order->getShippingAddress());
    }

    /**
     * @param Quote $quote
     * @return Checkout
     */
    protected function _getCheckout(Quote $quote)
    {
        return $this->_objectManager->create(
            'Magento\Paypal\Model\Express\Checkout',
            [
                'params' => [
                    'config' => $this->getMock('Magento\Paypal\Model\Config', [], [], '', false),
                    'quote' => $quote,
                ]
            ]
        );
    }

    /**
     * Verify that an order placed with an existing customer can re-use the customer addresses.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_payment_express_with_customer.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testReturnFromPaypal()
    {
        $quote = $this->_getFixtureQuote();
        $paypalConfigMock = $this->getMock('Magento\Paypal\Model\Config', [], [], '', false);
        $apiTypeFactory = $this->getMock('Magento\Paypal\Model\Api\Type\Factory', [], [], '', false);
        $paypalInfo = $this->getMock('Magento\Paypal\Model\Info', [], [], '', false);
        $checkoutModel = $this->_objectManager->create(
            'Magento\Paypal\Model\Express\Checkout',
            [
                'params' => ['quote' => $quote, 'config' => $paypalConfigMock],
                'apiTypeFactory' => $apiTypeFactory,
                'paypalInfo' => $paypalInfo
            ]
        );

        $api = $this->getMock(
            'Magento\Paypal\Model\Api\Nvp',
            ['call', 'getExportedShippingAddress', 'getExportedBillingAddress'],
            [],
            '',
            false
        );
        $api->expects($this->any())->method('call')->will($this->returnValue([]));
        $apiTypeFactory->expects($this->any())->method('create')->will($this->returnValue($api));

        $exportedBillingAddress = $this->_getExportedAddressFixture($quote->getBillingAddress()->getData());
        $api->expects($this->any())
            ->method('getExportedBillingAddress')
            ->will($this->returnValue($exportedBillingAddress));

        $exportedShippingAddress = $this->_getExportedAddressFixture($quote->getShippingAddress()->getData());
        $api->expects($this->any())
            ->method('getExportedShippingAddress')
            ->will($this->returnValue($exportedShippingAddress));

        $paypalInfo->expects($this->once())->method('importToPayment')->with($api, $quote->getPayment());

        $quote->getPayment()->setAdditionalInformation(Checkout::PAYMENT_INFO_BUTTON, 1);

        $checkoutModel->returnFromPaypal('token');

        $billingAddress = $quote->getBillingAddress();

        $this->assertContains('exported', $billingAddress->getFirstname());
        $this->assertEquals('note', $billingAddress->getCustomerNote());

        $shippingAddress = $quote->getShippingAddress();
        $this->assertTrue((bool)$shippingAddress->getSameAsBilling());
        $this->assertNull($shippingAddress->getPrefix());
        $this->assertNull($shippingAddress->getMiddlename());
        $this->assertNull($shippingAddress->getLastname());
        $this->assertNull($shippingAddress->getSuffix());
        $this->assertTrue($shippingAddress->getShouldIgnoreValidation());
        $this->assertContains('exported', $shippingAddress->getFirstname());

        $paymentAdditionalInformation = $quote->getPayment()->getAdditionalInformation();
        $this->assertArrayHasKey(Checkout::PAYMENT_INFO_TRANSPORT_SHIPPING_METHOD, $paymentAdditionalInformation);
        $this->assertArrayHasKey(Checkout::PAYMENT_INFO_TRANSPORT_PAYER_ID, $paymentAdditionalInformation);
        $this->assertArrayHasKey(Checkout::PAYMENT_INFO_TRANSPORT_TOKEN, $paymentAdditionalInformation);
        $this->assertTrue($quote->getPayment()->hasMethod());

        $this->assertTrue($quote->getTotalsCollectedFlag());
        $this->assertFalse($quote->hasDataChanges());
    }

    /**
     * Prepare fixture for exported address
     *
     * @param array $addressData
     * @return \Magento\Framework\DataObject
     */
    protected function _getExportedAddressFixture(array $addressData)
    {
        $addressDataKeys = ['firstname', 'lastname', 'street', 'city', 'telephone'];
        $result = [];
        foreach ($addressDataKeys as $key) {
            if (isset($addressData[$key])) {
                $result[$key] = 'exported' . $addressData[$key];
            }
        }
        $fixture = new \Magento\Framework\DataObject($result);
        $fixture->setExportedKeys($addressDataKeys);
        $fixture->setData('note', 'note');
        return $fixture;
    }

    /**
     * @return Quote
     */
    protected function _getFixtureQuote()
    {
        /** @var \Magento\Quote\Model\Resource\Quote\Collection $quoteCollection */
        $quoteCollection = $this->_objectManager->create('Magento\Quote\Model\Resource\Quote\Collection');

        return $quoteCollection->getLastItem();
    }
}
