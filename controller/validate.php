<?php

global $_verbose, $_nothing;

$git = GitLab::singleton ();
$broker = Broker::singleton ();

$builds = [ 'alpha', 'beta', 'release' ];

$builds = $broker->getBuildsStatus ();

echo "INFO > Checking status of all ". sizeof ($builds) ." builds in platform... \n\n";

foreach ($builds as $_build => $status)
{
	if ($status !== 'VALIDATING') continue;

	$_nothing = FALSE;

	if (!$_verbose) ob_start ();

	echo "INFO > Starting VALIDATE proccess to build '". $_build ."'... \n";

	$aux1 = explode ('/', $_build);
	$aux2 = explode ('@', $aux1 [1]);

	$_b->project = $aux1 [0];
	$_b->app = $aux2 [0];
	$_b->stage = $aux2 [1];

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name! \n\n";

		$broker->setBuildStatus ($_build, 'INVALID');

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, [], 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

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
		echo "ERROR > Project '". $_b->project ."' not created yet! A new attempt will be made. \n\n";

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, [], 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	$projectId = $project ['id'];

	try
	{
		$team = $git->projectMembers ($projectId);
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossible to get project team! ". $e->getMessage () ." \n";

		$team = [];
	}

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
		echo "ERROR > Repository '". $_b->project .'/'. $_b->app ."' not created yet! A new attempt will be made. \n\n";

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	$repos = $load [0];

	try
	{
		$git->reposUnarchive ($repos ['id']);
	}
	catch (Exception $e)
	{}

	$_attrs = $broker->getBuild ($_b->project, $_b->app, $_b->stage);

	try
	{
		echo "INFO > Trying to load '.embrapa/settings.json' from remote repository... ";

		$_settings = json_decode ($git->getFile ($repos ['id'], '.embrapa/settings.json'));

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Impossibe to get repository settings ('.embrapa/settings.json'). ". $e->getMessage () ."! A new attempt will be made. \n\n";

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	$cluster = null;

	foreach (self::singleton ()->clusters->$_b->stage as $trash => $c)
	{
		if ($_attrs->cluster != $c->host) continue;

		$cluster = $c;

		break;
	}

	if (!$cluster)
	{
		echo "ERROR > Cluster '". $_attrs->cluster ."' is not valid! See registered clusters in '". getenv ('GITLAB_URL') ."/io/boilerplate/metadata' at file 'clusters.json'. \n\n";

		$broker->setBuildStatus ($_build, 'INVALID');

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	if (!Orchestrator::exists ($cluster->orchestrator))
	{
		echo "ERROR > Orchestrator '". $cluster->orchestrator ."' is not valid! Fix orchestrator in '". getenv ('GITLAB_URL') ."/io/boilerplate/metadata' at file 'clusters.json'. \n\n";

		$broker->setBuildStatus ($_build, 'INVALID');

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	if (!isset ($_settings->boilerplate) || empty (trim ($_settings->boilerplate)))
	{
		echo "ERROR > Invalid JSON: attribute 'boilerplate' not found or empty. A new attempt will be made! \n\n";

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	$boilerplate = null;

	foreach (self::singleton ()->boilerplates as $trash => $b)
	{
		if ($_settings->boilerplate != $b->unix) continue;

		$boilerplate = $b;

		break;
	}

	if (!$boilerplate)
	{
		echo "ERROR > Boilerplate '". $_settings->boilerplate ."' not valid! See registered boilerplates in '". getenv ('GITLAB_URL') ."/io/boilerplate/metadata' at file 'boilerplates.json'. \n\n";

		$broker->setBuildStatus ($_build, 'INVALID');

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	if (!isset ($_settings->orchestrators) || !is_array ($_settings->orchestrators) || !in_array ($cluster->orchestrator, $_settings->orchestrators))
	{
		echo "ERROR > The cluster's orchestrator '". $cluster->orchestrator ."' is not homologated to this app. See your '.embrapa/settings.json' to more info. \n\n";

		$broker->setBuildStatus ($_build, 'INVALID');

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	echo "INFO > Definitions to cluster, boilerplate and type checked and it's ok! \n";

	echo "INFO > Checking volumes... ";

	try
	{
		foreach ($_attrs->volumes as $volume => $status)
			if (!preg_match ('/^[a-z0-9]+$/', $volume))
				throw new Exception ('Invalid volume name: '. $volume);

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Invalid volume: ". $e->getMessage () .". \n\n";

		$broker->setBuildStatus ($_build, 'INVALID');

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	echo "INFO > Checking environment variables... ";

	$_ports = [];

	try
	{
		$prefix = implode ('_', [$_b->project, $_b->app, $_b->stage]) .'_';

		foreach ($_attrs->variables as $name => $data)
		{
			switch ($data['type'])
			{
				case 'PORT':
					if (!array_key_exists ('value', $data) || empty ($data['value']) || !is_numeric ($data['value']) || (int) $data['value'] < 49152 || (int) $data['value'] > 65535)
						throw new Exception ("PORT named '". $name ."' is empty or has a invalid value (". @$data['value'] .")");

					$_ports [] = (int) $data['value'];

					break;

				case 'VOLUME':
					if (!array_key_exists ('value', $data) || empty ($data['value']) || !str_starts_with($data['value'], $prefix) || strlen ($prefix) + 1 >= strlen ($data['value']))
						throw new Exception ("VOLUME named '". $name ."' is empty or has a invalid value (". @$data['value'] .")");

					break;
			}
		}

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Invalid variable: ". $e->getMessage () .". \n\n";

		$broker->setBuildStatus ($_build, 'INVALID');

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	$type = null;

	foreach (self::singleton ()->types as $trash => $t)
	{
		if ($cluster->orchestrator != $t->type) continue;

		$type = $t;

		break;
	}

	if (!$type)
	{
		echo "ERROR > Fail to load type '". $cluster->orchestrator ."'! See registered types in '". getenv ('GITLAB_URL') ."/io/boilerplate/metadata' at file 'orchestrators.json'. A new attempt will be made. \n\n";

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team)->critical (ob_get_flush ());

		continue;
	}

	echo "INFO > App will be validate using orchestrator '". $cluster->orchestrator ."' at server '". $cluster->host ."'! \n";

	try
	{
		$dsn = Sentry::singleton ()->getDSN ($_b->project, $_b->app);
	}
	catch (Exception $e)
	{
		echo "ERROR > Fail to get a valid Sentry DSN to application! Please, check 'https://". getenv ('SENTRY_HOST') ."/organizations/". $_b->project ."/projects/". $_b->app ."/'! ". $e->getMessage () ." \n\n";

		$broker->setBuildStatus ($_build, 'INVALID');

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team)->critical (ob_get_flush ());

		continue;
	}

	try
	{
		$matomo = Matomo::singleton ()->getOrCreateSite ($_b->project, $_b->app);
	}
	catch (Exception $e)
	{
		echo "ERROR > Fail to get a valid Matomo's site ID to application! Please, check '". getenv ('MATOMO_URL') ."'. ". $e->getMessage () .". \n\n";

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team)->critical (ob_get_flush ());

		continue;
	}

	$replace = [
		'%SERVER%' => $_attrs->cluster,
		'%STAGE%' => $_b->stage,
		'%PROJECT_UNIX%'=> $_b->project,
		'%APP_UNIX%' => $_b->app,
		'%VERSION%' => '2.'. date ('y') .'.'. date ('n') .'-'. ($_b->stage !== 'release' ? $_b->stage .'.' : '') .'7',
		'%DEPLOYER%' => 'user.name@embrapa.br',
		'%SENTRY_DSN%' => $dsn,
		'%MATOMO_ID%' => $matomo,
		'%MATOMO_TOKEN%' => md5 ($_build)
	];

	$ci = '';
	$bk = '';

	foreach ($type->variables as $name => $value)
	{
		$line = $name .'='. str_replace (array_keys ($replace), array_values ($replace), $value) ."\n";

		$bk .= $name != 'COMPOSE_PROFILES' ? $line : "COMPOSE_PROFILES=cli\n";
		$ci .= $line;
	}

	$env = '';

	foreach ($_attrs->variables as $name => $data)
		$env .= $name .'='. $data['value'] ."\n";

	if (strpos ($env, ' ') !== false)
	{
		echo "ERROR > Environment variables can not contain spaces! \n\n";

		$broker->setBuildStatus ($_build, 'INVALID');

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	echo "INFO > Checking aliases... \n";

	try
	{
		foreach ($_attrs->aliases as $port => $alias)
		{
			if (trim ($alias) == '') continue;

			if (!filter_var (gethostbyname ($_attrs->aliases [$port]), FILTER_VALIDATE_IP))
				throw new Exception ($alias);

			echo "INFO > Alias '". $alias ."' is valid! \n";
		}
	}
	catch (Exception $e)
	{
		echo "ERROR > Invalid alias: '". $e->getMessage () ."'. Please, fix in domain DNS or remove in build configuration. \n\n";

		$broker->setBuildStatus ($_build, 'INVALID');

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	echo "INFO > Trying to clone app... ";

	unset ($_path);

	try
	{
		$_path = GitClient::singleton ()->cloneBranch ($_b->project, $_b->app, $_b->stage, $env, $ci, $bk);

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Impossibe to clone repository. ". $e->getMessage () ."! A new attempt will be made. \n\n";

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	try
	{
		$isValid = ($cluster->orchestrator)::validate ($_path, $cluster, $_ports);
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossibe to validate repository. ". $e->getMessage () ."! A new attempt will be made. \n\n";

		if (!$_verbose) (new Mail)->getErrorLogger ($_build, $team, 'VALIDATE')->critical (ob_get_flush ());

		continue;
	}

	try
	{
		GitClient::singleton ()->delete ($_path);
	}
	catch (Exception $e)
	{}

	$broker->setBuildStatus ($_build, $isValid ? 'VALID' : 'INVALID');

	if ($isValid)
		echo "SUCCESS > All done and build '". $_b->stage ."' to application '". $_b->project ."/". $_b->app ."' is VALID! \n";
	else
		echo "WARNING > All done, but build '". $_b->stage ."' to application '". $_b->project ."/". $_b->app ."' is INVALID! \n";

	if (!$_verbose)
	{
		if ($isValid) (new Mail)->getInfoLogger ($_build, $team, 'VALIDATE')->info (ob_get_flush ());
		else (new Mail)->getWarningLogger ($_build, $team, 'VALIDATE')->warning (ob_get_flush ());
	}
}
