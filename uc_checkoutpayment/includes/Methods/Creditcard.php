<?php

/**
 * Handle all payment request to the cko server.
 */
class MethodsCreditcard {

  /**
   * Get and format all data for posting to the server.
   *
   * @param object $order
   *   A Ubercart order object.
   * @param array $payment_method
   *   An array with the CKO settings.
   *
   * @return array
   *   An array with all data formatted to send to the cko server.
   */
  public function getExtraInit($order, array $payment_method) {
    $array = array();

    $payment_token = $this->generatePaymentToken($order, $payment_method);

    if ($order) {
      $amount_cents = number_format(round($order->order_total, variable_get('uc_currency_prec', 2)) * 100, 0, '', '');
      $config = array();

      $config['email'] = $order->primary_email;
      $config['name'] = $order->billing_first_name . ' ' . $order->billing_last_name;
      $config['amount'] = $amount_cents;
      $config['currency'] = $order->currency;
      $config['paymentToken'] = $payment_token['token'];

      $config['billingDetails'] = array(
        'addressLine1' => $order->billing_street1,
        'addressLine2' => $order->billing_street2,
        'postcode' => $order->billing_postal_code,
        'country' => uc_get_country_data(array('country_id' => $order->billing_country))[0]['country_iso_code_2'],
        'city' => $order->billing_city,
        'state' => $order->delivery_zone,
        'phone' => array(
          "number" => $order->billing_phone,
        ),
      );

      $config['shippingDetails'] = array(
        'addressLine1' => $order->delivery_street1,
        'addressLine2' => $order->delivery_street2,
        'postcode' => $order->delivery_postal_code,
        'country' => uc_get_country_data(array('country_id' => $order->delivery_country))[0]['country_iso_code_2'],
        'city' => $order->delivery_city,
        'state' => $order->delivery_zone,
        'phone' => array(
          "number" => $order->delivery_phone,
        ),
      );

      foreach ($order->products as $product) {
        $config['products'][] = array(
          'name' => $product->title,
          'description' => $product->title,
          'price' => round($product->price, 2),
          'quantity' => (int) $product->qty,
          'sku' => $product->model,
        );
      }

      $array['script'] = $config;
      $array['paymentToken'] = $payment_token;
    }

    return $array;
  }

  /**
   * Method to create a paymenttoken to allow local payment methods.
   *
   * @param object $order
   *   A Ubercart order object.
   * @param array $payment_method
   *   An array with the CKO settings.
   *
   * @var chargeMode should always be set to "3". (legacy of deprecated option).
   *
   * @return array
   *   Payment token message array.
   */
  public function generatePaymentToken($order, array $payment_method) {
    $config = array();

    $product_items = $order->products;

    if (isset($order)) {

      $order_id = $order->order_id;
      $default_currency = $order->currency;
      $amount_cents = number_format((100 * $order->order_total), 0, '', '');
      $autoCapture = ($payment_method['settings']['payment_action'] == UC_CREDIT_AUTH_CAPTURE ? "y" : "n");

      $config['authorization'] = $payment_method['settings']['private_key'];
      $config['mode'] = $payment_method['settings']['mode'];
      $config['timeout'] = $payment_method['settings']['timeout'];

      if ($payment_method['settings']['payment_action'] == 'authorize') {
        $config = array_merge($config, $this->authorizeConfig());
      }
      else {
        $config = array_merge($config, $this->captureConfig($payment_method));
      }

      $products = array();
      if (!empty($product_items)) {
        foreach ($product_items as $key => $item) {
          if (isset($item)) {
            $products[$key] = array(
              'name' => $item->title,
              'sku' => $item->qty,
              'price' => $item->price,
              'quantity' => $item->qty,
            );
          }
        }
      }

      $billing_address_config = array(
        'addressLine1' => $order->billing_street1,
        'addressLine2' => $order->billing_street2,
        'postcode' => $order->billing_postal_code,
        'country' => uc_get_country_data(array('country_id' => $order->billing_country))[0]['country_iso_code_2'],
        'city' => $order->billing_city,
        'state' => $order->delivery_zone,
        'phone' => array(
          "number" => $order->billing_phone,
        ),
      );

      $shipping_address_config = array(
        'addressLine1' => $order->delivery_street1,
        'addressLine2' => $order->delivery_street2,
        'postcode' => $order->delivery_postal_code,
        'country' => uc_get_country_data(array('country_id' => $order->delivery_country))[0]['country_iso_code_2'],
        'city' => $order->delivery_city,
        'state' => $order->delivery_zone,
        'phone' => array(
          "number" => $order->delivery_phone,
        ),
      );

      $config['postedParam'] = array(
        'chargeMode' => "3",
        'autoCapture' => $autoCapture,
        'autoCapTime' => $payment_method['settings']['autocaptime'],
        'email' => $order->primary_email,
        'value' => $amount_cents,
        'trackId' => $order_id,
        'currency' => $default_currency,
        'description' => 'Order number::' . $order_id,
        'shippingDetails' => $shipping_address_config,
        'products' => $products,
        'card' => array(
          'billingDetails' => $billing_address_config,
        ),
      );

      $api = CheckoutapiApi::getApi(array('mode' => $config['mode']));

      $payment_token_charge = $api->getPaymentToken($config);

      $payment_token_array = array(
        'message' => '',
        'success' => '',
        'eventId' => '',
        'token' => '',
      );

      if ($payment_token_charge->isValid()) {
        $payment_token_array['token'] = $payment_token_charge->getId();
        $payment_token_array['success'] = TRUE;
      }
      else {
        $payment_token_array['message'] = $payment_token_charge->getExceptionstate()->getErrorMessage();
        $payment_token_array['success'] = FALSE;
        $payment_token_array['eventId'] = $payment_token_charge->getEventId();
      }
    }

    return $payment_token_array;
  }

