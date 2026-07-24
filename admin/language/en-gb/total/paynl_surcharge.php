<?php
// Heading
$_['heading_title'] = 'Pay. - Payment Method Surcharge';

// Text
$_['text_extension'] = 'Order Totals';
$_['text_home'] = 'Dashboard';
$_['text_edit'] = 'Edit Pay. - Payment Method Surcharge';
$_['text_success'] = 'Settings saved';
$_['text_configure_note'] = 'Set the amount per payment method on that method\'s own settings page instead (Marketplace > Extensions > Payments). This screen only switches the surcharge line on or off as a whole, and where it appears in the order summary.';

// Entry
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sort Order';

// Help
$_['help_status'] = 'Switch the payment method surcharge on or off as a whole. Even when this is off, individual payment methods may still have their own surcharge amount configured - this only controls whether it actually gets applied.';
$_['help_sort_order'] = 'Where the surcharge line appears relative to the other totals (sub-total, shipping, tax, etc.) in the order summary. Important: this must be a lower number than the "Taxes" total\'s own sort order (5 by default), or VAT on the surcharge won\'t be correctly folded into the tax line. Default: 4.';

// Button
$_['button_save'] = 'Save';
$_['button_back'] = 'Back';

// Error
$_['error_permission'] = 'You do not have permission to modify these settings!';
