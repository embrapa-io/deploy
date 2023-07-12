<?php

$git = GitLab::singleton ();

echo "INFO > Checking status of all ". sizeof ($_builds) ." builds configured... \n";

foreach ($_builds as $_build => $_b)
{
	echo "\n";

	echo "=== ". $_build ." === \n\n";

	echo "INFO > Checking if build '". $_build ."' is correctly configured... \n";

	$_settings = $_data . DIRECTORY_SEPARATOR .'settings'. DIRECTORY_SEPARATOR . implode ('_', [$_b->project, $_b->app, $_b->stage]);

	if (!file_exists ($_settings) || !is_dir ($_settings) || !file_exists ($_settings . DIRECTORY_SEPARATOR .'.env'))
	{
		echo "ERROR > Settings folder or environment variables file (.env) does not exists at '". $_settings ."'! \n\n";

		continue;
	}

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name! \n\n";

		continue;
	}

	if (!intval ($_b->matomo->id)) echo "WARNING > Configure a valid Matomo ID! \n";

	// https://da5d463198b1440cbcb1280531b8ac48@bug.embrapa.io/78

	if (!preg_match ('/^https:\/\/[a-f0-9]{32}@bug\.embrapa\.io\/[0-9]+$/', $_b->sentry->dsn))
		echo "WARNING > Configure a valid Sentry DSN! \n";

	echo "INFO > Checking if build '". $_build ."' has new tags... \n";

	try
	{
		$project = $git->projectSearch ($_b->project);
	}
	catch (Exception $e)
	{
		$project = [];
	}

	if (!sizeof ($project))
	{
		echo "ERROR > Project '". $_b->project ."' not found! \n\n";

		continue;
	}

	$projectId = $project ['id'];

	try
	{
		$load = $git->reposSearch ($_b->project .'/'. $_b->app);
	}
	catch (Exception $e)
	{
		$load = [];
	}

	if (!sizeof ($load))
	{
		echo "ERROR > Repository '". $_b->project .'/'. $_b->app ."' not found! \n\n";

		continue;
	}

	$repos = $load [0];

	$replace = [
		'%SERVER%' => getenv ('SERVER'),
		'%STAGE%' => $_b->stage,
		'%PROJECT_UNIX%'=> $_b->project,
		'%APP_UNIX%' => $_b->app,
		'%VERSION%' => '2.'. date ('y') .'.'. date ('n') .'-'. ($_b->stage !== 'release' ? $_b->stage .'.' : '') .'7',
		'%DEPLOYER%' => 'user.name@embrapa.br',
		'%SENTRY_DSN%' => $_b->sentry->dsn,
		'%MATOMO_ID%' => $_b->matomo->id,
		'%MATOMO_TOKEN%' => md5 ($_build)
	];

	$ci = '';
	$bk = '';

	foreach (self::singleton ()->environment as $name => $value)
	{
		$line = $name .'='. str_replace (array_keys ($replace), array_values ($replace), $value) ."\n";

		$bk .= $name != 'COMPOSE_PROFILES' ? $line : "COMPOSE_PROFILES=cli\n";
		$ci .= $line;
	}

	echo "INFO > CI/DI environment variables: \n\n". $ci ."\n";

	try
	{
		$env = file_get_contents ($_settings . DIRECTORY_SEPARATOR .'.env');
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossibe to load environment variables file: '". $_settings . DIRECTORY_SEPARATOR .".env'! \n\n";

		continue;
	}

	if (strpos ($env, ' ') !== false)
	{
		echo "ERROR > Environment variables can not contain spaces! Check file '". $_settings . DIRECTORY_SEPARATOR .'.env' ."'. \n\n";

		continue;
	}

	echo "INFO > Trying to clone app... ";

	unset ($clone);

	try
	{
		$clone = GitClient::singleton ()->cloneBranch ($_b->project, $_b->app, $_b->stage, $ci, $bk);

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Impossibe to clone repository. ". $e->getMessage () ."! \n\n";

		continue;
	}

	GitClient::singleton ()->copy ($_settings, $clone);

	try
	{
		$isValid = (self::singleton ()->orchestrator)::validate ($clone, implode ('_', [$_b->project, $_b->app, $_b->stage]));
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossibe to validate repository. ". $e->getMessage () ."! \n\n";

		continue;
	}

	try
	{
		GitClient::singleton ()->delete ($clone);
	}
	catch (Exception $e)
	{}

	if ($isValid)
		echo "SUCCESS > All done and build '". $_b->stage ."' to application '". $_b->project ."/". $_b->app ."' is VALID! \n";
	else
		echo "WARNING > All done, but build '". $_b->stage ."' to application '". $_b->project ."/". $_b->app ."' is INVALID! \n";
}