  /**
   * Get the JS config script.
   *
   * Unused piece of code. The API code is to limited to use for this purpose.
   * This will be used when The API is update to give more flexibility.
   *
   * @param object $order
   *   A Ubercart order object.
   * @param array $payment_method
   *   An array with the CKO settings.
   *
   * @return string
   *   JS Script with the lightbox.
   */
  public function getWidgetElement($order, array $payment_method) {
    global $base_url;

    $data = $this->getExtraInit($order, $payment_method);
    $settings = $payment_method['settings'];

    $config = array(
      'publicKey' => $settings['public_key'],
      'paymentToken' => $data['script']['paymentToken'],
      'customerEmail' => $data['script']['email'],
      'value' => $data['script']['amount'],
      'currency' => $data['script']['currency'],
      'renderMode' => $settings['cko_render_mode'],
      'formButtonLabel' => $settings['button_label'],
      'title' => $settings['title'],
      'themeColor' => $settings['themecolor'],
      'logoUrl' => $settings['logourl'],
      'subtitle' => $settings['subtitle'],
      'localisation' => $settings['cko_language'],
      'useCurrencyCode' => $settings['currencycode'],

    );

    $redirectUrl = $base_url . REDIRECT_URL;

    return "
      <form class=\"payment-form\" method=\"POST\" action=\"" . $redirectUrl . "\">
        <script>
          window.CKOConfig = {
            publicKey: '" . $config['publicKey'] . "',
            paymentToken: '" . $config['paymentToken'] . "',
            customerEmail: '" . $config['customerEmail'] . "',
            value: " . $config['value'] . ",
            currency: '" . $config['currency'] . "',
            renderMode: " . $config['renderMode'] . ",
            formButtonLabel: '" . $config['formButtonLabel'] . "',
            title: '" . $config['title'] . "',
            themeColor: '" . $config['themeColor'] . "',
            formButtonColor: '" . $config['themeColor'] . "',
            logoUrl: '" . $config['logoUrl'] . "',
            subtitle: '" . $config['subtitle'] . "',
            localisation: '" . $config['localisation'] . "',
            useCurrencyCode: '" . $config['useCurrencyCode'] . "',
            redirectUrl: '" . $redirectUrl . "',
            cardFormMode: 'cardTokenisation',
          };
        </script>
        <script async src=\"https://cdn.checkout.com/sandbox/js/checkout.js\"></script>
      </form>
    ";
  }

