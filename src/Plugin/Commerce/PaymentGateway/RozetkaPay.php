<?php

namespace Drupal\commerce_rozetkapay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the RozetkaPay offsite Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "rozetkapay_redirect",
 *   label = @Translation("RozetkaPay (redirect to payment page)"),
 *   display_label = @Translation("RozetkaPay"),
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_rozetkapay\PluginForm\OffsiteRedirect\RozetkaPayOffsiteForm",
 *   },
 * )
 */
class RozetkaPay extends OffsitePaymentGatewayBase {

  /**
   * The supported API version.
   */
  const API_VERSION = 'v1';

  /**
   * The API base url.
   */
  const API_BASE_URL = 'https://api.rozetkapay.com/api/';

  /**
   * The payment info details.
   */
  const PAYMENT_INFO_DETAILS = [
    'purchase_details',
    'confirmation_details',
    'confirmation_details',
    'refund_details',
  ];

  /**
   * The http client service.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The log service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The payment storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $paymentStorage;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->httpClient = $container->get('http_client');
    $instance->logger = $container->get('logger.factory')->get('commerce_rozetkapay');
    $instance->paymentStorage = $container->get('entity_type.manager')->getStorage('commerce_payment');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'login' => '',
        'password' => '',
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['login'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Login'),
      '#description' => $this->t('This is the login for authorization in the RozetkaPay API.'),
      '#default_value' => $this->configuration['login'],
      '#required' => TRUE,
    ];

    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#description' => $this->t('This is the password for authorization in the RozetkaPay API'),
      '#default_value' => $this->configuration['password'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['login'] = $values['login'];
      $this->configuration['password'] = $values['password'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    [$response, $status_code] = $this->paymentInfo($order->id());

    if (!$response && $status_code != 200) {
      $this->logger->error($this->t(
        'Invalid Transaction. Order #@orderid. Status code: @status_code',
        [
          '@orderid' => $order->id(),
          '@status_code' => $status_code
        ]
      ));
      $this->messenger()->addMessage($this->t('Invalid Transaction. Please try again'), 'error');

      return $this->onCancel($order, $request);
    }

    if ($data = $this->isPaymentValid($order, $response, 'return')) {
      $payment = $this->paymentStorage->create([
        'state' => 'completed',
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $this->parentEntity->id(),
        'order_id' => $order->id(),
        'remote_id' => $response['id'],
        'remote_state' => $data['order_status'],
      ]);
      $payment->save();

      $this->messenger()->addMessage(
        $this->t('Your payment was successful with Order id : @orderid and Transaction id : @payment_id',
          [
            '@orderid' => $order->id(),
            '@payment_id' => $data['transaction_id']
          ]
        )
      );
    }
    else {
      $this->logger->error($this->t(
        'Invalid order #@orderid and Transaction id : @payment_id.',
        [
          '@orderid' => $order->id(),
          '@payment_id' => $data['transaction_id']
        ]
      ));
      $this->messenger()->addMessage($this->t('Invalid. Please try again'), 'error');

      return $this->onCancel($order, $request);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    // Get the request data.
    if ($request->request->all()) {
      $data = $request->request->all();
    }
    else {
      $data = $request->getContent();
    }

    // Check if exist the data from request.
    if (!empty($data)) {
      $decoded_data = json_decode($data, true);
    }
    else {
      $this->logger->error($this->t('Error while processing payment. Details: request data is empty'));
      return new Response('Error while processing payment. Details: request data is empty', 400);
    }

    // Check if exist the external_id.
    if (empty($decoded_data['external_id'])) {
      $this->logger->error($this->t('Missing external_id parameter.'));
      return new Response('Missing external_id parameter.', 400);
    }

    // Get the order.
    $external_id = $decoded_data['external_id'];
    $order_id = preg_replace('/\D/', '', $external_id);
    $commerce_order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $order = $commerce_order_storage->load($order_id);

    if (!$order) {
      $this->logger->error($this->t(
        'Order #@order_id is not exist. Transaction #@transaction_id status: @transaction_status.',
        [
          '@transaction_id' => $decoded_data['details']['transaction_id'],
          '@transaction_status' => $decoded_data['details']['status'],
          '@order_id' => $order_id,
        ]
      ));

      return new Response(
        "The order {$order_id} is not exist. Transaction #{$decoded_data['details']['transaction_id']} status: {$decoded_data['details']['status']}.",
        400
      );
    }

    // Is valid payment.
    if ($data = $this->isPaymentValid($order, $decoded_data)) {
      $payment = $this->paymentStorage->create([
        'state' => 'completed',
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $this->parentEntity->id(),
        'order_id' => $order->id(),
        'remote_id' => $decoded_data['id'],
        'remote_state' => $data['order_status'],
      ]);
      $payment->save();

      $this->messenger()->addMessage(
        $this->t('Your payment was successful with Order id : @orderid and Transaction id : @payment_id',
          [
            '@orderid' => $order->id(),
            '@payment_id' => $data['transaction_id']
          ]
        )
      );
    }
    else {
      $this->logger->error($this->t('Invalid Transaction. Please try again'));
      $this->messenger()->addError($this->t('Invalid Transaction. Please try again'));

      return $this->onCancel($order, $request);
    }
  }

  /**
   * Generates BasicAuth authorization.
   */
  public function setBasicAuth(): array {
    $login = $this->configuration['login'];
    $password = $this->configuration['password'];
    $token = base64_encode($login . ':' . $password);

    return [
      'Content-Type: application/json',
      'Authorization: Basic ' . $token,
    ];
  }

