<?php

declare(strict_types=1);

namespace Roave\ComposerGpgVerify;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Roave\ComposerGpgVerify\Package\Git\GitSignatureCheck;
use Roave\ComposerGpgVerify\Package\GitPackage;
use Roave\ComposerGpgVerify\Package\PackageVerification;
use Roave\ComposerGpgVerify\Package\UnknownPackageFormat;

final class Verify implements PluginInterface, EventSubscriberInterface
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents() : array
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'verify',
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @codeCoverageIgnore
     */
    public function activate(Composer $composer, IOInterface $io) : void
    {
        // Nothing to do here, as all features are provided through event listeners
    }

    /**
     * @param Event $composerEvent
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public static function verify(Event $composerEvent) : void
    {
        $originalLanguage = getenv('LANGUAGE');
        $composer         = $composerEvent->getComposer();
        $config           = $composer->getConfig();

        self::assertSourceInstallation($config);

        // prevent output changes caused by locale settings on the system where this script is running
        putenv(sprintf('LANGUAGE=%s', 'en_US'));

        $installationManager = $composer->getInstallationManager();
        /* @var $checkedPackages PackageVerification[] */
        $checkedPackages     = array_map(
            function (PackageInterface $package) use ($installationManager) : PackageVerification {
                return self::verifyPackage($installationManager, $package);
            },
            $composer->getRepositoryManager()->getLocalRepository()->getPackages()
        );

        putenv(sprintf('LANGUAGE=%s', (string) $originalLanguage));

        $escapes = array_filter(
            $checkedPackages,
            function (PackageVerification $verification) : bool {
                return ! $verification->isVerified();
            }
        );

        if (! $escapes) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'The following packages need to be signed and verified, or added to exclusions: %s%s',
            "\n",
            implode(
                "\n\n",
                array_map(
                    function (PackageVerification $failedVerification) : string {
                        return $failedVerification->printReason();
                    },
                    $escapes
                )
            )
        ));
    }

    private static function verifyPackage(
        InstallationManager $installationManager,
        PackageInterface $package
    ) : PackageVerification {
        $gitDirectory = $installationManager->getInstallPath($package) . '/.git';

        if (! is_dir($gitDirectory)) {
            return UnknownPackageFormat::fromNonGitPackage($package);
        }

        // because PHP is a moronic language, by-ref is everywhere in the standard library
        $output  = [];
        /* @var $checks GitSignatureCheck[] */
        $checks  = [];
        $command = sprintf(
            'git --git-dir %s verify-commit --verbose HEAD 2>&1',
            escapeshellarg($gitDirectory)
        );

        exec($command, $output, $exitCode);

        $checks[] = GitSignatureCheck::fromGitCommitCheck($package, $command, $exitCode, implode("\n", $output));

        exec(
            sprintf(
                'git --git-dir %s tag --points-at HEAD 2>&1',
                escapeshellarg($gitDirectory)
            ),
            $tags
        );

        // go through all found tags, see if at least one is signed
        foreach (array_filter($tags) as $tag) {
            $command = sprintf(
                'git --git-dir %s tag -v %s 2>&1',
                escapeshellarg($gitDirectory),
                escapeshellarg($tag)
            );

            exec($command, $tagSignatureOutput, $exitCode);

            $checks[] = GitSignatureCheck::fromGitTagCheck(
                $package,
                $command,
                $exitCode,
                implode("\n", $tagSignatureOutput)
            );
        }

        return GitPackage::fromPackageAndSignatureChecks($package, ...$checks);
    }

    /**
     * @param Config $config
     *
     *
     * @throws \LogicException
     * @throws \RuntimeException
     */
    private static function assertSourceInstallation(Config $config) : void
    {
        $preferredInstall = $config->get('preferred-install');

        if ('source' !== $preferredInstall) {
            throw new \LogicException(sprintf(
                'Expected installation "preferred-install" to be "source", found "%s" instead',
                (string) $preferredInstall
            ));
        }
    }
}
