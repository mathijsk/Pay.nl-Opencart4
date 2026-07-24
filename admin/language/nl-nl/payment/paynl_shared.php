<?php
// Pay. shared admin settings field labels/tooltips - included by every
// paynl_<method> admin language file (e.g. payment/paynl_ideal.php).
// Ported from the OC3 plugin's shared admin/language/*/extension/payment/paynl3.php,
// with the version-check and "send suggestion to Pay.nl" sections
// removed (those phone-home features were deliberately not ported -
// see AdminController's own docblock).

$_['text_apitoken'] = 'API token';
$_['text_serviceid'] = 'Verkooplocatie';
$_['text_tokencode'] = 'Token Code';

$_['text_payment'] = 'Betaling';

$_['button_save'] = 'Opslaan';
$_['button_cancel'] = 'Annuleren';

$_['text_success'] = 'Instellingen opgeslagen';
$_['text_home'] = 'Dashboard';
$_['text_extension'] = 'Pay.';
$_['error_permission'] = 'U heeft geen rechten om deze instellingen te wijzigen!';

$_['error_not_activated'] = "Deze betaalmethode is niet geactiveerd voor deze dienst. Ga naar "
	. "<a target=\"paynl\" href=\"https://admin.pay.nl/programs/programs\">https://admin.pay.nl/programs/programs</a> om dit aan te passen.";
$_['error_no_apitoken'] = 'U moet een apitoken invoeren, u vindt uw apitokens op: '
	. '<a href="https://admin.pay.nl/company/tokens">https://admin.pay.nl/company/tokens</a>';
$_['error_no_serviceid'] = 'U moet een serviceId invoeren, u vindt uw serviceId op: '
	. '<a href="https://admin.pay.nl/programs/programs">https://admin.pay.nl/programs/programs</a>. Een serviceId begint altijd met SL-';
$_['error_wrong_apitoken'] = 'Ongeldige API-token, u kunt uw API-token vinden op: <a href="https://admin.pay.nl/company/tokens">https://admin.pay.nl/company/tokens</a>';
$_['error_wrong_tokencode'] = 'Ongeldige tokencode, een tokencode begint altijd met AT-, u kunt uw tokencode vinden op: <a href="https://admin.pay.nl/company/tokens">https://admin.pay.nl/company/tokens</a>';
$_['error_wrong_serviceid'] = 'Ongeldige verkooplocatie, een verkooplocatie begint altijd met SL-';

$_['text_register'] = 'Nog geen account bij Pay.? Klik ';
$_['text_link_register'] = 'hier';
$_['link_register'] = 'https://www.pay.nl/registreren';
$_['text_after_register'] = ' om u aan te melden.';

$_['text_general_settings'] = 'Pay. Algemene instellingen';
$_['text_method_settings'] = 'Betaalmethode instellingen';

$_['text_confirm_start_tooltip'] = 'De order bevestigen bij het starten van de transactie, dus voordat er betaald is. De bevestigingsmail wordt dan ook meteen verstuurd';
$_['text_confirm_start'] = 'Order bevestigen bij starten transactie';
$_['text_send_statusupdates'] = 'Statusupdates versturen';
$_['text_send_statusupdates_tooltip'] = 'De gebruiker een email sturen als de status van de bestelling verandert';

$_['text_gateway'] = 'Failover gateway';
$_['text_gateway_tooltip'] = 'Voer hier alleen iets in als Pay. u een gateway heeft doorgegeven om hier in te vullen';

$_['text_prefix'] = 'Order omschrijving prefix';
$_['text_prefix_tooltip'] = 'Verander de order omschrijving prefix hier. Als dit leeg is, zal de omschrijving het ordernummer zijn.';

$_['text_advanced_settings'] = 'Geavanceerde instellingen';

$_['text_auto_void'] = 'Auto void';
$_['text_auto_void_tooltip'] = 'Geautoriseerde transacties automatisch vrijgeven (void) bij het annuleren van een bestelling.';

$_['text_auto_capture'] = 'Auto capture';
$_['text_auto_capture_tooltip'] = 'Schakel auto capture in voor gereserveerde transacties met status AUTHORIZE. De capture wordt uitgevoerd wanneer een bestelstatus wijzigt naar Completed.';

$_['text_refund_processing'] = 'Verwerking terugbetaling';
$_['text_refund_processing_tooltip'] = 'Verwerk terugbetalingen die gestart zijn vanuit Pay.';

$_['text_follow_payment_method'] = 'Volg betaalmethode';
$_['text_follow_payment_method_tooltip'] = 'Werkt de bestelling bij met de daadwerkelijke betaalmethode die is gebruikt om de bestelling te voltooien. Dit kan afwijken van de aanvankelijk gekozen betaalmethode.';

$_['text_coc'] = 'Toon KVK nummer veld';
$_['text_coc_tooltip'] = 'Wanneer dit aan staat zal de klant een optie hebben om hun KVK nummer in te voeren voordat ze de transactie afmaken';
$_['text_coc_disabled'] = 'Nee';
$_['text_coc_enabled'] = 'Ja, als optioneel veld';
$_['text_coc_required'] = 'Ja, als verplicht veld';

