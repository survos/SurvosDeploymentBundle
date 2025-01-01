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
use function Symfony\Component\String\u;

#[AsCommand('dokku:config', 'Configure a project for deployment on dukku')]
final class DokkuConfigCommand extends InvokableServiceCommand
{
    use RunsCommands;
    use RunsProcesses;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')] private string $projectDir,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    public function __invoke(
        IO $io,
        #[Argument(description: 'the repo prefix, e.g. barcode-demo')] ?string $name=null,
        #[Option('force', "actually run the dokku commands")] bool $force=false
    ): void {

        // should this be a maker bundle?
        $procfileContents = <<< END
web:  vendor/bin/heroku-php-nginx -C nginx.conf  -F fpm_custom.conf public/
END;
        file_put_contents($this->projectDir . '/Procfile', $procfileContents);
        file_put_contents($this->projectDir . '/fpm_custom.conf', <<<END
php_value[memory_limit] = 256M
php_value[post_max_size] = 100M
php_value[upload_max_filesize] = 100M
END
        );

        $composerData = json_decode(file_get_contents('composer.json'));
        assert($composerData->description, "run composer validate and composer normalize first!");
        if (!$name) {
            $name = u($composerData->name)->after('/')->toString();
        }

        $app = json_decode(file_get_contents($x = __DIR__ . './../../templates/app.json'));
        $app->name = $name;
        $app->description = $composerData->description;
        $app->repository = "https://github.com/" . $composerData->name;
        file_put_contents($this->projectDir . '/app.json', json_encode($app, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE));

        $conf = file_get_contents(__DIR__ . './../../templates/nginx.conf.twig');

        file_put_contents($this->projectDir . '/nginx.conf', $conf);

        $io->success('dokku:config files written.');

        $this->runCmd($cmd = 'git remote add dokku dokku@ssh.survos.com:' . $name);
        $this->runCmd($cmd = 'dokku apps:create');

        $this->runCmd($cmd = 'dokku config:set APP_ENV=prod --no-restart');
        $this->runCmd($cmd = 'dokku config:set REDIS=redis://dokku.survos.com:6379 --no-restart');
        $this->runCmd($cmd = 'bin/console secrets:generate-keys --env=prod');
        $this->runCmd($cmd = 'bin/console secrets:generate-keys');
//        $this->runCmd($cmd = 'bin/console secret:set APP_SECRET -r --env=prod');
//        $this->runCmd($cmd = 'bin/console secret:set APP_SECRET -r --env=dev');
        if ($force) {
            $secret = base64_encode(require "config/secrets/prod/prod.decrypt.private.php");
            $this->runCmd($cmd = "dokku config:set SYMFONY_DECRYPTION_SECRET=$secret APP_ENV=prod --no-restart");
        }


        $io->text("now run dokku config to see the variables. @todo: add secrets");
    }

    private function runCmd(string $cmd): void
    {
        $this->io()->writeln($cmd);
        if ($this->io()->getOption('force')) {
            try {
                $this->runProcess($cmd);
            } catch (\Exception $exception) {
                $this->io()->error($cmd);
            }
        }
    }
}
