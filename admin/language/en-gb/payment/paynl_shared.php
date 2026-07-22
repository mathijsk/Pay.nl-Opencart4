<?php
// Pay. shared admin settings field labels/tooltips - included by every
// paynl_<method> admin language file (e.g. payment/paynl_ideal.php).
// Ported from the OC3 plugin's shared admin/language/*/extension/payment/paynl3.php,
// with the version-check and "send suggestion to Pay.nl" sections
// removed (those phone-home features were deliberately not ported -
// see AdminController's own docblock).

$_['text_apitoken'] = 'API token';
$_['text_serviceid'] = 'Sales location';
$_['text_tokencode'] = 'Token Code';

$_['text_payment'] = 'Payment';

$_['button_save'] = 'Save';
$_['button_cancel'] = 'Cancel';

$_['text_success'] = 'Settings saved';
$_['text_home'] = 'Dashboard';
$_['text_extension'] = 'Pay.';
$_['error_permission'] = 'You do not have permission to modify these settings!';

$_['error_not_activated'] = 'This payment method is not activated for this service. Go to '
	. '<a target="paynl" href="https://admin.pay.nl/programs/programs">https://admin.pay.nl/programs/programs</a> to change this.';
$_['error_no_apitoken'] = 'You need to enter an API token, you can find your API tokens at: '
	. '<a href="https://admin.pay.nl/company/tokens">https://admin.pay.nl/company/tokens</a>';
$_['error_no_serviceid'] = 'You need to enter a serviceId, you can find your serviceId at: '
	. '<a href="https://admin.pay.nl/programs/programs">https://admin.pay.nl/programs/programs</a>. A serviceId always starts with SL-';
$_['error_wrong_apitoken'] = 'Invalid API token, you can find your API token at: <a href="https://admin.pay.nl/company/tokens">https://admin.pay.nl/company/tokens</a>';
$_['error_wrong_tokencode'] = 'Invalid token code, a token code always starts with AT-, you can find your token code at: <a href="https://admin.pay.nl/company/tokens">https://admin.pay.nl/company/tokens</a>';
$_['error_wrong_serviceid'] = 'Invalid sales location, a sales location always starts with SL-';

$_['text_register'] = "Don't have a Pay. account yet? Click ";
$_['text_link_register'] = 'here';
$_['link_register'] = 'https://www.pay.nl/en/register';
$_['text_after_register'] = ' to sign up.';

$_['text_general_settings'] = 'Pay. General Settings';
$_['text_method_settings'] = 'Payment Method Settings';

$_['text_confirm_start_tooltip'] = 'Confirm the order when the transaction starts, i.e. before it has been paid. The confirmation email is then also sent immediately.';
$_['text_confirm_start'] = 'Confirm order on transaction start';
$_['text_send_statusupdates'] = 'Send status updates';
$_['text_send_statusupdates_tooltip'] = 'Email the customer whenever the order status changes.';

$_['text_gateway'] = 'Failover gateway';
$_['text_gateway_tooltip'] = 'Only fill this in if Pay. has given you a gateway to enter here.';

$_['text_prefix'] = 'Order description prefix';
$_['text_prefix_tooltip'] = 'Change the order description prefix here. If left empty, the description will be the order number.';

$_['text_advanced_settings'] = 'Advanced Settings';

$_['text_auto_void'] = 'Auto void';
$_['text_auto_void_tooltip'] = 'Automatically release (void) authorized transactions when an order is cancelled.';

$_['text_auto_capture'] = 'Auto capture';
$_['text_auto_capture_tooltip'] = 'Enable auto capture for reserved transactions with status AUTHORIZE. The capture happens when an order status changes to Completed.';

$_['text_refund_processing'] = 'Refund processing';
$_['text_refund_processing_tooltip'] = 'Process refunds initiated from Pay.';

