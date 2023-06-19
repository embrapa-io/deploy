<?php

class DockerCompose extends Orchestrator
{
    const DOCKER = '/usr/bin/docker';
    const DOCKER_COMPOSE = '/usr/bin/docker-compose';

    static public function validate ($path, $cluster, $ports)
    {
        self::checkSSHConnection ($cluster->host);

        return self::checkDockerComposeFile ($path, $cluster->host, $ports);
    }

    static public function deploy ($path, $cluster, $ports)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        self::checkSSHConnection ($cluster->host);

        $valid = self::checkDockerComposeFile ($path, $cluster->host, $ports);

        if (!$valid)
            throw new Exception ('Invalid docker-compose.yaml file! Please, check configuration (volumes, ports, enviroment variables, etc)');

        chdir ($path);

        echo "INFO > Trying to execute backup service before deploy...\n";

        echo 'COMMAND > env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' build --force-rm --no-cache backup'."\n";

        exec ('env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' build --force-rm --no-cache backup 2>&1', $output, $return);

        unset ($return);
        unset ($output);

        echo 'COMMAND > env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' run --rm --no-deps backup'."\n";

        exec ('env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' run --rm --no-deps backup 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            echo "WARNING > Backup service failed! \n";
        }
        else
            echo "SUCCESS > Backup service executed successfully! \n";

        unset ($return);
        unset ($output);

        $name = basename ($path);

        echo "INFO > Creating a stack network named as '". $name ."' with Docker... \n";

        echo 'COMMAND > DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER .' network create '. $name ."\n";

        exec ('DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER .' network create '. $name .' 2>&1', $output, $return);

        if ($return !== 0)
            echo implode ("\n", $output) ."\n";

        unset ($return);
        unset ($output);

        echo "INFO > Building application with Docker Compose... \n";

        echo 'COMMAND > set -e && env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' up --force-recreate --build --no-start && exit $?'."\n";

        passthru ('set -e && env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' up --force-recreate --build --no-start && exit $? 2>&1', $return);

        if ($return !== 0)
            throw new Exception ('Error when buildings containers with Docker Compose');

        unset ($return);
        unset ($output);

        echo "INFO > Getting valid services (will ignore: ". implode (", ", self::CLI_SERVICES) .")... \n";

        echo 'COMMAND > env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' config --services'."\n";

        exec ('env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' config --services 2>&1', $services, $return);

        if ($return !== 0)
            throw new Exception ('Error when getting services from docker-compose.yaml');

        foreach ($services as $index => $service)
            if (in_array (trim ($service), self::CLI_SERVICES))
                unset ($services [$index]);

        if (!sizeof ($services))
            throw new Exception ('No valid services found in docker-compose.yaml');

        unset ($return);
        unset ($output);

        echo "INFO > Starting application with Docker Compose... \n";

        echo 'COMMAND > env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' start '. implode (' ', $services) ."\n";

        exec ('env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' start '. implode (' ', $services) .' 2>&1', $output, $return);

        echo implode ("\n", $output) ."\n";

        if ($return !== 0)
            throw new Exception ('Error when starting containers with Docker Compose');
    }

    static public function health ($cluster)
    {
        exec ('type '. self::DOCKER, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker' command");

        unset ($return);

        self::checkSSHConnection ($cluster->host, 10);

        exec ('DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER .' ps -a --format "{{.Names}}|{{.State}}|{{.Status}}|{{.Size}}|{{.CreatedAt}}|{{.RunningFor}}"', $output, $return);

        if ($return !== 0)
            throw new Exception ('Error on Docker command');

        $services = [];

        foreach ($output as $trash => $line)
        {
            $line = explode ('|', $line);

            if (sizeof ($line) !== 6) continue;

            $service = [];

            $pieces = explode ('_', $line[0]);

            if (sizeof ($pieces) < 3) continue;

            if (in_array ($pieces [2], [ 'alpha', 'beta', 'release' ]))
                $name = implode ('_', array_slice ($pieces, 3, sizeof ($pieces) - 4));
            else
            {
                $aux = explode ('-', $pieces [2]);

                $pieces [2] = $aux [0];

                $name = implode ('_', array_slice ($aux, 1, sizeof ($aux) - 2));
            }

            $build = $pieces [0] .'/'. $pieces [1] .'@'. $pieces [2];

            $service['state'] = $line[1];
            $service['status'] = $line[2];

            preg_match('/^[^\(]+\(([^\)]+)\)$/', $line[2], $out);

            if (sizeof ($out) == 2) $service['healthy'] = $out[1];
            else $service['healthy'] = 'undefined';

            preg_match ('/^[^\(]+\(virtual ([^\)]+)\)$/', $line[3], $out);

            if (sizeof ($out) == 2) $service['size'] = $out[1];
            else $service['size'] = $line[3];

            $service['created'] = (new DateTime($line[4]))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('j/n/y G:i');

            $service['running'] = $line[5];

            $services[$build][$name] = $service;
        }

        return $services;
    }

    static public function checkDockerComposeFile ($folder, $host, $ports)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        echo "INFO > Validating Docker Compose file: \n";

        chdir ($folder);

        echo 'COMMAND > env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config'."\n";

        if (!file_exists ('.embrapa') || !is_dir ('.embrapa'))
        {
            echo "ERROR > Apparently the '.embrapa' directory does not exist in this branch! Tip: merge from 'main' into the stage branch (alpha, beta or release). \n";

            return FALSE;
        }

        $out = tempnam ('.embrapa', '_');
        $log = tempnam ('.embrapa', '_');

        exec ('env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config > '. $out .' 2> '. $log, $trash, $return);

        $output = file_exists ($log) && is_readable ($log) ? @file ($log) : [];

        $warning = FALSE;

        if (sizeof ($output))
        {
            $warning = TRUE;

            echo "--- \n";
            echo implode ('', $output);
            echo "--- \n";
        }

        if ($return !== 0)
        {
            echo "ERROR > File docker-compose.yaml is INVALID! Please, check configuration (volumes, ports, enviroment variables, etc). \n";

            return FALSE;
        }

        unset ($return);
        unset ($output);

        echo "INFO > Trying to validate declared VOLUMEs and PORTs... \n";

        if (!file_exists ($out) || !is_readable ($out))
        {
            echo "ERROR > Impossible to load interpolate docker-compose.yaml! \n";

            return FALSE;
        }

        $config = yaml_parse_file ($out);

        if ($config === FALSE || !is_array ($config))
        {
            echo "ERROR > Impossible to load properly interpolate docker-compose.yaml! \n";

            return FALSE;
        }

        $prefix = basename ($folder);

        $volumes = [];

        if (array_key_exists ('volumes', $config) && is_array ($config ['volumes']))
        {
            foreach ($config ['volumes'] as $name => $volume)
            {
                if (!array_key_exists ('external', $volume) || !(bool) $volume ['external'])
                {
                    echo "ERROR > Volume '". $name ."' is NOT 'external'! See https://docs.docker.com/compose/compose-file/#external-1 for more info. \n";

                    return FALSE;
                }

                $aux = explode ('_', $volume ['name']);

                array_pop ($aux);

                if (implode ('_', $aux) != $prefix)
                {
                    echo "ERROR > Volume '". $name ."' is pointing to '". $volume ['name'] ."'! All mounted drivers needed to inside of application context. \n";

                    return FALSE;
                }

                $volumes [] = $name;
            }

            echo "INFO > All external VOLUMEs declared are valid! \n";
        }

        if (!array_key_exists ('networks', $config) || !is_array ($config ['networks']) || sizeof ($config ['networks']) !== 1 ||
            !array_key_exists ('external', $config ['networks'][array_key_first ($config ['networks'])]) || !(bool) $config ['networks'][array_key_first ($config ['networks'])]['external'] ||
            !array_key_exists ('name', $config ['networks'][array_key_first ($config ['networks'])]) || trim ($config ['networks'][array_key_first ($config ['networks'])]['name']) !== $prefix)
        {
            echo "ERROR > Is needed to exists ONE, and only one, external network to entire stack in 'docker-compose.yaml'. Must be named '". $prefix ."'. See https://docs.docker.com/compose/networking/ for more info. \n";

            return FALSE;
        }

        if (!array_key_exists ('services', $config) || !is_array ($config ['services']))
        {
            echo "ERROR > Impossible to load services from config file! \n";

            return FALSE;
        }

        $invalidPorts = [];

        foreach ($config ['services'] as $name => $service)
        {
            if (isset ($service ['ports']))
                foreach ($service ['ports'] as $trash => $port)
                {
                    if (!isset ($port ['published']) || !(int) $port ['published'])
                    {
                        echo "ERROR > Service '". $name ."' trying to expose a randomic port. All ports in services must be explicit! If you are not going to expose this service, remove it by setting a suitable 'profile'. \n";

                        return FALSE;
                    }

                    if (!in_array ((int) $port ['published'], $ports))
                        $invalidPorts [] = $port ['published'];
                }

            if (isset ($service ['volumes']))
                foreach ($service ['volumes'] as $trash => $volume)
                    if (!in_array ($volume ['source'], $volumes))
                    {
                        echo "ERROR > Volume named '". $volume ['source'] ."' used by service '". $name ."' is not declared as external. \n";

                        return FALSE;
                    }
        }

        if (sizeof ($invalidPorts))
        {
            echo "ERROR > Trying to use PORTs that is not defined at application configuration: ". implode (", ", $invalidPorts) ."! \n";

            return FALSE;
        }

        echo "INFO > All published PORTs are valid! \n";

        echo 'COMMAND > env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config --services'."\n";

        exec ('env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config --services 2>&1', $services1, $return);

        echo "INFO > Checking if has CLI services starting in application deployment... ";

        foreach (self::CLI_SERVICES as $index => $service)
            if (in_array ($service, $services1))
            {
                echo "fail! \n";

                echo "ERROR > Services named '". $service ."' cannot go up during deployment! Please, check attribute 'profiles' in configuration. \n";

                echo "\n". implode ("\n", $services1) ."\n\n";

                return FALSE;
            }

        echo "it's ok! \n";

        echo 'COMMAND > env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config --services'."\n";

        exec ('env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config --services 2>&1', $services2, $return);

        $cli = self::CLI_SERVICES;

        foreach ($cli as $index => $service)
            if (in_array ($service, $services2))
                unset ($cli [$index]);

        if (sizeof ($cli))
        {
            echo "WARNING > Some CLI recomended services are NOT FOUND at docker-compose.yaml: ". implode (", ", $cli) ."! Please, check configuration. \n";

            $warning = TRUE;
        }

        if ($warning)
            echo "WARNING > File docker-compose.yaml is VALID, but has some alerts to fix! \n";
        else
            echo "SUCCESS > File docker-compose.yaml is VALID! \n";

        return TRUE;
    }

    static public function undeploy ($path, $cluster)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        self::checkSSHConnection ($cluster->host);

        chdir ($path);

        echo "INFO > Stopping application with Docker Compose... \n";

        echo 'COMMAND > env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' stop'."\n";

        exec ('env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' stop 2>&1', $output, $return);

        if (sizeof ($output)) echo implode ("\n", $output) ."\n";

        if ($return !== 0)
            throw new Exception ('Error when trying to stop containers with Docker Compose');
    }

    static public function restart ($path, $cluster)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        self::checkSSHConnection ($cluster->host);

        chdir ($path);

        echo "INFO > Restarting application with Docker Compose... \n";

        echo 'COMMAND > env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' restart'."\n";

        exec ('env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' restart 2>&1', $output, $return);

        if (sizeof ($output)) echo implode ("\n", $output) ."\n";

        if ($return !== 0)
            throw new Exception ('Error when trying to restart containers with Docker Compose');
    }

    static public function backup ($path, $cluster)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        self::checkSSHConnection ($cluster->host);

        chdir ($path);

        echo "INFO > Trying to execute backup service...\n";

        echo 'COMMAND > env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' build --force-rm --no-cache backup'."\n";

        exec ('env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' build --force-rm --no-cache backup 2>&1', $output1, $return1);

        if ($return1 !== 0)
        {
            echo implode ("\n", $output1) ."\n";

            throw new Exception ("Backup service failed to BUILD");
        }

        echo 'COMMAND > env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' run --rm --no-deps backup'."\n";

        exec ('env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' run --rm --no-deps backup 2>&1', $output2, $return2);

        if ($return2 !== 0)
        {
            echo implode ("\n", $output2) ."\n";

            throw new Exception ("Backup service failed to RUN");
        }
    }

    static public function sanitize ($path, $cluster)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        self::checkSSHConnection ($cluster->host);

        chdir ($path);

        echo "INFO > Trying to execute sanitize service...\n";

        echo 'COMMAND > env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' build --force-rm --no-cache sanitize'."\n";

        exec ('env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' build --force-rm --no-cache sanitize 2>&1', $output1, $return1);

        if ($return1 !== 0)
        {
            echo implode ("\n", $output1) ."\n";

            throw new Exception ("Sanitize service failed to BUILD");
        }

        echo 'COMMAND > env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' run --rm --no-deps sanitize'."\n";

        exec ('env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $cluster->host .'" '. self::DOCKER_COMPOSE .' run --rm --no-deps sanitize 2>&1', $output2, $return2);

        if ($return2 !== 0)
        {
            echo implode ("\n", $output2) ."\n";

            throw new Exception ("Sanitize service failed to RUN");
        }
    }
}