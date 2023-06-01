<?php
/**
 * embrapa.io/releaser
 * Script for automatic deploy of Embrapa I/O apps releases in external remote environments.
 *
 * Copyright 2023: Brazilian Agricultural Research Corporation - Embrapa
 *
 * @author Camilo Carromeu <camilo.carromeu@embrapa.br>
 *
 * Bootstrap script.
 */

error_reporting (E_ALL);
set_time_limit (0);
ini_set ('memory_limit', '-1');
ini_set ('register_argc_argv', '1');

if (PHP_SAPI != 'cli')
	die ("CRITICAL > This is a command-line script! You cannot call by browser. \n");

if (!`which git`)
	die ("CRITICAL > GIT package not found!");

if (!(int) ini_get ('register_argc_argv'))
	die ("CRITICAL > This is a command-line script! You must enable 'register_argc_argv' directive. \n");

$vars = [
	'GITLAB_URL',
	'GITLAB_SSH',
	'SMTP_HOST',
	'SMTP_PORT',
	'SMTP_SECURE',
	'LOG_MAIL'
];

$unsetted = [];

foreach ($vars as $trash => $var)
	if (getenv ($var) === FALSE)
		$unsetted [] = $var;

if (sizeof ($unsetted))
	die ("CRITICAL > Required environment variables are not setted: ". implode (', ', $unsetted) ."! \n");

$_verbose = (bool) getenv ('VERBOSE');

require_once 'vendor/autoload.php';

require 'helper/error.php';

require 'class/Mail.php';

$_path = getcwd ();

set_error_handler ('handleError');

try
{
	if (!$_verbose) ob_start ();

	$_benchmark = time ();

	echo "INFO > Starting execution... \n\n";

	$_nothing = TRUE;

	/** CODE INIT **/

	echo "INFO > Sending e-mail... \n";

	(new Mail)->send ('pasto-certo/pwa@release', 'Hello World!', [ 'alice@carromeu.com', 'matheus@carromeu.com', 'melissa@carromeu.com' ]);

	echo "INFO > e-Mail sended! \n";

	/** CODE END **/

	echo "FINISH > All done after ". number_format (time () - $_benchmark, 0, ',', '.') ." seconds!";

	// if (!$_verbose && !$_nothing) Log::singleton ()->getInfoLogger ()->info (ob_get_clean ());

	exit (0);
}
catch (Exception $e)
{
	echo "\n";

	echo "CRITICAL > ". $e->getMessage () ."\n\n";
}

try
{
	echo "FINISH > Stopped after ". number_format (time () - $_benchmark, 0, ',', '.') ." seconds!";

	// if (!$_verbose) Log::singleton ()->getErrorLogger ()->critical (ob_get_clean ());
}
catch (Exception $e)
{
}