$_['text_follow_payment_method'] = 'Follow payment method';
$_['text_follow_payment_method_tooltip'] = 'Updates the order with the actual payment method used to complete it. This can differ from the initially selected payment method.';

$_['text_coc'] = 'Show COC number field';
$_['text_coc_tooltip'] = 'When enabled, the customer gets an option to enter their COC number before completing the transaction.';
$_['text_coc_disabled'] = 'No';
$_['text_coc_enabled'] = 'Yes, as an optional field';
$_['text_coc_required'] = 'Yes, as a required field';

$_['text_vat'] = 'Show VAT-id field for business customers';
$_['text_vat_tooltip'] = 'When enabled, the customer gets an option to enter their VAT-id before completing the transaction.';
$_['text_vat_disabled'] = 'Off';
$_['text_vat_enabled'] = 'Optional for business customers';
$_['text_vat_required'] = 'Required for business customers';

$_['text_dob'] = 'Show date of birth field';
$_['text_dob_tooltip'] = 'When enabled, the customer gets an option to enter their date of birth before completing the transaction.';
$_['text_dob_disabled'] = 'No';
$_['text_dob_enabled'] = 'Yes, as an optional field';
$_['text_dob_required'] = 'Yes, as a required field';

$_['text_display_icon'] = 'Display icon';
$_['text_display_icon_tooltip'] = 'Select here whether you want to display an icon, and which size.';

$_['text_custom_exchange_url'] = 'Custom Exchange URL';
$_['text_custom_exchange_url_tooltip'] = 'Use your own exchange handler. Requests will be sent as a GET.<br/>Example: https://www.yourdomain.com/exchange_handler?action=#action#&order_id=#order_id#';

$_['text_current_ip'] = "Current user's IP address: ";
$_['text_test_ip'] = 'Test IP Addresses';
$_['text_test_ip_tooltip'] = 'Force test mode for the given IP addresses, separate multiple IPs with commas.';

$_['text_logging'] = 'Logging';
$_['text_logging_tooltip'] = 'Enable logging';

$_['text_testmode'] = 'Test mode';
$_['text_testmode_tooltip'] = 'Turn test mode on or off to test the exchanges between Pay. and your webshop.';

$_['text_display_fast_checkout'] = 'Show the Fast Checkout button';
$_['text_display_fast_checkout_tooltip'] = 'Enable or disable the Fast Checkout button in the cart.';
$_['text_default_shipping_method'] = 'Default shipping method';
$_['text_only_guest'] = 'Guests only';

$_['text_status_pending'] = 'Order status: awaiting payment';
$_['text_status_pending_tooltip'] = "The order's status once payment has started but not yet completed.";
$_['text_status_complete'] = 'Order status: payment completed';
$_['text_status_complete_tooltip'] = "The status the order should get once payment has been successfully received.";
$_['text_status_canceled'] = 'Order status: cancelled';
$_['text_status_canceled_tooltip'] = "The status the order should get once payment has been cancelled.";
$_['text_status_refunded'] = 'Order status: refunded';
$_['text_status_refunded_tooltip'] = "The status the order should get once payment has been refunded.";
$_['text_minimum_amount'] = 'Minimum order amount';
$_['text_maximum_amount'] = 'Maximum order amount';
$_['text_payment_instructions'] = 'Instructions';
$_['text_payment_instructions_tooltip'] = 'If you want to show instructions to the customer, you can enter them here.';

$_['entry_order_status'] = 'Order Status';
$_['entry_geo_zone'] = 'Geo Zone';
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sort Order';

$_['text_customer_type'] = 'Allowed customer type';
$_['text_customer_type_tooltip'] = 'Select which type of customer can use this payment method.';
$_['text_both'] = 'Both';
$_['text_private'] = 'Private';
$_['text_business'] = 'Business';

$_['text_enabled'] = 'Enabled';
$_['text_disabled'] = 'Disabled';
$_['text_yes'] = 'Yes';
$_['text_no'] = 'No';
