<?php

// validates that all security advisories are valid

if (!is_file($autoloader = __DIR__.'/vendor/autoload.php')) {
    echo "Dependencies are not installed, please run 'composer install' first!\n";
    exit(1);
}
require $autoloader;

use Composer\Config;
use Composer\IO\NullIO;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Util\HttpDownloader;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

final class Validate extends Command
{
    private Parser $parser;
    private array $composerRepositories = array();
    private Config $composerConfig;
    private HttpDownloader $httpDownloader;

    public function __construct()
    {
        parent::__construct('validate');

        $this->parser = new Parser();
        $this->composerConfig = new Config(false);
        $this->composerConfig->merge(array('config' => array('cache-dir' => sys_get_temp_dir().'/php-security-advisories')));
        $this->httpDownloader = new HttpDownloader(new NullIO(), $this->composerConfig);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $advisoryFilter = function (SplFileInfo $file) {
            if ($file->isFile() && __DIR__ === $file->getPath()) {
                return false; // We want to skip root files
            }

            if ($file->isDir()) {
                if (__DIR__.DIRECTORY_SEPARATOR.'vendor' === $file->getPathname()) {
                    return false; // We want to skip the vendor dir
                }

                $dirName = $file->getFilename();
                if ('.' === $dirName[0]) {
                    return false; // Exclude hidden folders (.git and IDE folders at the root)
                }
            }

            return true; // any other file gets checks and any other folder gets iterated
        };

        $isAcceptableVersionConstraint = function ($versionString) {
            return (bool) preg_match('/^
                [<>]=?
                (([1-9]\d*)|0)
                (\.(([1-9]\d*)|0))*
                (-(alpha|beta|rc|p(atch)?)[1-9]\d*)?
            $/x', $versionString);
        };

        $messages = array();

        /* @var $dir \Traversable<\SplFileInfo> */
        $dir = new \RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator(__DIR__), $advisoryFilter));

        $progress = new ProgressBar($io, count(iterator_to_array($dir)));
        $progress->start();

