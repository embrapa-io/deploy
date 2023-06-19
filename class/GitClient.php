<?php

class GitClient
{
    const GIT = '/usr/bin/git';

    static private $single = FALSE;

    private final function __construct ()
	{
        exec (self::GIT .' config --global user.email "releaser@embrapa.io"');
        exec (self::GIT .' config --global user.name "Embrapa I/O Releaser"');
    }

    static public function singleton ()
	{
		if (self::$single !== FALSE)
			return self::$single;

		$class = __CLASS__;

		self::$single = new $class ();

		return self::$single;
	}

    static public function delete ($path)
    {
        if (!file_exists ($path)) return;

        $files = new RecursiveIteratorIterator (
            new RecursiveDirectoryIterator ($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $fileinfo)
        {
            $todo = ($fileinfo->isDir () ? 'rmdir' : 'unlink');
            $todo ($fileinfo->getRealPath ());
        }

        rmdir ($path);
    }

    public function cloneBranch ($namespace, $repos, $branch, $env, $ci, $bk)
    {
        $tmp = DIRECTORY_SEPARATOR .'tmp'. DIRECTORY_SEPARATOR .'validate';

        if (!file_exists ($tmp)) mkdir ($tmp);

        chdir ($tmp);

        $clone = $tmp . DIRECTORY_SEPARATOR . $namespace .'_'. $repos .'_'. $branch;

        if (file_exists ($clone)) self::delete ($clone);

        mkdir ($clone);

        exec (self::GIT .' clone --depth 1 --verbose --branch '. $branch .' '. getenv ('GITLAB_SSH') .'/'. $namespace .'/'. $repos .'.git '. $clone .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            self::delete ($clone);

            throw new Exception ('Impossible to clone repository at branch "'. $branch .'"');
        }

        if (!file_put_contents ($clone . DIRECTORY_SEPARATOR .'.env', $env, LOCK_EX) ||
            !file_put_contents ($clone . DIRECTORY_SEPARATOR .'.env.ci', $ci, LOCK_EX) ||
            !file_put_contents ($clone . DIRECTORY_SEPARATOR .'.env.cli', $bk, LOCK_EX))
        {
            self::delete ($clone);

            throw new Exception ('Impossible to write .env, .env.ci and/or .env.cli files');
        }

        return $clone;
    }

    public function cloneTag ($namespace, $repos, $stage, $tag, $env, $ci, $bk, $action = 'deploy')
    {
        $tmp = DIRECTORY_SEPARATOR .'tmp'. DIRECTORY_SEPARATOR . $action;

        if (!file_exists ($tmp)) mkdir ($tmp);

        chdir ($tmp);

        $clone = $tmp . DIRECTORY_SEPARATOR . $namespace .'_'. $repos .'_'. $stage;

        if (file_exists ($clone)) self::delete ($clone);

        mkdir ($clone);

        exec (self::GIT .' clone --depth 1 --verbose --branch '. $tag .' '. getenv ('GITLAB_SSH') .'/'. $namespace .'/'. $repos .'.git '. $clone .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            self::delete ($clone);

            echo "\n". implode ("\n", $output) ."\n";

            throw new Exception ('Impossible to clone repository at tag "'. $tag .'"');
        }

        if (!file_put_contents ($clone . DIRECTORY_SEPARATOR .'.env', $env, LOCK_EX) ||
            !file_put_contents ($clone . DIRECTORY_SEPARATOR .'.env.ci', $ci, LOCK_EX) ||
            !file_put_contents ($clone . DIRECTORY_SEPARATOR .'.env.cli', $bk, LOCK_EX))
        {
            self::delete ($clone);

            throw new Exception ('Impossible to write .env, .env.ci and/or .env.cli files');
        }

        return $clone;
    }

    public function supportReposIsUpdated ($namespace, $repos, $clone)
    {
        if (!file_exists ($clone))
        {
            mkdir ($clone, 0700, TRUE);

            exec (self::GIT .' clone --branch main '. getenv ('GITLAB_SSH') .'/'. $namespace .'/'. $repos .'.git '. $clone .' 2>&1', $output, $return);

            if ($return !== 0)
            {
                self::delete ($clone);

                throw new Exception ('Impossible to clone repository');
            }

            return FALSE;
        }

        chdir ($clone);

        exec (self::GIT .' reset --hard 2>&1', $output1, $return1);

        if ($return1 !== 0)
        {
            self::delete ($clone);

            throw new Exception ('Impossible to reset repository');
        }

        exec (self::GIT .' fetch --dry-run 2>&1', $output2, $return2);

        if ($return2 !== 0)
        {
            self::delete ($clone);

            throw new Exception ('Impossible to fetch repository');
        }

        if (!sizeof ($output2)) return TRUE;

        exec (self::GIT .' fetch 2>&1', $output3, $return3);
        exec (self::GIT .' pull 2>&1', $output4, $return4);

        if ($return3 !== 0 || $return4 !== 0)
        {
            self::delete ($clone);

            throw new Exception ('Impossible to update repository');
        }

        return FALSE;
    }
}