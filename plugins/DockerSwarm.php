<?php

/*
Requisito:
apt update && apt install nfs-common -y

1. Cria os volumes:
docker volume create --driver local --opt type=nfs --opt o=nfsvers=4,addr=storage.sede.embrapa.br,rw --opt device=:/mnt/nfs/swarm.sede.embrapa.br/agroapp_portal_alpha_db agroapp_portal_alpha_db

2. Build
env $(cat .env.ci) docker-compose up --force-recreate --build --no-start

3. Push images to registry
env $(cat .env.ci) docker-compose push

4. Deploy
env $(cat .env && cat .env.ci) docker stack deploy -c .embrapa/swarm/deployment.yaml agroapp_portal_alpha

One shot services:

1. Get Services
env $(cat .env.cli) docker-compose config --services

2. Build:
env $(cat .env.cli) docker-compose build --force-rm --no-cache [service]

3. Push images to registry:
env $(cat .env.cli) docker-compose push

4. Execute specific service:
docker stack rm agroapp_portal_alpha_backup && docker stack deploy -c .embrapa/swarm/cli/backup.yaml --prune agroapp_portal_alpha_backup
*/

class DockerSwarm extends Orchestrator
{
    const DOCKER = '/usr/bin/docker';
    const DOCKER_COMPOSE = '/usr/bin/docker-compose';

