<?php

namespace Survos\DeploymentBundle\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\ConfigureWithAttributes;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;
use Zenstruck\Console\Attribute\Option;

#[AsCommand('dokku:config', 'Configure a project for deployment on dukku')]
final class DokkuConfigCommand extends InvokableServiceCommand
{
    use ConfigureWithAttributes;
    use RunsCommands;
    use RunsProcesses;

    public function __construct(
        private KernelInterface $kernel,
        private Environment $twig,
        #[Autowire(param: 'kernel.project_dir')] private string $projectDir,
        string $name = null
    ) {
//        $this->application = new Application($this->kernel);
        parent::__construct($name);
    }

    public function __invoke(
        IO $io,
        #[Argument(description: 'the repo prefix, e.g. barcode-demo')] string $name,
    ): void {

        // should this be a maker bundle?
        $procfileContents = <<< END
web:  vendor/bin/heroku-php-nginx -C nginx.conf  -F fpm_custom.conf public/
release: bin/console importmap:install && bin/console asset-map:compile
END;
        file_put_contents($this->projectDir . '/Procfile', $procfileContents);
        file_put_contents($this->projectDir . '/fpm_custom.conf', <<<END
php_value[memory_limit] = 256M
php_value[post_max_size] = 100M
php_value[upload_max_filesize] = 100M
END);

// we don't really need twig,
        $conf = file_get_contents(__DIR__ . './../../templates/nginx.conf.twig');
        file_put_contents($this->projectDir . '/nginx.conf', $conf);


        $io->success('dokku:config success.');

        $this->runProcess('git remote add dokku dokku@ssh.survos.com:' . $name);

        $io->text("now run dokku config to see the variables. @todo: add secrets");
    }
}