  /**
   * Create payment.
   */
  public function paymentCreate($payment, $return_url, $callback_url): array {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();
    $order_id = $order->id();

    $amount = $payment->getAmount()->getNumber();
    $currency = $payment->getAmount()->getCurrencyCode();

    if ($payment->getOrder()->getCustomer()->isAnonymous() === FALSE) {
      $description = sprintf("%s%s. %s%s",
        $this->t('Customer: '),
        $payment->getOrder()
          ->getCustomer()
          ->getAccountName(),
        $this->t('Order #: '),
        $order_id
      );
    }
    else {
      $description = $this->t('Customer: anonymous');
    }

    $data = [
      'amount' => $amount,
      'currency' => $currency,
      'description' => $description,
      'external_id' => sprintf('order_%s', $order_id),
      'mode' => 'hosted',
      'callback_url' => $callback_url,
      'result_url' => $return_url,
    ];

    $data = json_encode($data);

    // Builds endpoint.
    $path = sprintf('payments/%s/new', self::API_VERSION);

    // Request.
    [$response, $status_code] = $this->doRequest($path, $data, 'POST');

    return [$response, $status_code];
  }

  /**
   * Get the payment info.
   */
  public function paymentInfo(string $order_id): array|bool {
    // Build endpoint.
    $path = sprintf('payments/%s/info?external_id=%s', self::API_VERSION, sprintf('order_1%s', $order_id));

    // Request.
    [$response, $status_code] = $this->doRequest($path);

    if (!$response && $status_code != 200) {
      return FALSE;
    }

    $result = [
      'id' => $response['id'],
      'amount' => $response['amount'],
      'currency' => $response['currency'],
    ];

    foreach (self::PAYMENT_INFO_DETAILS as $payment_info_detail) {
      if (isset($response[$payment_info_detail])) {
        foreach ($response[$payment_info_detail] as $detail) {
          $result['purchase_details'][] = [
            'transaction_id' => $detail['transaction_id'],
            'order_status' => $detail['status'],
            'order_status_code' => $detail['status_code'],
            'order_status_description' => $detail['status_description'],
          ];
        }
      }
    }

    return [$result, $status_code];
  }

  /**
   * Cancel payment.
   */
  public function paymentCancel($payment): array {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();
    $order_id = $order->id();

    // Get the amount and currency from order.
    $amount = $payment->getAmount()->getNumber();
    $currency = $payment->getAmount()->getCurrencyCode();

    // Build endpoint.
    $path = sprintf('payments/%s/cancel', self::API_VERSION);

    // Build data.
    $data = [
      'external_id' => sprintf('order_%s', $order_id),
      'amount' => $amount,
      'currency' => $currency,
    ];

    // Request.
    [$response, $status_code] = $this->doRequest($path, $data, 'POST');

    return [$response, $status_code];
  }

