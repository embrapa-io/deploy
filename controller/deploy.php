<?php

global $_verbose, $_nothing, $_builds, $_path;

$_data = $_path . DIRECTORY_SEPARATOR .'data';

if (!file_exists ($_data) || !is_dir ($_data))
	throw new Exception ('Volume for data storage is not mounted!');

$git = GitLab::singleton ();

echo "INFO > Checking status of all ". sizeof ($_builds) ." builds configured... \n\n";

foreach ($_builds as $_build => $_b)
{
	if (!$_b->active) continue;

	echo "INFO > Checking if build '". $_build ."' is correctly configured... \n";

	$_settings = $_path . DIRECTORY_SEPARATOR .'apps'. DIRECTORY_SEPARATOR . implode ('_', [$_b->project, $_b->app, $_b->stage]);

	if (!file_exists ($_settings) || !is_dir ($_settings) || !file_exists ($_settings . DIRECTORY_SEPARATOR .'.env'))
	{
		echo "ERROR > Settings folder or environment variables file (.env) does not exists at '". $_settings ."'! \n\n";

		$_nothing = FALSE;

		continue;
	}

	echo "INFO > Checking if build '". $_build ."' has new tags... \n";

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name! \n\n";

		$_nothing = FALSE;

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
		echo "ERROR > Project '". $_b->project ."' not found! \n\n";

		$_nothing = FALSE;

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

		$_nothing = FALSE;

		continue;
	}

	$repos = $load [0];

	try
	{
		$tags = $git->reposTags ($repos ['id']);
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossible to get repository tags! ". $e->getMessage () ."! \n\n";

		$_nothing = FALSE;

		continue;
	}

	$_tags = [];

	$_newer = [ 'score' => 0 ];

	foreach ($tags as $trash => $tag)
		if (preg_match (self::VERSION_REGEX [$_b->stage], $tag ['name']))
		{
			$tag ['score'] = self::score ($_b->stage, $tag ['name']);

			if ($tag ['score'] > $_newer ['score'])
				$_newer = $tag;

			$_tags [] = $tag;
		}

	if (!sizeof ($_tags) || !$_newer ['score'])
	{
		echo "INFO > No tags found! \n\n";

		continue;
	}

	$version = $_data . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'VERSION'. DIRECTORY_SEPARATOR . $_b->stage;

	try
	{
		$_last = file_get_contents ($version);
	}
	catch (Exception $e)
	{
		$aux = dirname ($version);

		if (!file_exists ($aux) || !is_dir ($aux)) mkdir ($aux, 0777, TRUE);

		$_last = NULL;
	}

	if (is_string ($_last) && trim ($_last) !== '' && self::score ($_b->stage, $_last) >= $_newer ['score'])
	{
		echo "INFO > No new tags found! \n\n";

		continue;
	}

	$_nothing = FALSE;

	if (!$_verbose) ob_start ();

	echo "INFO > New tag found to build '". $_build ."'! \n";

	if ($_last)
		echo "INFO > Updating from ". $_last ." to ". $_newer ['name'];
	else
		echo "INFO > Deploying it's first version ". $_newer ['name'];

	echo ", commited by ". $_newer ['commit']['author_name'] ." <". $_newer ['commit']['author_email'] ."> at ". $_newer ['commit']['created_at'] .". \n";

	echo "INFO > Checking if new tag has been created from branch '". $_b->stage ."'... ";

	try
	{
		$refs = $git->commitRefs ($repos ['id'], $_newer ['commit']['id']);
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Impossible to get refs to tag ". $_newer ['name'] ." (commit '". $_newer ['commit']['id'] ."')! ". $e->getMessage () ."! A new attempt will be made. \n\n";

		if (!$_verbose) Mail::singleton ()->send ($_build .' - RELEASE ERROR', ob_get_flush (), $_b->team);

		continue;
	}

	$checkBranch = FALSE;

	foreach ($refs as $trash => $ref)
	{
		if ($ref ['type'] != 'branch' || $ref ['name'] != $_b->stage) continue;

		$checkBranch = TRUE;

		break;
	}

	if (!$checkBranch)
	{
		echo "error! \n";

		echo "ERROR > New tag ". $_newer ['name'] ." has been not created from branch '". $_b->stage ."'! Please, delete this tag and re-create from correct branch. A new attempt will be made. \n\n";

		if (!$_verbose) Mail::singleton ()->send ($_build .' - RELEASE ERROR', ob_get_flush (), $_b->team);

		continue;
	}

	echo "ok! \n";

	try
	{
		$milestones = $git->projectMilestones ($projectId);
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossible to get milestones! ". $e->getMessage () ."! A new attempt will be made. \n\n";

		if (!$_verbose) Mail::singleton ()->send ($_build .' - RELEASE ERROR', ob_get_flush (), $_b->team);

		continue;
	}

	$milestone = explode ('-', $_newer ['name'])[0];

	$checkMilestone = FALSE;

	foreach ($milestones as $trash => $m)
	{
		if ($m ['title'] != $milestone) continue;

		$checkMilestone = TRUE;

		break;
	}

	if (!$checkMilestone)
		echo "WARNING > No related milestone found! Has good practice, a milestone named '". $milestone ."' is required to manage issues releated to this release. \n";

	try
	{
		echo "INFO > Trying to load '.embrapa/settings.json' from remote repository... ";

		$_embrapa = json_decode ($git->getFile ($repos ['id'], '.embrapa/settings.json'));

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Impossibe to get repository settings ('.embrapa/settings.json'). ". $e->getMessage () ."! A new attempt will be made. \n\n";

		if (!$_verbose) Mail::singleton ()->send ($_build .' - RELEASE ERROR', ob_get_flush (), $_b->team);

		continue;
	}

	echo "INFO > New version will be deployed using orchestrator '". self::singleton ()->orchestrator ."' at server '". getenv ('SERVER') ."'! \n";

	$replace = [
		'%SERVER%' => getenv ('SERVER'),
		'%STAGE%' => $_b->stage,
		'%PROJECT_UNIX%'=> $_b->project,
		'%APP_UNIX%' => $_b->app,
		'%VERSION%' => $_newer ['name'],
		'%DEPLOYER%' => $_newer ['commit']['author_email'],
		'%SENTRY_DSN%' => $_b->sentry->dsn,
		'%MATOMO_ID%' => $_b->matomo->id,
		'%MATOMO_TOKEN%' => $_b->matomo->token
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
		echo "ERROR > Impossibe to load environment variables file: '". $_settings . DIRECTORY_SEPARATOR .".env'! A new attempt will be made. \n\n";

		if (!$_verbose) Mail::singleton ()->send ($_build .' - RELEASE ERROR', ob_get_flush (), $_b->team);

		continue;
	}

	echo "INFO > Trying to clone app... ";

	unset ($clone);

	try
	{
		$clone = GitClient::singleton ()->cloneTag ($_b->project, $_b->app, $_b->stage, $_newer ['name'], $env, $ci, $bk, $_path);

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Impossibe to clone repository. ". $e->getMessage () ."! A new attempt will be made. \n\n";

		if (!$_verbose) Mail::singleton ()->send ($_build .' - RELEASE ERROR', ob_get_flush (), $_b->team);

		continue;
	}

	try
	{
		(self::singleton ()->orchestrator)::deploy ($clone, implode ('_', [$_b->project, $_b->app, $_b->stage]));
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossibe to deploy tag ". $_newer ['name'] .". ". $e->getMessage () ."! A new attempt will be made. \n\n";

		if (!$_verbose) Mail::singleton ()->send ($_build .' - RELEASE ERROR', ob_get_flush (), $_b->team);

		continue;
	}

	file_put_contents ($version, $_newer ['name'], LOCK_EX);

	echo "SUCCESS > All done! Version '". $_newer ['name'] ."' in '". $_b->stage ."' stage of application '". $_b->project ."/". $_b->app ."' is DEPLOYED! \n";

	if (!$_verbose) Mail::singleton ()->send ($_build .' '. $_newer ['name'] .' - RELEASE SUCCESS', ob_get_flush (), $_b->team);

	echo "\n";
}
