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

$_operations = [
	'validate' 	=> [ 'Validate builds (i.e., project app stages like alpha, beta or release)', 'Controller::validate' ],
	'deploy' 	=> [ 'Re-validate, prepare (i.e., create network) and deploy builds', 'Controller::deploy' ],
	'stop' 		=> [ 'Stop running containers', 'Controller::stop' ],
	'restart' 	=> [ 'Restart running containers', 'Controller::restart' ],
	'backup' 	=> [ 'Generate builds backup', 'Controller::backup' ],
	'cleanup' 	=> [ 'Delete backups older than one week', 'Controller::cleanup' ],
	'sanitize'	=> [ 'Run periodically sanitize proccess', 'Controller::sanitize' ]
];

try
{
	if ($argc != 3) throw new Exception ();

	$aux = explode (':', $argv [1]);

	if (!array_key_exists (trim ($aux [0]), $_operations)) throw new Exception ();

	$_operation = trim ($aux [0]);

	$_daemon = sizeof ($aux) == 2 && trim ($aux [1]) == 'daemon' && in_array ($aux [0], [ 'deploy', 'backup', 'sanitize' ]) ? TRUE : FALSE;
}
catch (Exception $e)
{
	echo "\n";

	echo "Usage: php run.php [OPERATION] [BUILDS] \n\n";

	echo "Operations: \n";

	foreach ($_operations as $op => $array)
		echo "  ". str_pad ($op, 12) . $array [0] ."\n";

	echo "\n";

	echo "Builds: \n";

	echo "Needs to be a list, comma sepparated, where each build is in format 'project/app@stage'. \n\n";

	echo "Example: php run.php backup project-a/app1@beta,project-b/web@release,project-a/app2@alpha \n";

	exit;
}

$vars = [
	'SERVER',
	'ORCHESTRATOR',
	'GITLAB_URL',
	'GITLAB_SSH',
	'GITLAB_TOKEN',
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

$_path = dirname (__FILE__);

$_data = $_path . DIRECTORY_SEPARATOR .'data';

if (!file_exists ($_data) || !is_dir ($_data))
	die ("CRITICAL > Volume for data storage is not mounted! \n");

$lifetimes = [
	'deploy' => intval (getenv ('LOCK_LIFETIME_MINUTES')),
	'backup' => 7 * 24 * 60,
	'sanitize' => 15 * 24 * 60
];

$_lock = $_data . DIRECTORY_SEPARATOR .'.'. $_operation;

if ($_daemon && file_exists ($_lock) && (!is_writable ($_lock) || time () - filemtime ($_lock) < $lifetimes [$_operation] * 60))
	die ("CRITICAL > The operation '". $_operation ."' is already being performed by a process started earlier! \n");

@unlink ($_lock);

require_once 'vendor/autoload.php';

require_once 'helper/error.php';

require_once 'class/Log.php';
require_once 'class/Mail.php';
require_once 'class/GitLab.php';
require_once 'class/GitClient.php';
require_once 'class/Controller.php';

require_once 'plugin/Orchestrator.php';
require_once 'plugin/DockerCompose.php';
require_once 'plugin/DockerSwarm.php';

if (!Orchestrator::exists (getenv ('ORCHESTRATOR')))
	die ("CRITICAL > Orchestrator '". getenv ('ORCHESTRATOR') ."' defined in '.env' is not valid! \n");

$builds = $_path . DIRECTORY_SEPARATOR .'apps'. DIRECTORY_SEPARATOR .'builds.json';

if (!file_exists ($builds) || !is_readable ($builds))
	die ("CRITICAL > Is needed configure builds of apps to deploy in file '". $builds ."'! \n");

$_builds = [];

$loaded = json_decode (file_get_contents ($builds));

$slice = explode (',', $argv [2]);

$all = sizeof ($slice) == 1 && trim ($slice [0]) == '--all' ? TRUE : FALSE;

foreach ($loaded as $trash => $b)
{
	$build  = $b->project .'/'. $b->app .'@'. $b->stage;

	if ($all || in_array ($build, $slice))
	{
		if (array_key_exists ($build, $_builds))
			die ("CRITICAL > The build '". $build ."' was configured twice! \n");

		$_builds [$build] = $b;
	}
}

if (!sizeof ($_builds))
	die ("CRITICAL > No builds to deploy! Check settings file (apps/builds.json). \n");

set_error_handler ('handleError');

try
{
	if ($_daemon) ob_start ();

	$_benchmark = time ();

	if ($_daemon) file_put_contents ($_lock, '');

	echo "INFO > Starting execution... \n";

	$_nothing = TRUE;

	$function = $_operations [$_operation][1];

	call_user_func_array ($function, []);

	try { @unlink ($_lock); } catch (Exception $e) {}

	echo "\n";

	echo "FINISH > All done after ". number_format (time () - $_benchmark, 0, ',', '.') ." seconds!";

	if ($_daemon && !$_nothing) Mail::singleton ()->send ('SUCCESS EXECUTION of Releaser Script', ob_get_clean ());

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

	if ($_daemon) Mail::singleton ()->send ('CRITICAL ERROR of Releaser Script', ob_get_clean ());
}
catch (Exception $e)
{}