  /**
   * Refund payment.
   */
  public function paymentRefund($payment): array {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();
    $order_id = $order->id();

    // Get the amount and currency from order.
    $amount = $payment->getAmount()->getNumber();
    $currency = $payment->getAmount()->getCurrencyCode();

    // Build endpoint.
    $path = sprintf('payments/%s/refund', self::API_VERSION);

    // Build data.
    $data = [
      'external_id' => sprintf('order_%s', $order_id),
      'amount' => $amount,
      'currency' => $currency,
    ];

    // Request.
    [$response, $status_code] = $this->doRequest($path, $data, 'POST');

    return [$response, $status_code];
  }

  /**
   * Does a request to RozetkaPay API.
   */
  public function doRequest($path, $data = [], string $method = 'GET'): array {
    // Gets configuration settings.
    $config = $this->getConfiguration();
    // Gets the login and password from config.
    $login = $config['login'];
    $password = $config['password'];

    // Builds the endpoint API.
    $url = self::API_BASE_URL . $path;

    // Builds the basic auth.
    $headers = $this->setBasicAuth();

    $method = strtoupper($method);

    // Check data.
    if (!empty($data)) {
      $request_options = [
        'headers' => $headers,
        'body' => $data,
        'auth' => [$login, $password],
      ];
    }
    else {
      $request_options = [
        'headers' => $headers,
        'auth' => [$login, $password],
      ];
    }

    try {
      $response = $this->httpClient->request($method, $url, $request_options);
    } catch (\Exception $e) {
      $log = sprintf('Exception: %s', $e->getMessage());
      throw new PaymentGatewayException($log);
    }

    return [
      json_decode($response->getBody()->getContents(), true),
      $response->getStatusCode()
    ];
  }

  /**
   * The payment validation.
   */
  public function isPaymentValid($order, array $response, $callback_method = 'notify'): array|bool {
    if ($callback_method == 'return') {
      return $this->getPaymentInfoDetails($order, $response);
    }
    else {
      if (isset($response['details']) && $response['is_success']) {

        $detail = $response['details'];
        if ($detail['status_code'] === 'transaction_successful' && $this->validateSum($detail, $order)) {
          return $detail;
        }
      }
    }

    return FALSE;
  }

  /**
   * Get the paymentInfo details for the onReturn method.
   */
  public function getPaymentInfoDetails($order, $response): array|bool {
    foreach (self::PAYMENT_INFO_DETAILS as $payment_info_detail) {
      if (isset($response[$payment_info_detail])) {

        $detail = reset($response[$payment_info_detail]);
        if ($detail['order_status_code'] === 'transaction_successful' && $this->validateSum($response, $order)) {
          return $detail;
        }
      }
    }

    return FALSE;
  }

  /**
   * Build the products' data.
   *
   * TODO: This method will be use if need the order products to pass to the request.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_items
   *   The order items.
   *
   * @return array
   *   Return the products array.
   */
  public function getOrderProducts(OrderItemInterface $order_items): array {
    $products = [];
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    foreach ($order_items as $order_item) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $product_variation */
      $product_variation = $order_item->getPurchasedEntity();

      // Build products data.
      $products = [
        'id' => $product_variation->getProductId(),
        'name' => $product_variation->getTitle(),
        'currency' => $order_item->getTotalPrice()->getCurrencyCode(),
        'net_amount' => number_format((float)$order_item->getTotalPrice()->getNumber(), 2, '.', ''),
        'quantity' => number_format((float)$order_item->getQuantity(), 2, '.', ''),
      ];
    }

    return $products;
  }

  /**
   * The sum validation.
   */
  protected function validateSum($transaction_detail, $order_detail): bool {
    $transaction_currency = $transaction_detail['currency'];
    $transaction_amount = $transaction_detail['amount'];
    $order_currency = $order_detail->getTotalPrice()->getCurrencyCode();
    $order_amount = $order_detail->getTotalPrice()->getNumber();

    if ($transaction_currency != $order_currency) {
      return FALSE;
    }
    if ($transaction_amount != $order_amount) {
      return FALSE;
    }

    return TRUE;
  }

}