$_['text_vat'] = 'Toon BTW nummer veld voor zakelijke klanten';
$_['text_vat_tooltip'] = 'Wanneer dit aan staat zal de klant een optie hebben om hun BTW nummer in te voeren voordat ze de transactie afmaken';
$_['text_vat_disabled'] = 'Uit';
$_['text_vat_enabled'] = 'Optioneel voor zakelijke klanten';
$_['text_vat_required'] = 'Verplicht voor zakelijke klanten';

$_['text_dob'] = 'Toon geboortedatum veld';
$_['text_dob_tooltip'] = 'Wanneer dit aan staat zal de klant een optie hebben om hun geboortedatum in te voeren voordat ze de transactie afmaken';
$_['text_dob_disabled'] = 'Nee';
$_['text_dob_enabled'] = 'Ja, als optioneel veld';
$_['text_dob_required'] = 'Ja, als verplicht veld';

$_['text_display_icon'] = 'Icoon weergeven';
$_['text_display_icon_tooltip'] = 'Selecteer hier of je een icoon wilt weergeven en welke grootte.';

$_['text_custom_exchange_url'] = 'Alternatieve Exchange URL';
$_['text_custom_exchange_url_tooltip'] = 'Gebruik je eigen exchange-handler. Requests worden verzonden als een GET.<br/>Voorbeeld: https://www.uwdomein.nl/exchange_handler?action=#action#&order_id=#order_id#';

$_['text_current_ip'] = 'IP-adres van huidige gebruiker: ';
$_['text_test_ip'] = 'Test IP Adressen';
$_['text_test_ip_tooltip'] = "Forceer test mode voor de ingevulde IP adressen, scheid IP's met komma's voor meerdere IP's";

$_['text_logging'] = 'Logging';
$_['text_logging_tooltip'] = 'Schakel logging in';

$_['text_testmode'] = 'Test mode';
$_['text_testmode_tooltip'] = 'Zet de test mode aan of uit om de exchanges te testen tussen Pay. en uw webshop';

$_['text_display_fast_checkout'] = 'Toon de Fast Checkout-knop';
$_['text_display_fast_checkout_tooltip'] = 'Schakel de Fast Checkout-knop in of uit in de winkelwagen.';
$_['text_default_shipping_method'] = 'Standaard verzendmethode';
$_['text_only_guest'] = 'Alleen voor gasten';

$_['text_status_pending'] = 'Order status wacht op betaling';
$_['text_status_pending_tooltip'] = 'De status van de order wanneer de betaling is gestart, maar nog niet afgerond';
$_['text_status_complete'] = 'Order status betaling voltooid';
$_['text_status_complete_tooltip'] = 'De status die het order moet krijgen nadat de betaling succesvol is ontvangen';
$_['text_status_canceled'] = 'Order status geannuleerd';
$_['text_status_canceled_tooltip'] = 'De status die het order moet krijgen nadat de betaling is geannuleerd';
$_['text_status_refunded'] = 'Order status terugbetaald';
$_['text_status_refunded_tooltip'] = 'De status die het order moet krijgen nadat de betaling is terugbetaald';
$_['text_minimum_amount'] = 'Minimaal order bedrag';
$_['text_maximum_amount'] = 'Maximaal order bedrag';
$_['text_surcharge'] = 'Toeslag - vast bedrag';
$_['text_surcharge_fixed_tooltip'] = 'Vast bedrag (in €) dat bovenop de bestelling wordt gerekend bij gebruik van deze betaalmethode. Wettelijk mag u hierbij niet meer rekenen dan de daadwerkelijke transactiekosten die Pay. u in rekening brengt.';
$_['text_surcharge_percentage'] = 'Toeslag - percentage';
$_['text_surcharge_percentage_tooltip'] = 'Percentage van het orderbedrag dat als toeslag wordt gerekend bij gebruik van deze betaalmethode (bijv. 1.8 voor 1,8%). Wordt opgeteld bij het vaste bedrag hierboven.';
$_['text_surcharge_not_allowed'] = 'Voor deze betaalmethode is een toeslag wettelijk niet toegestaan (o.a. iDEAL, standaard consumenten betaalkaarten, SEPA-incasso/overboeking, en enkele lokale Europese bankmethoden). Deze velden worden daarom niet getoond.';
$_['text_payment_instructions'] = 'Instructies';
$_['text_payment_instructions_tooltip'] = 'Als u instructies wilt tonen aan de klant, kunt u die hier aangeven';

$_['entry_order_status'] = 'Order Status';
$_['entry_geo_zone'] = 'Geo Zone';
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sorteervolgorde';

$_['text_customer_type'] = 'Toegestaan klanttype';
$_['text_customer_type_tooltip'] = 'Selecteer welk type klant de betaalmethode kan gebruiken.';
$_['text_both'] = 'Beide';
$_['text_private'] = 'Privé';
$_['text_business'] = 'Zakelijk';

$_['text_enabled'] = 'Aan';
$_['text_disabled'] = 'Uit';
$_['text_yes'] = 'Ja';
$_['text_no'] = 'Nee';
