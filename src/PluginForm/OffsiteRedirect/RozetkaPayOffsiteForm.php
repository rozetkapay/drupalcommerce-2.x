<?php

namespace Drupal\commerce_rozetkapay\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides the RozetkaPay (redirect to payment page) offsite payment.
 */
class RozetkaPayOffsiteForm extends BasePaymentOffsiteForm {

  /**
   * Additional const.
   */
  const METHOD = 'get';

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $payment = $this->getEntity();
    /** @var \Drupal\commerce_rozetkapay\Plugin\Commerce\PaymentGateway\RozetkaPay $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    // Return url.
    $return_url = $form['#return_url'];
    // Callback url.
    $callback_url = $payment_gateway_plugin->getNotifyUrl()->toString();

    [$rozetkapay_response, $status_code] = $payment_gateway_plugin->paymentCreate($payment, $return_url, $callback_url);

    if ($status_code !== 200) {
      return FALSE;
    }

    return $this->buildRedirectForm(
      $form,
      $form_state,
      $rozetkapay_response['action']['value'],
      [],
      self::METHOD
    );
  }
}
