<?php
// Heading
$_['heading_title'] = 'Pay. - Betaalmethode toeslag';

// Text
$_['text_extension'] = 'Bestelling totalen';
$_['text_home'] = 'Dashboard';
$_['text_edit'] = 'Pay. - Betaalmethode toeslag bewerken';
$_['text_success'] = 'Instellingen opgeslagen';
$_['text_configure_note'] = 'Het bedrag per betaalmethode stelt u in op de instellingenpagina van die betaalmethode zelf (Marketplace > Extensies > Betalingen). Hier schakelt u alleen de toeslagregel als geheel in of uit, en bepaalt u waar deze in het besteloverzicht verschijnt.';

// Entry
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sorteervolgorde';

// Help
$_['help_status'] = 'Schakel de betaalmethode toeslag als geheel in of uit. Ook wanneer dit uit staat, kunnen individuele betaalmethoden nog steeds hun eigen toeslagbedrag hebben ingesteld - deze regel bepaalt alleen of die daadwerkelijk wordt toegepast.';
$_['help_sort_order'] = 'Bepaalt waar de toeslagregel verschijnt ten opzichte van de andere regels (subtotaal, verzendkosten, btw, etc.) in het besteloverzicht. Belangrijk: deze waarde moet lager zijn dan de sorteervolgorde van "Taxes" (standaard 5), anders wordt de btw over de toeslag niet correct meegenomen in de btw-regel. Standaard: 4.';

// Button
$_['button_save'] = 'Opslaan';
$_['button_back'] = 'Terug';

// Error
$_['error_permission'] = 'U heeft geen rechten om deze instellingen te wijzigen!';