    static private function getUpManagerNode ($cluster)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        exec ('type '. self::DOCKER, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker' command");

        unset ($return);

        $manager = NULL;

        if (isset ($cluster->nodes->manager) && is_array ($cluster->nodes->manager))
        {
            $nodes = $cluster->nodes->manager;

            array_unshift ($nodes, $cluster->host);

            shuffle ($nodes);
        }
        else $nodes = [ $cluster->host ];

        foreach ($nodes as $trash => $node)
            try
            {
                self::checkSSHConnection ($node);

                $manager = $node;

                break;
            }
            catch (Exception $e)
            {}

        if ($manager === NULL)
            throw new Exception ('All cluster manager nodes are unreachable');

        exec ('DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER ." info --format '{{.Swarm.ControlAvailable}}' 2>&1", $output, $return);

        if ($return !== 0)
        {
            echo "--- \n";
            echo implode ('', $output);
            echo "--- \n";

            throw new Exception ('Impossible to get node info to "'. $manager .'"');
        }

        if (!sizeof ($output) || $output[0] !== 'true')
            throw new Exception ('Node "'. $manager .'" is not a manager');

        return $manager;
    }

    static private function getAllNodes ($cluster)
    {
        $managers = isset ($cluster->nodes->manager) && is_array ($cluster->nodes->manager) ? $cluster->nodes->manager : [];
        $workers = isset ($cluster->nodes->worker) && is_array ($cluster->nodes->worker) ? $cluster->nodes->worker : [];

        return array_merge ([ $cluster->host ], $managers, $workers);
    }

    static public function validate ($path, $cluster, $ports)
    {
        $manager = self::getUpManagerNode ($cluster);

        return self::checkDockerSwarmFile ($path, $manager, $ports);
    }

    static public function deploy ($path, $cluster, $ports)
    {
        $manager = self::getUpManagerNode ($cluster);

        $valid = self::checkDockerSwarmFile ($path, $manager, $ports);

        if (!$valid)
            throw new Exception ('Invalid build (docker-compose.yaml) or deploy (.embrapa/swarm/deployment.yaml) files! Please, check configuration (volumes, ports, enviroment variables, etc)');

        chdir ($path);

        echo "INFO > Trying to execute backup service before deploy...\n";

        try
        {
            self::buildAndRunCliService ('backup', $manager, $path);
        }
        catch (Exception $e)
        {
            echo "WARNING > Backup service failed: ". $e->getMessage () ."! \n";
        }

        $name = basename ($path);

        echo "INFO > Creating a stack network named as '". $name ."' with Docker... \n";

        echo 'COMMAND > DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' network create -d overlay '. $name ."\n";

        exec ('DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' network create -d overlay '. $name .' 2>&1', $output, $return);

        if ($return !== 0)
            echo implode ("\n", $output) ."\n";

        unset ($return);
        unset ($output);

        echo "INFO > Building application with Docker Compose... \n";

        echo 'COMMAND > set -e && env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER_COMPOSE .' up --force-recreate --build --no-start && exit $?'."\n";

        passthru ('set -e && env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER_COMPOSE .' up --force-recreate --build --no-start && exit $? 2>&1', $return);

        if ($return !== 0)
            throw new Exception ('Error when buildings containers with Docker Compose');

        unset ($return);
        unset ($output);

        echo "INFO > Pushing images to registry... \n";

        echo 'COMMAND > env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER_COMPOSE .' push '."\n";

        exec ('env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER_COMPOSE .' push 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ('Error when pushing images with Docker Compose');
        }

        unset ($return);
        unset ($stacks);

        echo "INFO > Checking if stack ". $name ." is running...\n";

        echo 'COMMAND > DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack list --format "{{.Name}}"'."\n";

        exec ('DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack list --format "{{.Name}}" 2>&1', $stacks, $return);

        if ($return !== 0)
            throw new Exception ("Impossible to check deployed stacks");

        if (in_array ($name, $stacks))
        {
            unset ($return);
            unset ($output);

            echo "INFO > Stopping application with Docker Swarm... \n";

            echo 'COMMAND > env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack rm '. $name ."\n";

            exec ('env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack rm '. $name .' 2>&1', $output, $return);

            echo implode ("\n", $output) ."\n";

            if ($return !== 0)
                echo "ERROR > Error when stopping stack of containers with Docker Swarm! \n";

            sleep (10);
        }

        unset ($return);
        unset ($output);

        echo "INFO > Deploying aplication stack as '". $name ."' in cluster with Docker Swarm... \n";

        echo 'COMMAND > env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack deploy -c .embrapa/swarm/deployment.yaml '. $name ."\n";

        exec ('env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack deploy -c .embrapa/swarm/deployment.yaml '. $name .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ('Error when deploying stack of containers with Docker Swarm');
        }
    }

    static public function health ($cluster)
    {
        $nodes = self::getAllNodes ($cluster);

        $manager = self::getUpManagerNode ($cluster);

        echo 'COMMAND > DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack ls --format "{{.Name}}"'."\n";

        exec ('DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack ls --format "{{.Name}}"', $stacks, $return1);

        if ($return1 !== 0)
            throw new Exception ('Error to retrieve stacks from manager node "'. $manager .'"');

        $services = [];

        echo "INFO > Checking stacks health for: ". implode (", ", $stacks) ."... \n";

        foreach ($stacks as $trash => $stack)
        {
            $pieces = explode ('_', $stack);

            if (sizeof ($pieces) < 3) continue;

            $build = $pieces [0] .'/'. $pieces [1] .'@'. $pieces [2];

            unset ($return2);
            unset ($srvcs);

            echo 'COMMAND > DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack services --format "{{.Name}}|{{.Mode}}|{{.Replicas}}" '. $stack ."\n";

            exec ('DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack services --format "{{.Name}}|{{.Mode}}|{{.Replicas}}" '. $stack, $srvcs, $return2);

            if ($return2 !== 0 || !sizeof ($srvcs))
                continue;

            foreach ($srvcs as $trash => $s)
            {
                $p = explode ('|', $s);

                if (sizeof ($p) < 3) continue;

                $name = $p[0];
                $mode = $p[1];
                $replicas = $p[2];

                $aux = explode ('_', $name);

                $srvc = array_pop ($aux);

                if (in_array ($srvc, self::CLI_SERVICES)) continue;

                foreach ($nodes as $trash => $node)
                {
                    unset ($return3);
                    unset ($output);

                    echo 'COMMAND > DOCKER_HOST="ssh://root@'. $node .'" '. self::DOCKER .' ps -f "name='. $name .'" --format "{{.Names}}|{{.State}}|{{.Status}}|{{.Size}}|{{.CreatedAt}}|{{.RunningFor}}"'."\n";

                    exec ('DOCKER_HOST="ssh://root@'. $node .'" '. self::DOCKER .' ps -f "name='. $name .'" --format "{{.Names}}|{{.State}}|{{.Status}}|{{.Size}}|{{.CreatedAt}}|{{.RunningFor}}"', $output, $return3);

                    if ($return3 !== 0 || !sizeof ($output)) continue;

                    $line = explode ('|', $output[0]);

                    if (sizeof ($line) !== 6) continue;

                    $service = [];

                    $service['state'] = $line[1];
                    $service['status'] = $line[2] .' in '. $mode .' mode with '. $replicas .' replicas';

                    preg_match('/^[^\(]+\(([^\)]+)\)$/', $line[2], $out);

                    $service['healthy'] = sizeof ($out) == 2 ? $out[1] : 'undefined';

                    preg_match ('/^[^\(]+\(virtual ([^\)]+)\)$/', $line[3], $out);

                    $service['size'] = sizeof ($out) == 2 ? $out[1] : $line[3];

                    $service['created'] = (new DateTime($line[4]))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('j/n/y G:i');

                    $service['running'] = $line[5];

                    $services[$build][$srvc] = $service;

                    break;
                }
            }
        }

        return $services;
    }

    static public function checkDockerSwarmFile ($folder, $host, $ports)
    {
        echo "INFO > Validating Docker Compose and Swarm files... \n";

        chdir ($folder);

        if (!file_exists ('.embrapa/swarm/deployment.yaml'))
        {
            echo "ERROR > Missing '.embrapa/swarm/deployment.yaml' file in app folder! \n";

            return FALSE;
        }

        echo 'COMMAND > env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' -f .embrapa/swarm/deployment.yaml config --profiles'."\n";

        exec ('env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' -f .embrapa/swarm/deployment.yaml config --profiles 2>&1', $profiles, $return);

        if ($return !== 0 || sizeof ($profiles) > 0)
        {
            echo "ERROR > Profiles is not supported by Docker Swarm! Found: ". implode (', ', $profiles) .". Please, edit '.embrapa/swarm/deployment.yaml' to fix it. \n";

            return FALSE;
        }

        unset ($return);

        echo 'COMMAND > env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config'."\n";

        $out1 = tempnam ('.embrapa', '_');
        $log1 = tempnam ('.embrapa', '_');

        exec ('env $(cat .env.ci) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config > '. $out1 .' 2> '. $log1, $trash, $return);

        $output = file_exists ($log1) && is_readable ($log1) ? @file ($log1) : [];

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
            echo "ERROR > File 'docker-compose.yaml' is INVALID! Please, check configuration (volumes, ports, enviroment variables, etc). \n";

            return FALSE;
        }

        unset ($return);
        unset ($output);

        echo 'COMMAND > env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' -f .embrapa/swarm/deployment.yaml config'."\n";

        $out2 = tempnam ('.embrapa', '_');
        $log2 = tempnam ('.embrapa', '_');

        exec ('env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' -f .embrapa/swarm/deployment.yaml config > '. $out2 .' 2> '. $log2, $trash, $return);

        $output = file_exists ($log2) && is_readable ($log2) ? @file ($log2) : [];

        if (sizeof ($output))
        {
            $warning = TRUE;

            echo "--- \n";
            echo implode ('', $output);
            echo "--- \n";
        }

        if ($return !== 0)
        {
            echo "ERROR > File '.embrapa/swarm/deployment.yaml' is INVALID! Please, check configuration (volumes, ports, enviroment variables, etc). \n";

            return FALSE;
        }

        unset ($return);
        unset ($output);

        echo "INFO > Trying to validate declared VOLUMEs and PORTs... \n";

        if (!file_exists ($out1) || !is_readable ($out1))
        {
            echo "ERROR > Impossible to load interpolated 'docker-compose.yaml'! \n";

            return FALSE;
        }

        if (!file_exists ($out2) || !is_readable ($out2))
        {
            echo "ERROR > Impossible to load interpolates '.embrapa/swarm/deployment.yaml'! \n";

            return FALSE;
        }

        $cBuild = yaml_parse_file ($out1);
        $cDeply = yaml_parse_file ($out2);

        if ($cBuild === FALSE || !is_array ($cBuild))
        {
            echo "ERROR > Impossible to load properly interpolated 'docker-compose.yaml'! \n";

            return FALSE;
        }

        if ($cDeply === FALSE || !is_array ($cDeply))
        {
            echo "ERROR > Impossible to load properly interpolated '.embrapa/swarm/deployment.yaml'! \n";

            return FALSE;
        }

        $prefix = basename ($folder);

        $volumes = [];

        if (array_key_exists ('volumes', $cDeply) && is_array ($cDeply ['volumes']))
        {
            echo "INFO > Validating declared VOLUMEs in '.embrapa/swarm/deployment.yaml'... \n";

            foreach ($cDeply ['volumes'] as $name => $volume)
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

        if (array_key_exists ('volumes', $cBuild) && is_array ($cBuild ['volumes']))
        {
            echo "INFO > Validating declared VOLUMEs in 'docker-compose.yaml'... \n";

            foreach ($cBuild ['volumes'] as $name => $volume)
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

        if (!array_key_exists ('networks', $cBuild) || !is_array ($cBuild ['networks']) || sizeof ($cBuild ['networks']) !== 1 ||
            !array_key_exists ('external', $cBuild ['networks'][array_key_first ($cBuild ['networks'])]) || !(bool) $cBuild ['networks'][array_key_first ($cBuild ['networks'])]['external'] ||
            !array_key_exists ('name', $cBuild ['networks'][array_key_first ($cBuild ['networks'])]) || trim ($cBuild ['networks'][array_key_first ($cBuild ['networks'])]['name']) !== $prefix)
        {
            echo "ERROR > Is needed to exists ONE, and only one, external network to entire stack in 'docker-compose.yaml'. Must be named '". $prefix ."'. See https://docs.docker.com/compose/networking/ for more info. \n";

            return FALSE;
        }

        if (!array_key_exists ('networks', $cDeply) || !is_array ($cDeply ['networks']) || sizeof ($cDeply ['networks']) !== 1 ||
            !array_key_exists ('external', $cDeply ['networks'][array_key_first ($cDeply ['networks'])]) || !(bool) $cDeply ['networks'][array_key_first ($cDeply ['networks'])]['external'] ||
            !array_key_exists ('name', $cDeply ['networks'][array_key_first ($cDeply ['networks'])]) || trim ($cDeply ['networks'][array_key_first ($cDeply ['networks'])]['name']) !== $prefix)
        {
            echo "ERROR > Is needed to exists ONE, and only one, external network to entire stack in '.embrapa/swarm/deployment.yaml'. Must be named '". $prefix ."'. See https://docs.docker.com/compose/networking/ for more info. \n";

            return FALSE;
        }

        $network = array_key_first ($cDeply ['networks']);

        echo "INFO > External stack network in use is valid! \n";

        if (!array_key_exists ('services', $cBuild) || !is_array ($cBuild ['services']) ||
            !array_key_exists ('services', $cDeply) || !is_array ($cDeply ['services']))
        {
            echo "ERROR > Impossible to load services from config files! Check 'docker-compose.yaml' and '.embrapa/swarm/deployment.yaml'. \n";

            return FALSE;
        }

        $invalidPorts = [];

        foreach ($cDeply ['services'] as $name => $service)
        {
            if (!array_key_exists ($name, $cBuild ['services']))
            {
                echo "ERROR > Service '". $name ."', present in '.embrapa/swarm/deployment.yaml', is NOT declared in 'docker-compose.yaml'! \n";

                return FALSE;
            }

            if (in_array ($name, self::CLI_SERVICES))
            {
                echo "ERROR > Service '". $name ."', present in '.embrapa/swarm/deployment.yaml', is for CLI only! \n";

                return FALSE;
            }

            if (!isset ($service ['image']) || !isset ($cBuild ['services'][$name]['image']))
            {
                echo "ERROR > Service '". $name ."' has no image! Check 'docker-compose.yaml' and '.embrapa/swarm/deployment.yaml'. \n";

                return FALSE;
            }

            if (trim ($service ['image']) != trim ($cBuild ['services'][$name]['image']))
            {
                echo "ERROR > Image for service '". $name ."' in 'docker-compose.yaml' is NOT equal in '.embrapa/swarm/deployment.yaml'. \n";

                return FALSE;
            }

            if (!isset ($service ['networks']) || !is_array ($service ['networks']) ||
                sizeof ($service ['networks']) !== 1 || !array_key_exists ($network, $service ['networks']))
            {
                echo "ERROR > Service '". $name ."' not refering '". $prefix ."' external network (nicknamed '". $network ."') ! Check '.embrapa/swarm/deployment.yaml'. \n";

                return FALSE;
            }

            if (isset ($service ['deploy']['mode']) && trim ($service ['deploy']['mode']) != 'global')
            {
                echo "ERROR > In this time, is not accepted more than one instance per cluster node! Remove deploy mode or change it to 'global' at service '". $name ."' in '.embrapa/swarm/deployment.yaml'. \n";

                return FALSE;
            }

            if (!isset ($service ['deploy']['restart_policy']['condition']) || trim ($service ['deploy']['restart_policy']['condition']) !== 'on-failure')
            {
                echo "ERROR > Change 'restart_policy' of service '". $name ."' in '.embrapa/swarm/deployment.yaml' to 'on-failure' condition! \n";

                return FALSE;
            }

            if (isset ($service ['deploy']['update_config']) || isset ($service ['deploy']['replicas']) || isset ($service ['deploy']['resources']['reservations']))
            {
                echo "ERROR > In this time, is not accepted to use of 'update_config', 'replicas' or 'resources.reservations' attributes! Remove it at service '". $name ."' in '.embrapa/swarm/deployment.yaml'. \n";

                return FALSE;
            }

            if ($ports !== FALSE && isset ($service ['ports']))
                foreach ($service ['ports'] as $trash => $port)
                {
                    if (!isset ($port ['published']) || !(int) $port ['published'])
                    {
                        echo "ERROR > Service '". $name ."' trying to expose a randomic port. All ports in services must be explicit! If you are not going to expose this service, remove it from '.embrapa/swarm/deployment.yaml'. \n";

                        return FALSE;
                    }

                    if (!in_array ((int) $port ['published'], $ports))
                        $invalidPorts [] = $port ['published'];
                }

            if (isset ($service ['volumes']))
                foreach ($service ['volumes'] as $trash => $volume)
                    if (!in_array ($volume ['source'], $volumes))
                    {
                        echo "ERROR > Volume named '". $volume ['source'] ."' used by service '". $name ."' is not declared as external in '.embrapa/swarm/deployment.yaml'. \n";

                        return FALSE;
                    }
        }

        if (sizeof ($invalidPorts))
        {
            echo "ERROR > Trying to use PORTs that is not defined at application configuration: ". implode (", ", $invalidPorts) ."! \n";

            return FALSE;
        }

        echo "INFO > All published PORTs are valid! \n";

        echo 'COMMAND > env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config --services'."\n";

        exec ('env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config --services 2>&1', $services, $return);

        $cli = self::CLI_SERVICES;

        foreach ($cli as $index => $service)
            if (in_array ($service, $services) && file_exists ('.embrapa/swarm/cli/'. $service .'.yaml'))
                unset ($cli [$index]);

        if (sizeof ($cli))
        {
            echo "WARNING > Some CLI recomended services are NOT FOUND at 'docker-compose.yaml' or '.embrapa/swarm/cli/': ". implode (", ", $cli) ."! Please, check configuration. \n";

            $warning = TRUE;
        }

        if ($warning)
            echo "WARNING > File 'docker-compose.yaml' (for BUILD) and '.embrapa/swarm/deployment.yaml' (for DEPLOY) are VALIDs, but has some alerts to fix! \n";
        else
            echo "SUCCESS > File 'docker-compose.yaml' (for BUILD) and '.embrapa/swarm/deployment.yaml' (for DEPLOY) are VALIDs! \n";

        return TRUE;
    }

    static public function undeploy ($path, $cluster)
    {
        $manager = self::getUpManagerNode ($cluster);

        chdir ($path);

        $name = basename ($path);

        echo "INFO > Checking if stack ". $name ." is deployed...\n";

        echo 'COMMAND > DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack list --format "{{.Name}}"'."\n";

        exec ('DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack list --format "{{.Name}}" 2>&1', $stacks, $return);

        if ($return !== 0)
            throw new Exception ("Impossible to check deployed stacks");

        if (!in_array ($name, $stacks))
            throw new Exception ("Stack '". $name ."' is not deployed");

        echo "INFO > Stopping application with Docker Swarm... \n";

        echo 'COMMAND > env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack rm '. $name ."\n";

        exec ('env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack rm '. $name .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ('Error when stopping stack of containers with Docker Swarm');
        }
    }

    static public function restart ($path, $cluster)
    {
        $manager = self::getUpManagerNode ($cluster);

        chdir ($path);

        $name = basename ($path);

        echo "INFO > Checking if stack ". $name ." is deployed...\n";

        echo 'COMMAND > DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack list --format "{{.Name}}"'."\n";

        exec ('DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack list --format "{{.Name}}" 2>&1', $stacks, $return);

        if ($return !== 0)
            throw new Exception ("Impossible to check deployed stacks");

        if (in_array ($name, $stacks))
        {
            echo "INFO > Stopping application with Docker Swarm... \n";

            echo 'COMMAND > env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack rm '. $name ."\n";

            exec ('env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack rm '. $name .' 2>&1', $output, $return);

            echo implode ("\n", $output) ."\n";

            if ($return !== 0)
                echo "ERROR > Error when stopping stack of containers with Docker Swarm! \n";

            sleep (10);
        }

        unset ($return);
        unset ($output);

        echo "INFO > Creating a stack network named as '". $name ."' with Docker Swarm... \n";

        echo 'COMMAND > DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' network create -d overlay '. $name ."\n";

        exec ('DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' network create -d overlay '. $name .' 2>&1', $output, $return);

        if ($return !== 0)
            echo implode ("\n", $output) ."\n";

        unset ($return);
        unset ($output);

        echo "INFO > Deploying aplication stack as '". $name ."' in cluster with Docker Swarm... \n";

        echo 'COMMAND > env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack deploy -c .embrapa/swarm/deployment.yaml '. $name ."\n";

        exec ('env $(cat .env && cat .env.ci) DOCKER_HOST="ssh://root@'. $manager .'" '. self::DOCKER .' stack deploy -c .embrapa/swarm/deployment.yaml '. $name .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ('Error when deploying stack of containers with Docker Swarm');
        }
    }

    static public function backup ($path, $cluster)
    {
        $manager = self::getUpManagerNode ($cluster);

        self::buildAndRunCliService ('backup', $manager, $path);
    }

    static public function sanitize ($path, $cluster)
    {
        $manager = self::getUpManagerNode ($cluster);

        self::buildAndRunCliService ('sanitize', $manager, $path);
    }

    static private function buildAndRunCliService ($service, $host, $path)
    {
        if (!in_array ($service, self::CLI_SERVICES))
            throw new Exception ("'". $service ."' is not a CLI service");

        $valid = self::checkDockerSwarmFile ($path, $host, FALSE);

        if (!$valid)
            throw new Exception ('Invalid build (docker-compose.yaml) or deploy (.embrapa/swarm/deployment.yaml) files! Please, check configuration (volumes, ports, enviroment variables, etc)');

        chdir ($path);

        $prefix = basename ($path);

        if (!file_exists ('.embrapa/swarm/cli/'. $service .'.yaml'))
            throw new Exception ("File '.embrapa/swarm/cli/". $service .".yaml' not found");

        echo "INFO > Checking if stack ". $prefix ." is deployed...\n";

        echo 'COMMAND > DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER .' stack list --format "{{.Name}}"'."\n";

        exec ('DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER .' stack list --format "{{.Name}}" 2>&1', $stacks, $return);

        if ($return !== 0)
            throw new Exception ("Impossible to check deployed stacks");

        if (!in_array ($prefix, $stacks))
            throw new Exception ("Stack '". $prefix ."' is not deployed yet");

        echo "INFO > Trying to get configured CLI services... \n";

        echo 'COMMAND > env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config --services'."\n";

        exec ('env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' config --services 2>&1', $services, $return);

        if ($return !== 0)
            throw new Exception ("Impossible to get services in 'docker-compose.yaml'");

        if (!in_array ($service, $services))
            throw new Exception ("Service '". $service ."' not configured in 'docker-compose.yaml'");

        $out = tempnam ('.embrapa', '_');
        $log = tempnam ('.embrapa', '_');

        echo 'COMMAND > env $(cat .env && cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' -f .embrapa/swarm/cli/'. $service .'.yaml config > '. $out .' 2> '. $log ."\n";

        exec ('env $(cat .env && cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' -f .embrapa/swarm/cli/'. $service .'.yaml config > '. $out .' 2> '. $log, $trash, $return);

        if (!file_exists ($out) || !is_readable ($out))
            throw new Exception ("Impossible to load interpolates '.embrapa/swarm/cli/'. $service .'.yaml'");

        $config = yaml_parse_file ($out);

        if ($config === FALSE || !is_array ($config))
            throw new Exception ("Impossible to load properly interpolated 'docker-compose.yaml'");

        if (!isset ($config ['services']) || !is_array ($config ['services']) || !sizeof ($config ['services']))
            throw new Exception ("Has no one configured service at '.embrapa/swarm/cli/'. $service .'.yaml'");

        $volumes = [];

        if (array_key_exists ('volumes', $config) && is_array ($config ['volumes']))
        {
            foreach ($config ['volumes'] as $name => $volume)
            {
                if (!array_key_exists ('external', $volume) || !(bool) $volume ['external'])
                    throw new Exception ("Volume '". $name ."' is NOT 'external'! See https://docs.docker.com/compose/compose-file/#external-1 for more info");

                $aux = explode ('_', $volume ['name']);

                array_pop ($aux);

                if (implode ('_', $aux) != $prefix)
                    throw new Exception ("Volume '". $name ."' is pointing to '". $volume ['name'] ."'! All mounted drivers needed to inside of application context");

                $volumes [] = $name;
            }

            echo "INFO > All external VOLUMEs declared are valid! \n";
        }

        if (!array_key_exists ('networks', $config) || !is_array ($config ['networks']) || sizeof ($config ['networks']) !== 1 ||
            !array_key_exists ('external', $config ['networks'][array_key_first ($config ['networks'])]) || !(bool) $config ['networks'][array_key_first ($config ['networks'])]['external'] ||
            !array_key_exists ('name', $config ['networks'][array_key_first ($config ['networks'])]) || trim ($config ['networks'][array_key_first ($config ['networks'])]['name']) !== $prefix)
            throw new Exception ("Is needed to exists ONE, and only one, external network to entire stack. Must be named '". $prefix ."'. See https://docs.docker.com/compose/networking/ for more info");

        $network = array_key_first ($config ['networks']);

        foreach ($config ['services'] as $name => $s)
        {
            if (!isset ($s ['image']))
                throw new Exception ("Service '". $name ."' has no image! Check '.embrapa/swarm/cli/'. $service .'.yaml'");

            if (!isset ($s ['networks']) || !is_array ($s ['networks']) ||
                sizeof ($s ['networks']) !== 1 || !array_key_exists ($network, $s ['networks']))
                throw new Exception ("Service '". $name ."' not refering '". $prefix ."' external network (nicknamed '". $network ."') ! Check '.embrapa/swarm/cli/'. $service .'.yaml'");

            if (isset ($s ['deploy']['mode']))
                throw new Exception ("CLI services cannot be replicated! Remove deploy mode at service '". $name ."' in '.embrapa/swarm/cli/'. $service .'.yaml'");

            if (!isset ($s ['deploy']['restart_policy']['condition']) || trim ($s ['deploy']['restart_policy']['condition']) !== 'none')
                throw new Exception ("CLI services cannot be restarted! Set restart policy as 'none' at service '". $name ."' in '.embrapa/swarm/cli/'. $service .'.yaml'");

            if (isset ($s ['deploy']['update_config']) || isset ($s ['deploy']['replicas']) || isset ($s ['deploy']['resources']['reservations']))
                throw new Exception ("CLI services cannot use 'update_config', 'replicas' or 'resources.reservations' attributes! Remove it at service '". $name ."' in '.embrapa/swarm/cli/'. $service .'.yaml'");

            if (isset ($s ['ports']))
                throw new Exception ("You cannot declare ports to be expose in a CLI service! Check '.embrapa/swarm/cli/'. $service .'.yaml'");

            if (isset ($s ['volumes']))
                foreach ($s ['volumes'] as $trash => $volume)
                    if (!in_array ($volume ['source'], $volumes))
                        throw new Exception ("Volume named '". $volume ['source'] ."' used by service '". $name ."' is not declared as external in '.embrapa/swarm/cli/'. $service .'.yaml'");
        }

        echo 'COMMAND > env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' build --force-rm --no-cache '. $service ."\n";

        exec ('env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' build --force-rm --no-cache '. $service .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ("Service '". $service ."' failed to BUILD");
        }

        echo 'COMMAND > env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' push '. $service ."\n";

        exec ('env $(cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER_COMPOSE .' push '. $service .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ("Impossible to push images to registry");
        }

        $name = $prefix .'_'. $service;

        echo 'COMMAND > DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER .' stack rm '. $name ."\n";

        exec ('DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER .' stack rm '. $name .' 2>&1', $output1, $return1);

        if ($return1 !== 0)
            echo implode ("\n", $output1) ."\n";

        echo 'COMMAND > env $(cat .env && cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER .' stack deploy -c .embrapa/swarm/cli/'. $service .'.yaml --prune '. $name ."\n";

        exec ('env $(cat .env && cat .env.cli) DOCKER_HOST="ssh://root@'. $host .'" '. self::DOCKER .' stack deploy -c .embrapa/swarm/cli/'. $service .'.yaml --prune '. $name .' 2>&1', $output2, $return2);

        if ($return2 !== 0)
        {
            echo implode ("\n", $output2) ."\n";

            throw new Exception ("Service '". $service ."' failed to DEPLOY");
        }

        echo "SUCCESS > Service '". $service ."' deployed successfully! \n";
    }
}