  /**
   * Get the frames script.
   *
   * The simplest usage would be:
   *   get_payment_frames();
   *
   * More advaced usage could be:
   *   $config = array(
   *     'submit_form' => FALSE,
   *     'js_function' => 'myCustomJavascriptAction'
   *   );
   *   get_payment_frames($config);
   *
   *   This will call the function myCustomJavascriptAction(cardToken).
   *
   * @param array $config
   *   Configuration settings for the frames element.
   *
   * @return string
   *   returns the html form element.
   */
  public function getFramesElement(array $config) {

    $submit_action = "paymentForm.submit();";

    if (array_key_exists('submit_form', $config) && $config['submit_form'] == FALSE) {
      $submit_action = "";

      if (array_key_exists('js_function', $config)) {
        $submit_action .= "window." . $config['js_function'] . "(cardToken);";
      }
    }

    $frame = "
      <script>
      (function () {
        var paymentForm = document.getElementById(\"payment-form\");
        var payNowButton = document.getElementById(\"pay-now-button\");

        Frames.init({
          publicKey: \"" . $config['settings']["public_key"] . "\",
          containerSelector: \".frames-container\",
          cardValidationChanged: function() {
            payNowButton.disabled = !Frames.isCardValid();
          },
          cardSubmitted: function() {
            payNowButton.disabled = true;
          },
          cardTokenised: function(event) {
            var cardToken = event.data.cardToken;
            Frames.addCardToken(paymentForm, cardToken)
            " . $submit_action . "
          },
          cardTokenisationFailed: function(event) {
          }
        });
        paymentForm.addEventListener(\"submit\", function(event) {
          event.preventDefault();
          Frames.submitCard()
          .then(function(data) {
            payNowButton = document.getElementById(\"pay-now-button\");
            var cardToken = data.cardToken;
            Frames.addCardToken(paymentForm, cardToken);
            " . $submit_action . "
          })
          .catch(function(err) {
          });
        });
      }());
      </script>";

    return $frame;
  }

  /**
   * Create a cko charge request.
   *
   * This request contains a payment- or card-token and all other necessary
   * information to make a charge request.
   *
   * @param array $config
   *   An array with the requiremnts to create a charge request.
   *
   * @return array
   *   Http result.
   */
  protected function createCharge(array $config) {
    $config = array();

    $payment_method = ubercart_payment_method_instance_load('uc_checkoutpayment|ubercart_payment_uc_checkoutpayment');
    $secret_key = $payment_method['settings']['private_key'];
    $mode = $payment_method['settings']['mode'];
    $timeout = $payment_method['settings']['timeout'];

    $config['authorization'] = $secret_key;
    $config['timeout'] = $timeout;
    $config['paymentToken'] = $_POST['cko-cc-paymenToken'];

    $api = CheckoutapiApi::getApi(array('mode' => $mode));
    return $api->verifyChargePaymentToken($config);
  }

  /**
   * Configure the autocapture.
   *
   * @param array $action
   *   An array with the CKO settings.
   *
   * @return array
   *   An array.
   */
  protected function captureConfig(array $action) {
    $to_return['postedParam'] = array(
      'autoCapture' => CheckoutapiClientConstant::AUTOCAPUTURE_CAPTURE,
      'autoCapTime' => $action['settings']['autocaptime'],
    );
    return $to_return;
  }

  /**
   * Authorize config settings.
   *
   * @return array
   *   An array.
   */
  protected function authorizeConfig() {
    $to_return['postedParam'] = array(
      'autoCapture' => CheckoutapiClientConstant::AUTOCAPUTURE_AUTH,
      'autoCapTime' => 0,
    );
    return $to_return;
  }

  /**
   * Create a cko capture request for an order which is Authorized.
   *
   * @param object $order
   *   A Ubercart order object.
   * @param array $payment_method
   *   An array with the CKO settings.
   * @param int $value
   *   Is the value which should be captured.
   *
   * @return httpresponse
   *   The response from the CKO server.
   */
  public function captureCharge($order, array $payment_method, $value) {
    $config = array();

    $secret_key = $payment_method['settings']['private_key'];
    $mode = $payment_method['settings']['mode'];

    $result = db_select('uc_checkoutpayment_hub_communication', 'c')
      ->fields('c')
      ->condition('track_id', $order->order_id, '=')
      ->condition('status', "Authorised", '=')
      ->execute()
      ->fetchObject();

    $config['authorization'] = $secret_key;
    $config['chargeId'] = $result->id;
    $config['postedParam'] = array(
      'value' => $value,
    );

    $api = CheckoutapiApi::getApi(array('mode' => $mode));
    return $api->captureCharge($config);
  }

  /**
   * Create a cko refund request for an order which is captured.
   *
   * @param object $order
   *   A Ubercart order object.
   * @param array $payment_method
   *   An array with the CKO settings.
   * @param int $value
   *   Is the value which should be captured.
   *
   * @return httpresponse
   *   The response from the CKO server.
   */
  public function refundCharge($order, array $payment_method, $value) {
    $payedAmount = ($order->order_total - uc_payment_balance($order)) * 100;

    if ($value <= $payedAmount) {

      $config = array();

      $secret_key = $payment_method['settings']['private_key'];
      $mode = $payment_method['settings']['mode'];

      $result = db_select('uc_checkoutpayment_hub_communication', 'c')
        ->fields('c')
        ->condition('track_id', $order->order_id, '=')
        ->condition('status', "Captured", '=')
        ->execute()
        ->fetchObject();

      $config['authorization'] = $secret_key;
      $config['chargeId'] = $result->id;
      $config['postedParam'] = array(
        'value' => $value,
      );

      $api = CheckoutapiApi::getApi(array('mode' => $mode));
      return $api->refundCharge($config);
    }
  }

}
