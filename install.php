<?php
/**
 * Pay. (pay.nl) OpenCart 4 extension - standalone installer.
 *
 * Run this ONCE, from the command line, after copying/cloning this
 * "paynl" folder into your OpenCart 4 store's extension/ directory
 * (i.e. so it lives at <your-store>/extension/paynl/), like this:
 *
 *     php extension/paynl/install.php
 *
 * What this does, and why it's needed
 * ------------------------------------
 * OpenCart 4's Marketplace > Extensions > Installer screen (the
 * "upload a .ocmod.zip" flow) automatically registers a new extension
 * folder in the oc_extension_install table when you upload a
 * correctly-named/structured zip through it. That's the only thing
 * that makes OC4 re-register this extension's autoloader/template/
 * language paths on every future page request (via
 * beheer/controller/startup/extension.php and
 * catalog/controller/startup/extension.php, both of which loop over
 * oc_extension_install specifically - confirmed by reading both files
 * directly).
 *
 * If you just copy this folder into place directly (git clone,
 * download+extract, rsync, etc. - the common way to install something
 * from GitHub) rather than going through that upload flow, that
 * registration step never happens, and every paynl_* payment method's
 * admin/catalog pages will 404 or silently fail to load on any request
 * after the very first one. This script performs that exact same
 * registration directly, using the same two calls the real installer
 * makes (addInstall() then editStatus(true)), so the end result is
 * identical either way.
 *
 * This script does NOT install any individual payment method (iDEAL,
 * PayPal, etc.) - that part happens normally, afterward, through the
 * admin UI: Marketplace > Extensions > Payments > click Install next
 * to whichever methods you want to accept.
 *
 * Requirements
 * ------------
 * - PHP 8.1 or later (matches the bundled paynl/php-sdk's own
 *   requirement)
 * - Run from a machine/container that can reach your store's database
 *   directly (this script reads the DB credentials straight out of
 *   your store's own root config.php - no credentials to re-enter)
 * - This folder must already be at <store-root>/extension/paynl/
 *   before running this script
 */

// Locate the store's own root config.php (two levels up from
// extension/paynl/), which already has DB_HOSTNAME/DB_USERNAME/etc.
// defined as constants - reuse those rather than asking for
// credentials again.
$store_root = dirname(__DIR__, 2) . '/';
$config_file = $store_root . 'config.php';

if (php_sapi_name() !== 'cli') {
	die("This script is meant to be run from the command line (php extension/paynl/install.php), not through a web browser.\n");
}

if (!is_file($config_file)) {
	die("Could not find {$config_file}\n\nThis script expects to live at <your-opencart-store>/extension/paynl/install.php - if this folder has been placed somewhere else, move it there first (or copy just this install.php file's logic into your own setup).\n");
}

require_once $config_file;

foreach (['DB_HOSTNAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'DB_PORT', 'DB_PREFIX'] as $required_constant) {
	if (!defined($required_constant)) {
		die("{$config_file} doesn't define {$required_constant} - is this really an OpenCart 4 store's config.php?\n");
	}
}

$mysqli = @mysqli_connect(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);

if (!$mysqli) {
	die('Could not connect to the database: ' . mysqli_connect_error() . "\n");
}

$table = DB_PREFIX . 'extension_install';

// Already registered? Nothing to do - safe to run this script more
// than once.
$existing = mysqli_query($mysqli, "SELECT extension_install_id, status FROM `{$table}` WHERE `code` = 'paynl'");

if ($existing && mysqli_num_rows($existing) > 0) {
	$row = mysqli_fetch_assoc($existing);

	if ((int)$row['status'] === 1) {
		echo "Already installed and active (extension_install_id={$row['extension_install_id']}). Nothing to do.\n";
		echo "Next step: go to Marketplace > Extensions > Payments in your admin panel and install the payment methods you want to offer.\n";
		exit(0);
	}

	// Registered but not active (e.g. left over from a previous
	// partial attempt) - just flip status on, matching what the real
	// installer's own install() action does as its final step.
	mysqli_query($mysqli, "UPDATE `{$table}` SET `status` = 1 WHERE `extension_install_id` = " . (int)$row['extension_install_id']);
	echo "Found an existing, inactive registration and activated it (extension_install_id={$row['extension_install_id']}).\n";
	echo "Next step: go to Marketplace > Extensions > Payments in your admin panel and install the payment methods you want to offer.\n";
	exit(0);
}

$install_json_path = __DIR__ . '/install.json';

if (!is_file($install_json_path)) {
	die("Could not find install.json next to this script - the paynl/ folder looks incomplete.\n");
}

$install_json = json_decode((string)file_get_contents($install_json_path), true);

if (!$install_json) {
	die("install.json exists but isn't valid JSON.\n");
}

$name = mysqli_real_escape_string($mysqli, (string)($install_json['name'] ?? 'Pay. Payment Methods'));
$description = mysqli_real_escape_string($mysqli, (string)($install_json['description'] ?? ''));
$version = mysqli_real_escape_string($mysqli, (string)($install_json['version'] ?? '1.0.0'));
$author = mysqli_real_escape_string($mysqli, (string)($install_json['author'] ?? ''));
$link = mysqli_real_escape_string($mysqli, (string)($install_json['link'] ?? ''));

// Matches Opencart\Admin\Model\Setting\Extension::addInstall() exactly
// (confirmed by reading beheer/model/setting/extension.php directly) -
// status always starts at 0 there too, flipped on in a second step.
$insert_sql = "INSERT INTO `{$table}` (extension_id, extension_download_id, name, description, code, version, author, link, status, date_added) "
	. "VALUES (0, 0, '{$name}', '{$description}', 'paynl', '{$version}', '{$author}', '{$link}', 0, NOW())";

if (!mysqli_query($mysqli, $insert_sql)) {
	die('Insert failed: ' . mysqli_error($mysqli) . "\n");
}

$extension_install_id = mysqli_insert_id($mysqli);

mysqli_query($mysqli, "UPDATE `{$table}` SET `status` = 1 WHERE `extension_install_id` = " . (int)$extension_install_id);

echo "Registered and activated (extension_install_id={$extension_install_id}).\n\n";
echo "Next step: log into your admin panel, go to Marketplace > Extensions,\n";
echo "filter by \"Payments\", and click Install next to each Pay. payment\n";
echo "method you want to offer (e.g. Pay. - iDEAL). Then open that method's\n";
echo "settings page and enter your Pay. Token Code / API token / Sales\n";
echo "location (Service ID) - found at https://admin.pay.nl/company/tokens\n";
echo "and https://admin.pay.nl/programs/programs.\n";

mysqli_close($mysqli);
