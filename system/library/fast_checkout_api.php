<?php
namespace Opencart\System\Library\Extension\Paynl;

require_once __DIR__ . '/paynl/vendor/autoload.php';

/**
 * Class FastCheckoutApi
 *
 * Client for Pay.nl's Fast Checkout "orders" endpoint
 * (connect.payments.nl), ported from OC3's Pay_Api + Pay_Api_FastCheckout.
 * This is a genuinely separate API surface from the rest of this
 * extension: the modern paynl/php-sdk vendor library (used everywhere
 * else - OrderCreateRequest, OrderStatusRequest, etc.) does not support
 * the "optimize"/fastCheckout flow at all (confirmed by searching its
 * source for "optimize"/"fastCheckout" - no matches), so fast-checkout
 * needs its own client. Built on Guzzle (already a dependency via the
 * vendor SDK) rather than porting the original's raw curl_* calls.
 *
 * @package Opencart\System\Library\Extension\Paynl
 */
class FastCheckoutApi {
	private const GATEWAY = 'https://connect.payments.nl';

	private string $serviceId = '';
	private string $apiToken = '';
	private bool $testmode = false;
	private string $orderNumber = '';
	private int $amount = 0;
	private string $currency = '';
	private string $description = '';
	private string $reference = '';
	private array $optimize = [];

	/** @var int|array<string, mixed> */
	private $paymentMethod = 10;

	private string $returnUrl = '';
	private string $exchangeUrl = '';

	/** @var array<int, array<string, mixed>> */
	private array $products = [];

	/** @var array<string, mixed> */
	private array $lastPostData = [];

	public function setServiceId(string $serviceId): void {
		$this->serviceId = $serviceId;
	}

	public function setApiToken(string $apiToken): void {
		$this->apiToken = $apiToken;
	}

	public function setTestmode(bool $testmode): void {
		$this->testmode = $testmode;
	}

	public function setOrderNumber(string $orderNumber): void {
		$this->orderNumber = $orderNumber;
	}

	/**
	 * Set Amount
	 *
	 * @param int $amount Amount in cents
	 *
	 * @return void
	 */
	public function setAmount(int $amount): void {
		$this->amount = $amount;
	}

	public function setCurrency(string $currency): void {
		$this->currency = $currency;
	}

	public function setDescription(string $description): void {
		$this->description = $description;
	}

	public function setReference(string $reference): void {
		$this->reference = $reference;
	}

	/**
	 * Set Optimize
	 *
	 * Tells Pay.nl to collect the customer's contact/shipping/billing
	 * details itself during the fast-checkout flow, since - unlike
	 * normal checkout - the customer hasn't gone through OpenCart's own
	 * checkout steps at all when this is used.
	 *
	 * @param bool $contactDetails
	 * @param bool $shippingAddress
	 * @param bool $billingAddress
	 *
	 * @return void
	 */
	public function setOptimize(bool $contactDetails = true, bool $shippingAddress = true, bool $billingAddress = true): void {
		$this->optimize = [
			'flow'            => 'fastCheckout',
			'contactDetails'  => $contactDetails,
			'shippingAddress' => $shippingAddress,
			'billingAddress'  => $billingAddress
		];
	}

	/**
	 * Set Payment Method
	 *
	 * @param int|array<string, mixed> $paymentMethod Either a plain
	 *                                                 payment-option id,
	 *                                                 or (e.g. for PayPal)
	 *                                                 an ['id' => ...,
	 *                                                 'input' => [...]]
	 *                                                 shape.
	 *
	 * @return void
	 */
	public function setPaymentMethod($paymentMethod): void {
		$this->paymentMethod = $paymentMethod;
	}

	public function setReturnUrl(string $returnUrl): void {
		$this->returnUrl = $returnUrl;
	}

	public function setExchangeUrl(string $exchangeUrl): void {
		$this->exchangeUrl = $exchangeUrl;
	}

	/**
	 * Add Product
	 *
	 * Purely administrative - does not affect the charged amount.
	 *
	 * @param string $id
	 * @param string $description
	 * @param int    $price         In cents
	 * @param int    $quantity
	 * @param string $vatClass      N/L/H (see Helper::calculateTaxClass())
	 * @param string $type
	 *
	 * @return void
	 */
	public function addProduct(string $id, string $description, int $price, int $quantity, string $vatClass, string $type = 'ARTICLE'): void {
		$this->products[] = [
			'productId'   => $id,
			'description' => substr($description, 0, 45),
			'price'       => ['value' => $price],
			'quantity'    => $quantity,
			'vatCode'     => $vatClass,
			'productType' => $type
		];
	}

	/**
	 * Get Post Data
	 *
	 * @return array<string, mixed>
	 */
	public function getPostData(): array {
		return $this->lastPostData;
	}

	/**
	 * Do Request
	 *
	 * @return array<string, mixed>
	 *
	 * @throws PayException
	 */
	public function doRequest(): array {
		$paymentMethod = is_int($this->paymentMethod) ? ['id' => $this->paymentMethod] : $this->paymentMethod;

		$data = [
			'serviceId'     => $this->serviceId,
			'amount'        => ['value' => $this->amount, 'currency' => $this->currency],
			'description'   => $this->description,
			'reference'     => $this->reference,
			'optimize'      => $this->optimize,
			'paymentMethod' => $paymentMethod,
			'returnUrl'     => $this->returnUrl,
			'exchangeUrl'   => $this->exchangeUrl,
			'order'         => ['products' => $this->products]
		];

		if ($this->testmode) {
			$data['integration'] = ['test' => true];
		}

		$this->lastPostData = $data;

		$authorization = base64_encode('token:' . $this->apiToken);

		$client = new \GuzzleHttp\Client();

		try {
			$response = $client->post(self::GATEWAY . '/v1/orders', [
				'headers' => [
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Basic ' . $authorization
				],
				'json'    => $data,
				'timeout' => 30
			]);

			$result = json_decode((string)$response->getBody(), true);
		} catch (\GuzzleHttp\Exception\GuzzleException $e) {
			throw new PayException('Fast checkout request failed: ' . $e->getMessage());
		}

		if (empty($result['links']['redirect'])) {
			if (!empty($result['request']['errorId']) && !empty($result['request']['errorMessage'])) {
				throw new PayException($result['request']['errorId'] . ' - ' . $result['request']['errorMessage']);
			} elseif (!empty($result['error'])) {
				throw new PayException(is_array($result['error']) ? json_encode($result['error']) : $result['error']);
			}

			throw new PayException('Unexpected fast checkout API result');
		}

		return $result;
	}
}