        foreach ($dir as $file) {
            if (!$file->isFile()) {
                $progress->advance();

                continue;
            }

            $path = str_replace(__DIR__.DIRECTORY_SEPARATOR, '', $file->getPathname());

            if ('yaml' !== $file->getExtension()) {
                $messages[$path][] = 'The file extension should be ".yaml".';
                continue;
            }

            try {
                $data = $this->parser->parse(file_get_contents($file));

                // validate first level keys
                if ($keys = array_diff(array_keys($data), array('reference', 'branches', 'title', 'link', 'cve', 'composer-repository'))) {
                    foreach ($keys as $key) {
                        $messages[$path][] = sprintf('Key "%s" is not supported.', $key);
                    }
                }

                // required keys
                foreach (array('reference', 'title', 'link', 'branches') as $key) {
                    if (!isset($data[$key])) {
                        $messages[$path][] = sprintf('Key "%s" is required.', $key);
                    }
                }

                if (isset($data['reference'])) {
                    if (0 !== strpos($data['reference'], 'composer://')) {
                        $messages[$path][] = 'Reference must start with "composer://"';
                    } else {
                        $composerPackage = substr($data['reference'], 11);

                        if (str_replace(DIRECTORY_SEPARATOR, '/', dirname($path)) !== $composerPackage) {
                            $messages[$path][] = 'Reference composer package must match the folder name';
                        }

                        if (!isset($data['composer-repository'])) {
                            $data['composer-repository'] = 'https://repo.packagist.org';
                        }

                        if (!empty($data['composer-repository'])) {
                            $composerRepository = $this->getComposerRepository($data['composer-repository']);

                            $found = false;
                            foreach ($composerRepository->search($composerPackage, RepositoryInterface::SEARCH_NAME) as $package) {
                                if ($package['name'] === $composerPackage) {
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $messages[$path][] = sprintf('Invalid composer package (not found in repository %s)', $data['composer-repository']);
                            }
                        }
                    }
                }

                if (isset($data['cve']) && !str_starts_with($data['cve'], 'CVE-')) {
                    $messages[$path][] = '"cve" must be a valid CVE number when provided.';
                }

                if (!isset($data['branches'])) {
                    $progress->advance();

                    continue; // Don't validate branches when not set to avoid notices
                }

                if (!is_array($data['branches'])) {
                    $messages[$path][] = '"branches" must be an array.';
                    $progress->advance();

                    continue;  // Don't validate branches when not set to avoid notices
                }

                $upperBoundWithoutLowerBound = null;

                foreach ($data['branches'] as $name => $branch) {
                    if (!preg_match('/^([\d.\-]+(\.x)?(-dev)?|master|main)$/', $name)) {
                        $messages[$path][] = sprintf('Invalid branch name "%s".', $name);
                    }

                    if ($keys = array_diff(array_keys($branch), array('time', 'versions'))) {
                        foreach ($keys as $key) {
                            $messages[$path][] = sprintf('Key "%s" is not supported for branch "%s".', $key, $name);
                        }
                    }

                    if (!array_key_exists('time', $branch)) {
                        $messages[$path][] = sprintf('Key "time" is required for branch "%s".', $name);
                    } elseif (isset($branch['time'])) {
                        $timestamp = is_int($branch['time']) ? $branch['time'] : strtotime($branch['time']);

                        if ($timestamp === false) {
                            $messages[$path][] = sprintf('"time" is invalid for branch "%s", given "%s".', $name, $branch['time']);
                        } elseif ($timestamp > time()) {
                            $messages[$path][] = sprintf('"time" cannot be in the future for branch "%s", given "%s".', $name, $branch['time']);
                        }
                    }

                    if (!isset($branch['versions'])) {
                        $messages[$path][] = sprintf('Key "versions" is required for branch "%s".', $name);
                    } elseif (!is_array($branch['versions'])) {
                        $messages[$path][] = sprintf('"versions" must be an array for branch "%s".', $name);
                    } else {
                        $upperBound = null;
                        $hasMin = false;
                        foreach ($branch['versions'] as $version) {
                            if (!$isAcceptableVersionConstraint($version)) {
                                $messages[$path][] = sprintf('Version constraint "%s" is not in an acceptable format.', $version);
                            }

                            if ('<' === substr($version, 0, 1)) {
                                if (null !== $upperBound) {
                                    $messages[$path][] = sprintf('"versions" cannot have multiple upper bounds for branch "%s".', $name);
                                    continue;
                                }

                                $upperBound = $version;
                                continue;
                            }
                            if ('>' === substr($version, 0, 1)) {
                                if ($hasMin) {
                                    $messages[$path][] = sprintf('"versions" cannot have multiple lower bounds for branch "%s".', $name);
                                    continue;
                                }

                                $hasMin = true;
                            }
                        }

                        if (null === $upperBound) {
                            $messages[$path][] = sprintf('"versions" must have an upper bound for branch "%s".', $name);
                        }

                        if (!$hasMin && null === $upperBoundWithoutLowerBound) {
                            $upperBoundWithoutLowerBound = $upperBound;
                        }

                        // Branches can omit the lower bound only if their upper bound is the same than for other branches without lower bound.
                        if (!$hasMin && $upperBoundWithoutLowerBound !== $upperBound) {
                            $messages[$path][] = sprintf('"versions" must have a lower bound for branch "%s" to avoid overlapping lower branches.', $name);
                        }
                    }
                }

                if (isset($data['cve']) && $data['cve'] !== $file->getBasename('.yaml')) {
                    $messages[$path][] = sprintf('The filename should be %s.yaml.', $data['cve']);
                }

            } catch (ParseException $e) {
                $messages[$path][] = sprintf('YAML is not valid (%s).', $e->getMessage());
            }

            $progress->advance();
        }

        $progress->finish();

        $io->newLine();

        if ($messages) {
            $io->error(sprintf('Found %s issue%s in %s file%s.',
                $issues = array_sum(array_map('count', $messages)),
                1 === $issues ? '' : 's', $files = count($messages),
                1 === $files ? '' : 's'
            ));
            $table = new Table($io);

            $table->setHeaders(array('File', 'Issues'));

            $files = array_keys($messages);
            $lastFile = array_pop($files);

            foreach ($messages as $file => $issues) {
                $table->addRow(array(
                    new TableCell($file, array('rowspan' => count($issues))),
                    array_shift($issues)
                ));

                foreach ($issues as $issue) {
                    $table->addRow(array($issue));
                }

                if ($file !== $lastFile) {
                    $table->addRow(new TableSeparator());
                }
            }

            $table->render();
        } else {
            $io->success('No issues found.');
        }

        return count($messages);
    }

    private function getComposerRepository($uri): ComposerRepository
    {
        if (!isset($this->composerRepositories[$uri])) {
            $repository = new ComposerRepository(
                array(
                    'url' => $uri,
                ),
                new NullIO(),
                $this->composerConfig,
                $this->httpDownloader
            );

            $this->composerRepositories[$uri] = $repository;
        }

        return $this->composerRepositories[$uri];
    }
}

final class Validator extends Application
{
    protected function getCommandName(InputInterface $input): ?string
    {
        return 'validate';
    }

    protected function getDefaultCommands(): array
    {
        $defaultCommands = parent::getDefaultCommands();

        $defaultCommands[] = new Validate();

        return $defaultCommands;
    }

    public function getDefinition(): InputDefinition
    {
        $inputDefinition = parent::getDefinition();

        $inputDefinition->setArguments();

        return $inputDefinition;
    }
}

$application = new Validator();

$application->run();
