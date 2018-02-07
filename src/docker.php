<?php

namespace App;

use Docker\API\Model\ContainerInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

require __DIR__.'/../vendor/autoload.php';

$docker = new \Docker\Docker();

$containers = $docker->getContainerManager()->findAll();

$groups = [

];

function detectLabels(ContainerInfo $container) {
    $labels = [];
    $fullName = $container->getNames()[0];

    $keywords = [
        'proxy' => [],
        'phpmyadmin' => ['web'],
        'database' => [],
        'mysql' => ['database'],
        'mock' => [],
        'api' => ['web', 'code'],
        'marketplace' => ['web', 'code'],
    ];

    foreach ($keywords as $keyword => $alias) {
        if (strpos($fullName, $keyword) !== false) {
            $labels[] = $keyword;
            if (!empty($alias)) {
                array_push($labels, ...$alias);
            }
        }
    }

    return $labels;
}

function detectPorts(ContainerInfo $container)
{
    $exposedPorts = [];

    $ports = $container->getPorts();
    foreach ($ports as $port) {
        $exposedPorts[$port->getPrivatePort()] = $port->getPublicPort();
    }

    return $exposedPorts;
}

foreach ($containers as $container) {

    $details = $docker->getContainerManager()->find($container->getId());
    $fullName = $container->getNames()[0];
    list ($groupName, $name) = explode('_', $fullName);

    $rawEnvs = $details->getConfig()->getEnv();
    $rawEnvs = str_replace(['[', ']'], '', $rawEnvs);
    $env = [];
    foreach ($rawEnvs as $rawEnv) {
        if (strpos($rawEnv, '=') !== false) {
            list($envName, $envValue) = explode('=', $rawEnv);
            $env[$envName] = $envValue;
        }

    }

    $item = [
        'name' => $name,
        'fullname' => $fullName,
        'host' => $env['VIRTUAL_HOST'] ?? 'localhost',
        'port' => $env['VIRTUAL_PORT'] ?? detectPorts($container),
        'external_ports' => $env['VIRTUAL_PORT'] ?? detectPorts($container),
        'labels' => detectLabels($container),
        'env' => $env,
    ];

    $codePath = null;

    if (in_array('code', $item['labels'])) {
        $mounts = $container->getMounts();
        foreach ($mounts as $mount) {
            $source = $mount->getSource();
            if (strpos($source, '.') === false) {
                $codePath = $source;
            }
        }
    }

    $item['code_path'] = $codePath;

    $groups[$groupName][] = $item;
}

dump($groups);

function findByRole($containers, $role) {
    return array_filter($containers, function($container) use($role) {
        return in_array($role, $container['labels']);
    });
}

$output = new SymfonyStyle(new ArrayInput([]), new ConsoleOutput());

foreach($groups as $groupName => $group) {
    $output->title(sprintf('Project '.$groupName));

    // Les proxy

    $output->section('Proxy');

    $proxy = findByRole($group, 'proxy') ?? null;

    if (!$proxy) {
        $output->warning('Introuvable');
    } else {
        $output->success('OK');
    }

    $output->section('Applications web');

    // Les serveurs web
    $containers = findByRole($group, 'web');

    $tables = [];
    foreach ($containers as $container) {

        $url = sprintf(
            '%s://%s/',
            (($container['port'] == null) ? 'http' : 'https'),
            $container['host']
        );

        $tables[] = [
            $container['name'],

            $url,
            $container['code_path'],
        ];

        if (!gethostbynamel($container['host'])) {
            $output->warning('bad resolution hostname '.$container['host']);
        }
    }
    $output->table(['Nom', 'url', 'Chemin code source'], $tables);


    $output->section('Base de données');

    $containers = findByRole($group, 'database');
    $tables = [];
    foreach ($containers as $container) {

        $externalAccess = ['phpmyadmin'];
        if ($container['port'][3306]) {
            $externalAccess[] = $container['port'][3306];
        }
        $externalAccess = implode(', ', $externalAccess);

        $tables[] = [
            $container['name'],
            $externalAccess,
            'root',
            $container['env']['MYSQL_ROOT_PASSWORD'] ?? null,
            $container['env']['MYSQL_DATABASE']
        ];
    }
    $output->table(['Nom', 'Accès extérieur', 'username', 'password', 'database'], $tables);
}